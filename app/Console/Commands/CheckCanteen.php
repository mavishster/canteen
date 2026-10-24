<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\User;
use App\Services\Payments\AbaPayWayGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * Lists what is unsafe or missing before a real school uses the system.
 * FAIL = do not go live. WARN = fix before the first week (or a development-only setting). OK = fine.
 * It only reads: it never changes anything and never prints secrets or personal data.
 */
class CheckCanteen extends Command
{
    use \App\Console\Commands\Concerns\ChecksConsent;
    use \App\Console\Commands\Concerns\ChecksOperations;

    protected $signature = 'canteen:check {--json : Print the results as JSON (for a monitoring tool)}';

    protected $description = 'Checks the configuration and data for a go-live: what is unsafe or missing.';

    /** @var array<int, array{level: string, area: string, message: string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->results = [];   // a second run in the same process must not repeat the first one

        $production = config('app.env') === 'production';

        $this->checkApp($production);
        $this->checkPayments($production);
        $this->checkMessaging($production);
        $this->checkSis($production);
        $this->checkQueueAndSchedule($production);
        $this->checkDatabase();
        $this->checkAccounts($production);
        $this->checkConsent($production);
        $this->checkOperations($production);

        if ($this->option('json')) {
            $this->line(json_encode($this->results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($this->results as $r) {
                $line = str_pad("[{$r['level']}]", 8) . str_pad($r['area'], 16) . $r['message'];

                match ($r['level']) {
                    'FAIL' => $this->error($line),
                    'WARN' => $this->warn($line),
                    default => $this->line($line),
                };
            }

            $fails = $this->count('FAIL');
            $warns = $this->count('WARN');

            $this->newLine();
            $this->line($fails === 0
                ? "No blocking problems. {$warns} warning(s) to look at."
                : "{$fails} blocking problem(s), {$warns} warning(s). Do not go live until the FAIL lines are fixed.");
        }

        return $this->count('FAIL') === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** "Bad" on a real server, only a heads-up on a development computer. */
    private function badIf(bool $production): string
    {
        return $production ? 'FAIL' : 'WARN';
    }

    private function checkApp(bool $production): void
    {
        $debug = (bool) config('app.debug');
        $this->add(
            $debug ? $this->badIf($production) : 'OK',
            'App debug',
            $debug ? 'Debug mode is on: error pages would show secrets. Set APP_DEBUG=false.' : 'Debug mode is off.'
        );

        $this->add(
            blank(config('app.key')) ? 'FAIL' : 'OK',
            'App key',
            blank(config('app.key')) ? 'APP_KEY is empty. Run php artisan key:generate once on the server.' : 'APP_KEY is set.'
        );

        $https = str_starts_with((string) config('app.url'), 'https://');
        $this->add(
            $https ? 'OK' : $this->badIf($production),
            'HTTPS',
            $https ? 'The site address uses https.' : 'APP_URL does not use https. Parents and staff sign in over it.'
        );

        $this->add(
            $production ? 'OK' : 'WARN',
            'Environment',
            $production
                ? 'APP_ENV is "production".'
                : 'APP_ENV is "' . config('app.env') . '" (development). Set APP_ENV=production on the server.'
        );
    }

