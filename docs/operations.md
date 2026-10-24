# Operations

`php artisan canteen:check` verifies that the system is being looked after. A production server FAILS when something that was running has stopped.

## 1. The scheduler (every minute)
Add this one line to the server's crontab (`crontab -e` as the user that runs the site):

    * * * * * cd /path/to/canteen && php artisan schedule:run >> /dev/null 2>&1

It reconciles top-ups, expires meal plans, writes the scheduler heartbeat, sends the queue heartbeat, and runs the daily backup at 02:00.

## 2. The queue worker (always running)
Parents' push messages and SMS go through the queue. Keep a worker alive with Supervisor, `/etc/supervisor/conf.d/canteen-worker.conf`:

    [program:canteen-worker]
    process_name=%(program_name)s_%(process_num)02d
    command=php /path/to/canteen/artisan queue:work --sleep=3 --tries=3 --max-time=3600
    autostart=true
    autorestart=true
    stopasgroup=true
    killasgroup=true
    user=www-data
    numprocs=1
    redirect_stderr=true
    stdout_logfile=/path/to/canteen/storage/logs/worker.log
    stopwaitsecs=3600

Then `sudo supervisorctl reread && sudo supervisorctl update`. After every deploy run `php artisan queue:restart`.

## 3. Backups
`php artisan canteen:backup` writes `storage/app/backups/canteen-YYYYmmdd-HHMMSS.sql.gz` (a consistent snapshot; the file only gets its final name when it is complete),
keeps the newest 7 and anything younger than 30 days, and is scheduled daily at 02:00. It needs the `mysqldump` program on the server (it comes with the MySQL client).
Settings in `.env`: `BACKUP_KEEP_DAYS`, `BACKUP_MAX_AGE_HOURS`, `BACKUP_PATH`.

**A backup on the same server is not enough.** Set `BACKUP_DISK` to a disk from `config/filesystems.php` (an S3-compatible bucket, for example) and every backup is copied there too.
A second option without code: `rclone sync storage/app/backups remote:canteen-backups` from cron.

**Restore** (try it once before you need it, on a spare database):

    gunzip -c canteen-20261023-020000.sql.gz | mysql -u USER -p DATABASE

## 4. Monitoring from outside
Set `HEALTH_TOKEN` in `.env` (a long random string). Then an uptime monitor can call `GET /health` with the header `X-Health-Token: <token>`:
200 means ok or warn, 503 means the database is down or something that was running has stopped. Without a token the page does not exist.

## What `canteen:check` verifies
Scheduler heartbeat (every minute; fails after 3 minutes), queue worker (a job every 5 minutes; fails after 12), last good backup (fails after 26 hours),
the off-site copy (warns), free disk space (warns under 2 GB or 10%). "Not seen yet" is only a warning, so a fresh install is not a failure.
