# pebble-cron — API cheat sheet

Quick lookup by intent. This is not exhaustive. Read the source in `vendor/sopheos/pebble_cron/src/` for exact signatures and for edge cases not covered here.

## Cron (`Pebble\Cron\Cron`)

| Intent | Method |
| ------ | ------ |
| Build with config | `__construct(array $config = [])` |
| Declare a job (returns the `Job` to configure) | `add(string $name): Job` |
| Write `cron.json` and launch every due job in the background | `run()` |

Config keys (merged over the defaults):

| Key | Default | Meaning |
| --- | ------- | ------- |
| `app` | `null` | Prefix: job `x` becomes `<app>_x` |
| `max_runtime` | `300` | Copied into each job by `add()` |
| `stdout` | `/dev/null` | Copied into each job's stdout **and stderr** by `add()` |
| `stderr` | `/dev/null` | Ignored (bug) |
| `tmpdir` | `sys_get_temp_dir()` | State files directory. A missing directory falls back to the default. A trailing `/` is added |

`run()` throws `Pebble\Cron\Exception('posix extension is required')` without `ext-posix`. It launches `php src/run-job.php "<http_build_query of export() + tmpdir>"` with `exec(… 1> /dev/null 2>&1 &)`.

## Job (`Pebble\Cron\Job`)

All setters return `static`.

| Intent | Method |
| ------ | ------ |
| Shell command (default `pwd`) | `command(string $command)` |
| Raw schedule (default `* * * * *`), not validated | `schedule(string $schedule)` |
| Build `"m h dom mon dow"` (defaults `*`) | `every($minute, $hour, $dayMonth, $month, $dayWeek)` |
| Every minute | `minutly()` |
| Minute 0 of every hour | `hourly()` |
| Every day at `$hour:00` | `daily(int $hour)` |
| Warning threshold in seconds | `maxRuntime(int $max)` |
| Append stdout / stderr to a file | `stdout(string $out)` / `stderr(string $out)` |
| Current schedule | `getSchedule(): string` |
| Array of `name`, `command`, `schedule`, `max_runtime`, `stdout`, `stderr` | `export(): array` / `jsonSerialize()` |

An empty name is replaced by `md5($command)` in `export()`.

## ScheduleChecker (`Pebble\Cron\ScheduleChecker`)

| Intent | Method |
| ------ | ------ |
| Reference time (defaults to now) | `__construct(DateTimeImmutable $now = null)` |
| Is the schedule due at `$now`? | `isDue($schedule): bool` |

Resolution order: callable (called with `$now`), then `Y-m-d H:i:s` date compared on `Y-m-d H:i`, then `Cron\CronExpression` (macros such as `@daily` work; invalid input throws `InvalidArgumentException`).

## JobRunner (`Pebble\Cron\JobRunner`)

| Intent | Method |
| ------ | ------ |
| Build from `Job::export()` + `tmpdir` | `__construct(array $config)` |
| Run once with locking and logging | `run()` |

State files, all `<tmpdir><Helper::escape(name)>.*`:

| File | Meaning |
| ---- | ------- |
| `.lock` | `flock`ed while the job runs. Contains the PID. Deleted on release |
| `.crash` | Written (date) when the command exits non-zero. Blocks every later run |
| `.disabled` | Created by hand. Skips the job |
| `.json` | Last 100 log entries `{ref, date, status, message}`. Status `Info` (`Fini en Ns`), `Warning` (max runtime), `Error` (exit code) |

The command runs as `<command> 1>> "<stdout>" 2>> "<stderr>"` through `exec()`.

## Lock (`Pebble\Cron\Lock`)

| Intent | Method |
| ------ | ------ |
| Take the lock, write the PID, return `true` | `acquire()` |
| Unlock and delete the file | `release()` |
| Seconds since the file's mtime if its PID is alive, else `0` | `getLifetime(): int` |

`acquire()` throws `Pebble\Cron\Exception` when the instance already holds the lock, when the file can't be created or opened, or after 5 failed `LOCK_EX | LOCK_NB` attempts.

## Helper (`Pebble\Cron\Helper`)

| Intent | Method |
| ------ | ------ |
| Job name to file base name | `escape($input): string` |
| PHP binary (`symfony/process` `PhpExecutableFinder`) | `getPhpBinary(): string` |
| Temp dir | `getTempDir(): string` |