    private function checkPayments(bool $production): void
    {
        $gateway = (string) config('payments.default');

        if ($gateway === 'fake') {
            $this->add(
                $this->badIf($production),
                'Payments',
                $production
                    ? 'The fake payment gateway is selected. Parents would be told they paid when they did not. Set PAYMENT_GATEWAY=aba.'
                    : 'The fake payment gateway is selected (fine for development only).'
            );

            return;
        }

        $this->add('OK', 'Payments', "Gateway is '{$gateway}'.");

        if ($gateway === 'aba') {
            $missing = array_keys(array_filter([
                'ABA_MERCHANT_ID' => config('payments.aba.merchant_id'),
                'ABA_API_KEY' => config('payments.aba.api_key'),
                'ABA_BASE_URL' => config('payments.aba.base_url'),
            ], fn ($value) => blank($value)));

            $this->add(
                $missing ? $this->badIf($production) : 'OK',
                'ABA PayWay',
                $missing ? 'Missing settings: ' . implode(', ', $missing) . '.' : 'Merchant settings are present.'
            );

            // The driver class says when it is real. Until then every top-up answers "not available".
            $installed = defined(AbaPayWayGateway::class . '::IMPLEMENTED') && AbaPayWayGateway::IMPLEMENTED;
            $this->add(
                $installed ? 'OK' : $this->badIf($production),
                'ABA driver',
                $installed
                    ? 'The ABA PayWay driver is installed.'
                    : 'The ABA PayWay driver is not written yet: every top-up would answer "not available". Do not go live on it.'
            );
        }
    }

    private function checkMessaging(bool $production): void
    {
        foreach (['push' => 'Push', 'sms' => 'SMS'] as $key => $label) {
            $driver = (string) config("messaging.{$key}");

            if (in_array($driver, ['log', 'fake'], true)) {
                $this->add(
                    $this->badIf($production),
                    "{$label} messages",
                    $production
                        ? "The '{$driver}' driver writes children's names and balances to the log. Choose a real provider."
                        : "The '{$driver}' driver is for development."
                );
            } elseif ($driver === 'none') {
                $this->add('WARN', "{$label} messages", "No {$label} provider: parents will not be told by {$label}.");
            } else {
                $this->add('OK', "{$label} messages", "Driver is '{$driver}'.");
            }
        }
    }

    private function checkSis(bool $production): void
    {
        if (config('sis.driver') === 'fake') {
            $this->add($this->badIf($production), 'SIS', 'The fake SIS is selected. Real parents cannot be checked against the school system.');

            return;
        }

        $api = (string) config('sis.api.base_url');

        if (blank($api)) {
            $this->add($this->badIf($production), 'SIS API', 'SIS_API_URL is empty: parents cannot sign in through the school system.');
        } elseif (! str_starts_with($api, 'https://')) {
            $this->add($this->badIf($production), 'SIS API', 'The SIS API address is not https. Use https.');
        } else {
            $this->add('OK', 'SIS API', 'SIS API address is set (https).');
        }

        $dbSet = ! blank(config('sis.database.database')) && ! blank(config('sis.database.username'));
        $this->add(
            $dbSet ? 'OK' : $this->badIf($production),
            'SIS database',
            $dbSet ? 'Read-only SIS database settings are present.' : 'SIS database settings are missing (SIS_DB_DATABASE, SIS_DB_USERNAME).'
        );

        $localLogin = (bool) config('sis.allow_local_parent_login');
        $this->add(
            $localLogin ? $this->badIf($production) : 'OK',
            'Parent login',
            $localLogin
                ? ($production ? 'The email and password parent login is still on. Set SIS_ALLOW_LOCAL_PARENT_LOGIN=false.' : 'Email and password parent login is on (development).')
                : 'Only the school system login is accepted for parents.'
        );

        $this->add(
            config('sis.sync_phone') ? 'OK' : 'WARN',
            'Parent phones',
            config('sis.sync_phone')
                ? 'Phone numbers come from the school system.'
                : 'Phone numbers are not synced from the SIS, so SMS alerts will not reach parents.'
        );
    }

    private function checkQueueAndSchedule(bool $production): void
    {
        $queue = (string) config('queue.default');

        $this->add(
            $queue === 'sync' ? $this->badIf($production) : 'OK',
            'Queue',
            $queue === 'sync'
                ? 'The queue is "sync": notifications run inside the sale request. Use database or redis, and run a worker.'
                : "Queue driver is '{$queue}'. Make sure `php artisan queue:work` runs all the time (Supervisor or systemd)."
        );

    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $this->add('FAIL', 'Database', 'The database cannot be reached.');

            return;
        }

