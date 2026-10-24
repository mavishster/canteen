<?php

use App\Jobs\RecordQueueHeartbeat;
use App\Support\Ops\Dumper;
use App\Support\Ops\Heartbeat;
use App\Support\Ops\OperationsStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

function opLevels(bool $production): array
{
    return collect(OperationsStatus::report($production))->mapWithKeys(fn ($r) => [$r['area'] => $r['level']])->all();
}

function opFakeDump(string $content = "-- fake dump\n-- Dump completed\n"): void
{
    app()->instance('canteen.dumper', new class($content) implements Dumper
    {
        public function __construct(private string $content) {}

        public function dump(string $gzPath): void
        {
            $gz = gzopen($gzPath, 'wb9');
            gzwrite($gz, str_repeat($this->content, 3));
            gzclose($gz);
        }
    });
}

function opBackupDir(): string
{
    $dir = sys_get_temp_dir() . '/canteen-bk-' . uniqid();
    mkdir($dir, 0750, true);
    config(['ops.backup.path' => $dir, 'ops.backup.disk' => null]);

    return $dir;
}

// ----------------------------------------------------------------- heartbeats

it('keeps one row per heartbeat and updates it', function () {
    Heartbeat::beat('scheduler');
    $this->travel(5)->minutes();
    Heartbeat::beat('scheduler', 'again');

    expect(\Illuminate\Support\Facades\DB::table('heartbeats')->count())->toBe(1)
        ->and(Heartbeat::last('scheduler')['note'])->toBe('again')
        ->and(Heartbeat::last('scheduler')['seconds'])->toBe(0)
        ->and(Heartbeat::last('nothing'))->toBeNull();
});

it('writes the queue heartbeat when the job is processed', function () {
    expect(Heartbeat::last('queue'))->toBeNull();

    RecordQueueHeartbeat::dispatchSync();

    expect(Heartbeat::last('queue'))->not->toBeNull();
});

it('registers the scheduler and queue heartbeats and the daily backup', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

    $descriptions = $events->map(fn ($e) => $e->description . ' ' . $e->command . ' ' . $e->expression)->implode("\n");

    expect($descriptions)->toContain('heartbeat-scheduler')->toContain('canteen:backup')->toContain('0 2 * * *');
});

// ------------------------------------------------------------- the verdicts

it('only warns about a scheduler that has never been seen, even on a production server', function () {
    config(['queue.default' => 'database']);

    $levels = opLevels(true);

    expect($levels['Scheduler'])->toBe('WARN')->and($levels['Queue worker'])->toBe('WARN')->and($levels['Backup'])->toBe('WARN');
});

it('is happy with fresh heartbeats and fails stale ones on a production server only', function () {
    config(['queue.default' => 'database']);
    Heartbeat::beat('scheduler');
    Heartbeat::beat('queue');
    Heartbeat::beat('backup', 'x.sql.gz, 1 MB');

    expect(opLevels(true))->toMatchArray(['Scheduler' => 'OK', 'Queue worker' => 'OK', 'Backup' => 'OK']);

    $this->travel(15)->minutes();
    expect(opLevels(true)['Scheduler'])->toBe('FAIL')->and(opLevels(true)['Queue worker'])->toBe('FAIL')
        ->and(opLevels(false)['Scheduler'])->toBe('WARN')->and(opLevels(false)['Queue worker'])->toBe('WARN')
        ->and(opLevels(true)['Backup'])->toBe('OK');

    $this->travel(30)->hours();
    expect(opLevels(true)['Backup'])->toBe('FAIL')->and(opLevels(false)['Backup'])->toBe('WARN');
});

it('does not look for a queue worker when the queue is sync', function () {
    config(['queue.default' => 'sync']);

    expect(opLevels(true))->not->toHaveKey('Queue worker');
});

it('reports a missing off-site copy', function () {
    config(['ops.backup.disk' => 'offsite']);

    expect(opLevels(true)['Backup copy'])->toBe('WARN');

    Heartbeat::beat('backup_offsite');

    expect(opLevels(true)['Backup copy'])->toBe('OK');
});

// ------------------------------------------------------------------- the backup

it('writes a complete backup, records it, and leaves no half-written file', function () {
    $dir = opBackupDir();
    opFakeDump();

    $this->artisan('canteen:backup')->assertExitCode(0)->expectsOutputToContain('Backup written');

    $files = glob("{$dir}/*");

    expect($files)->toHaveCount(1)
        ->and($files[0])->toMatch('~canteen-\d{8}-\d{6}\.sql\.gz$~')
        ->and(gzdecode((string) file_get_contents($files[0])))->toContain('Dump completed')
        ->and(Heartbeat::last('backup')['note'])->toContain('canteen-');

    File::deleteDirectory($dir);
});

it('fails clearly when the dump fails, and records nothing', function () {
    $dir = opBackupDir();
    app()->instance('canteen.dumper', new class implements Dumper
    {
        public function dump(string $gzPath): void
        {
            file_put_contents($gzPath, 'half');

            throw new RuntimeException('mysqldump failed: access denied');
        }
    });

    $this->artisan('canteen:backup')->assertExitCode(1)->expectsOutputToContain('access denied');

    expect(glob("{$dir}/*"))->toBe([])->and(Heartbeat::last('backup'))->toBeNull();

    File::deleteDirectory($dir);
});

it('fails when the backup file is empty', function () {
    $dir = opBackupDir();
    app()->instance('canteen.dumper', new class implements Dumper
    {
        public function dump(string $gzPath): void
        {
            file_put_contents($gzPath, '');
        }
    });

    $this->artisan('canteen:backup')->assertExitCode(1)->expectsOutputToContain('empty');

    expect(Heartbeat::last('backup'))->toBeNull();

    File::deleteDirectory($dir);
});

