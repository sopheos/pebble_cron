---
name: pebble-cron
description: How to correctly declare, schedule and troubleshoot background jobs using the sopheos/pebble_cron PHP library (namespace Pebble\Cron — classes Cron, Job, JobRunner, Lock, ScheduleChecker, Helper and the run-job.php worker script). Use this whenever the project's composer.json requires sopheos/pebble_cron, code imports from Pebble\Cron\*, or you're asked to add/change a scheduled task, a cron entry, a nightly/hourly job, a job's log or output file, or to investigate a job that "stopped running", "never runs" or "runs twice" in a PHP project that has this library available — even if the request is phrased generically like "run this script every 5 minutes" or "why didn't the backup run" without naming the library. Also check this before editing the system crontab, writing a hand-rolled flock/pid-file wrapper or a custom scheduler loop in such a project, since this library replaces those and has non-obvious and in places broken behavior (a failed run leaves a .crash file that disables the job until someone deletes it, the 'stderr' config key is ignored, only the last command of `a; b` is redirected, max_runtime only logs and never kills, the lock retry lasts about 1 ms, ScheduleChecker emits PHP 8.4+ deprecations on load) that hand-rolled code would miss.
---

# pebble-cron

`sopheos/pebble_cron` is a small PHP 8.5+ job scheduler. The system crontab calls one PHP entry point every minute. That script declares jobs on a `Cron` object and calls `run()`, which launches every due job in its own background PHP process (`src/run-job.php`). Each process runs the job's shell command through `JobRunner`, guarded by a lock file, and appends to a per-job JSON log. The library does **not** kill jobs that run too long, retry failed jobs, queue jobs, or provide a CLI.

Namespace: `Pebble\Cron\*`. Source lives in `vendor/sopheos/pebble_cron/src/`. Read it directly when you need an exact signature; this skill focuses on *how the pieces fit together* and the behavior that isn't obvious from the method names. Requires `ext-posix`.

## Orientation

- `Cron` holds the config (`app`, `max_runtime`, `stdout`, `stderr`, `tmpdir`) and the job list. `add($name)` returns a `Job`. `run()` writes `<tmpdir>/cron.json` and spawns `php run-job.php '<query string>' &` for each due job, then returns immediately.
- `Job` is a fluent definition: `command()`, `schedule()`/`every()`/`minutly()`/`hourly()`/`daily()`, `maxRuntime()`, `stdout()`, `stderr()`.
- `ScheduleChecker::isDue()` accepts a cron expression, a one-shot `Y-m-d H:i:s` date (matched to the minute) or a callable.
- `JobRunner` runs in the background process. It owns the state files in `tmpdir`: `<name>.lock`, `<name>.crash`, `<name>.disabled`, `<name>.json`.
- `Lock` is a non-blocking `flock` with the PID written into the file. `Helper::escape()` turns a job name into the file base name.

For a full method cheat sheet and the state-file table, see `references/api-reference.md`. For the complete list of easy-to-miss behaviors, see `references/gotchas.md`. Read it before debugging a job that "doesn't run".

## Core recipes

### Entry point

```php
// bin/cron.php — crontab: * * * * * php /var/www/app/bin/cron.php
require __DIR__ . '/../vendor/autoload.php';

use Pebble\Cron\Cron;

$cron = new Cron([
    'app' => 'shop',                         // job names become shop_<name>
    'tmpdir' => '/var/www/app/var/cron',      // must exist, or it silently falls back to sys_get_temp_dir()
    'stdout' => '/var/www/app/var/log/cron.log',
    'max_runtime' => 600,
]);

$cron->add('backup')->command('php /var/www/app/bin/backup.php')->daily(3);
$cron->add('mails')->command('php /var/www/app/bin/mails.php')->every(minute: '*/5');
$cron->add('report')->command('php /var/www/app/bin/report.php')->schedule('0 8 * * 1');

$cron->run();
```

The entry point must run **every minute**: a job is due only during the matching minute.

### Separate stderr

The `stderr` config key is ignored (bug). Set it per job, after `add()`:

```php
$cron->add('backup')
    ->command('php bin/backup.php')
    ->stdout('/var/log/app/backup.log')
    ->stderr('/var/log/app/backup.err');
```

### Multi-step commands

Only the last command of a shell list is redirected (bug). Wrap it yourself:

```php
$job->command("sh -c 'php bin/export.php && php bin/upload.php'");
```

### One-shot job

```php
$cron->add('migration')->command('php bin/migrate.php')->schedule('2026-11-01 02:00:00');
```

### Re-enable a crashed job, or pause one

```php
// A non-zero exit code creates <tmpdir>/<escaped name>.crash, and the job never runs again.
unlink('/var/www/app/var/cron/shop_backup.crash');

// Pause without touching the code:
touch('/var/www/app/var/cron/shop_backup.disabled');
```

The escaped name is lower-cased, keeps `[a-z0-9_.-]` and turns spaces into `_`. Read `<name>.json` to see the last 100 runs (`Info`, `Warning`, `Error`).

## Behavior to keep in mind while writing code

- **A failed run disables the job.** Any non-zero exit code writes `<name>.crash`, and every later run is skipped until someone deletes the file. Make commands exit 0 when a failure is expected.
- **`max_runtime` never kills anything.** If the previous run is still alive past `max_runtime`, a `Warning` is logged each minute and the new run is skipped.
- **A job still running inside `max_runtime` is skipped silently**, with no log entry.
- **The `stderr` config key is ignored (bug).** `Cron::add()` copies `stdout` into the job's stderr.
- **Only the last command of `a; b` / `a && b` is redirected (bug).** Wrap lists in `sh -c '…'`.
- **Output is appended (`>>`), never rotated.** The JSON log keeps the last 100 entries.
- **`ScheduleChecker` emits two `E_DEPRECATED` on load under PHP 8.4+ (bug).** Expect them in logs and in PHPUnit output.
- **The lock retries for about 1 ms in total (bug)**, and `release()` deletes the lock file, which leaves a tiny window for two concurrent runs of the same job.
- **Names are escaped for file names.** Two names that differ only by case or punctuation share the same state files, and a name made only of non-ASCII or symbols becomes `<tmpdir>/.lock`.
- **`cron.json` is not prefixed by `app`.** Give each application its own `tmpdir`.
- **The method is spelled `minutly()`.** There is no `minutely()`.

Read `references/gotchas.md` for the rest before assuming the scheduler behaves like the system cron.