        if (! Schema::hasTable('migrations')) {
            $this->add('FAIL', 'Migrations', 'The database is empty. Run php artisan migrate --force.');

            return;
        }

        $pending = $this->pendingMigrations();
        $this->add(
            $pending ? 'FAIL' : 'OK',
            'Migrations',
            $pending ? count($pending) . ' migration(s) are not applied. Run php artisan migrate --force.' : 'All migrations are applied.'
        );

        if (! Schema::hasTable('schools')) {
            return;
        }

        $schools = School::count();
        $this->add(
            $schools === 0 ? 'FAIL' : 'OK',
            'Schools',
            $schools === 0 ? 'No school exists yet. Run php artisan canteen:create-school.' : "{$schools} school(s)."
        );

        $unlinked = School::whereNull('sis_branch_id')->count();
        if ($schools > 0 && $unlinked > 0) {
            $this->add('WARN', 'School SIS link', "{$unlinked} school(s) are not linked to a SIS branch, so their students cannot be imported.");
        }

        $noCap = School::all()->filter(fn (School $s) => ! data_get($s->settings, 'limits.weekly'))->count();
        if ($schools > 0 && $noCap > 0) {
            $this->add('WARN', 'Spending caps', "{$noCap} school(s) have no weekly spending maximum set (Settings).");
        }
    }

    private function checkAccounts(bool $production): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles') || ! Schema::hasTable('users')) {
            $this->add('FAIL', 'Roles', 'The account tables are missing. Run php artisan migrate --force.');

            return;
        }

        $wanted = ['super-admin', 'admin', 'manager', 'cashier', 'parent'];
        $missing = array_values(array_diff($wanted, Role::whereIn('name', $wanted)->pluck('name')->all()));

        $this->add(
            $missing ? 'FAIL' : 'OK',
            'Roles',
            $missing ? 'Missing roles: ' . implode(', ', $missing) . '. Run php artisan canteen:install.' : 'All roles exist.'
        );

        // User::role() throws when the role does not exist, so never ask for a role that is missing
        $supers = in_array('super-admin', $missing, true) ? 0 : User::role('super-admin')->count();
        $this->add(
            $supers === 0 ? 'FAIL' : 'OK',
            'Super-admin',
            $supers === 0 ? 'No super-admin exists. Run php artisan canteen:install.' : "{$supers} super-admin(s)."
        );

        $demo = User::where(fn ($q) => $q->where('email', 'like', '%@pilot.test')->orWhere('email', 'like', '%@canteen.test'))->count();
        $this->add(
            $demo > 0 ? $this->badIf($production) : 'OK',
            'Demo accounts',
            $demo > 0 ? "{$demo} demo account(s) (@pilot.test or @canteen.test) exist. Delete them before go-live." : 'No demo accounts.'
        );

        $schoolless = User::whereNull('school_id')
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'super-admin'))
            ->count();

        if ($schoolless > 0) {
            $this->add('WARN', 'Accounts', "{$schoolless} account(s) belong to no school and are not super-admins. They cannot do anything.");
        }

        $admins = in_array('admin', $missing, true) ? 0 : User::role('admin')->count();
        $this->add(
            $admins === 0 ? 'WARN' : 'OK',
            'School admins',
            $admins === 0 ? 'No school admin yet. Run php artisan canteen:create-admin.' : "{$admins} school admin(s)."
        );
    }

    /** @return array<int, string> */
    private function pendingMigrations(): array
    {
        $ran = DB::table('migrations')->pluck('migration')->all();

        return collect(glob(database_path('migrations/*.php')) ?: [])
            ->map(fn ($file) => pathinfo($file, PATHINFO_FILENAME))
            ->reject(fn ($name) => in_array($name, $ran, true))
            ->values()
            ->all();
    }

    private function add(string $level, string $area, string $message): void
    {
        $this->results[] = ['level' => $level, 'area' => $area, 'message' => $message];
    }

    private function count(string $level): int
    {
        return count(array_filter($this->results, fn ($r) => $r['level'] === $level));
    }
}