it('keeps the newest backups and the young ones, and removes the old rest', function () {
    $dir = opBackupDir();
    config(['ops.backup.keep_days' => 30, 'ops.backup.keep_min' => 7]);

    foreach (range(1, 10) as $i) {
        $file = sprintf('%s/canteen-202601%02d-020000.sql.gz', $dir, $i);
        file_put_contents($file, 'old');
        touch($file, time() - (60 - $i) * 86400);   // 59 ... 50 days old
    }

    $young = "{$dir}/canteen-20269999-020000.sql.gz";
    file_put_contents($young, 'young');
    touch($young, time() - 3 * 86400);

    opFakeDump();
    $this->artisan('canteen:backup')->assertExitCode(0);

    // 12 files: the new one, the young one and the 5 newest old ones are the newest 7; the other 5 are old enough to go
    expect(glob("{$dir}/canteen-*.sql.gz"))->toHaveCount(7)
        ->and(file_exists($young))->toBeTrue()
        ->and(file_exists("{$dir}/canteen-20260101-020000.sql.gz"))->toBeFalse()
        ->and(file_exists("{$dir}/canteen-20260110-020000.sql.gz"))->toBeTrue();

    File::deleteDirectory($dir);
});

it('never removes a young backup, however many there are', function () {
    $dir = opBackupDir();
    config(['ops.backup.keep_days' => 30, 'ops.backup.keep_min' => 2]);

    foreach (range(1, 6) as $i) {
        $file = "{$dir}/canteen-2026020{$i}-020000.sql.gz";
        file_put_contents($file, 'recent');
        touch($file, time() - $i * 86400);
    }

    opFakeDump();
    $this->artisan('canteen:backup')->assertExitCode(0);

    expect(glob("{$dir}/canteen-*.sql.gz"))->toHaveCount(7);

    File::deleteDirectory($dir);
});

it('copies the backup to the off-site disk when one is set', function () {
    Storage::fake('offsite');
    $dir = opBackupDir();
    config(['ops.backup.disk' => 'offsite']);
    opFakeDump();

    $this->artisan('canteen:backup')->assertExitCode(0)->expectsOutputToContain('Copied to the disk');

    $copies = Storage::disk('offsite')->files('canteen-backups');

    expect($copies)->toHaveCount(1)->and(Heartbeat::last('backup_offsite'))->not->toBeNull();

    File::deleteDirectory($dir);
});

it('keeps the local backup but fails when the off-site copy fails', function () {
    $dir = opBackupDir();
    config(['ops.backup.disk' => 'a-disk-that-does-not-exist']);
    opFakeDump();

    $this->artisan('canteen:backup')->assertExitCode(1)->expectsOutputToContain('off-site copy');

    expect(glob("{$dir}/*"))->toHaveCount(1)->and(Heartbeat::last('backup'))->not->toBeNull()->and(Heartbeat::last('backup_offsite'))->toBeNull();

    File::deleteDirectory($dir);
});

// ----------------------------------------------------------------- canteen:check

it('shows the real operations lines in canteen:check, in place of the old reminders', function () {
    config(['queue.default' => 'database']);
    Artisan::call('canteen:check', ['--json' => true]);

    $lines = collect(json_decode(Artisan::output(), true));
    $areas = $lines->pluck('area');

    expect($areas)->toContain('Scheduler')->toContain('Backup')->toContain('Queue worker')
        ->and($lines->where('area', 'Scheduler'))->toHaveCount(1)
        ->and($lines->where('area', 'Backup')->first()['message'])->not->toContain('Make sure')
        ->and($areas)->not->toContain('Backups');
});

it('fails canteen:check on a production server whose backup is stale', function () {
    config(['app.env' => 'production', 'queue.default' => 'database']);
    Heartbeat::beat('backup', 'old');
    $this->travel(30)->hours();

    Artisan::call('canteen:check', ['--json' => true]);
    $failing = collect(json_decode(Artisan::output(), true))->where('level', 'FAIL')->pluck('area')->all();

    expect($failing)->toContain('Backup');
});

// ------------------------------------------------------------------------ /health

it('has no health page until a token is set', function () {
    config(['ops.health_token' => '']);

    $this->get('/health')->assertNotFound();
});

it('answers only to the right token', function () {
    config(['ops.health_token' => 'a-long-random-token']);

    $this->get('/health')->assertForbidden();
    $this->get('/health', ['X-Health-Token' => 'wrong'])->assertForbidden();
    $this->get('/health?token=a-long-random-token')->assertForbidden();   // a token in the address would end up in logs

    $this->get('/health', ['X-Health-Token' => 'a-long-random-token'])
        ->assertOk()
        ->assertJsonPath('database', 'ok')
        ->assertJsonStructure(['status', 'database', 'checks' => [['level', 'area', 'message']]]);
});

it('says 503 when something that was running has stopped on a production server', function () {
    config(['ops.health_token' => 'tok', 'app.env' => 'production', 'queue.default' => 'database']);
    Heartbeat::beat('backup', 'old');
    $this->travel(30)->hours();

    $this->get('/health', ['X-Health-Token' => 'tok'])->assertStatus(503)->assertJsonPath('status', 'fail');
});

it('says ok or warn, with 200, when nothing has failed', function () {
    config(['ops.health_token' => 'tok', 'queue.default' => 'database']);

    $this->get('/health', ['X-Health-Token' => 'tok'])->assertOk()->assertJsonPath('status', 'warn');   // never seen yet: a warning
});
