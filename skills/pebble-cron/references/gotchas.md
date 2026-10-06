# pebble-cron — gotchas

Things the method names don't tell you, grouped by class. Every item below is pinned by a test in `tests/`. Items marked **(bug)** are listed in the package's `TODO.md` and may be fixed in a later version. Check the test of the same name in `vendor/sopheos/pebble_cron/tests/` to see the current behavior.

## Cron

- **(bug) The `stderr` config key is ignored.** `add()` calls `$job->stderr($this->config['stdout'])`, so every job's stderr goes to the `stdout` file. Set `->stderr()` on each job instead.
- **`app` prefixes the job name with `<app>_`.** Without `app`, the name is used as is.
- **`add()` copies `max_runtime` and `stdout` into the job.**
- **A `tmpdir` that doesn't exist silently falls back to `sys_get_temp_dir()`.** A trailing `/` is always added.
- **`run()` launches due jobs in background processes** and skips the others. Check `<name>.json` to know whether a job actually ran.
- **`run()` writes `<tmpdir>/cron.json`** with every declared job, due or not, and without the `app` prefix in the file name.
- **The job travels as a query string.** `getExecutableCommand()` builds `"run-job.php" "<http_build_query>"`, so quotes and `$` in the command are URL-encoded and survive the shell.

## Job

- **The every-minute shortcut is spelled `minutly()`.**
- **`every()` takes named arguments in any order**: `every(dayWeek: 1, hour: 6, minute: 30)` gives `30 6 * * 1`.
- **`daily()` always runs at minute 0.**
- **Schedules are not validated** until `ScheduleChecker` evaluates them.
- **An empty name becomes `md5($command)`** in `export()`.

## ScheduleChecker

- **Cron expressions are evaluated by `dragonmantank/cron-expression`.** Macros like `@daily` work, and an invalid expression throws `InvalidArgumentException`.
- **A `Y-m-d H:i:s` string is a one-shot schedule**, compared to the minute. Seconds are ignored.
- **A callable receives the reference `DateTimeImmutable`.**
- **A non-static `[Class::class, 'method']` triggers an `E_USER_DEPRECATED`, then a `TypeError`**, because it is not callable and falls through to `DateTime::createFromFormat()`.
- **`new ScheduleChecker()` uses the current time.**
- **(bug) Loading the class emits two `E_DEPRECATED` on PHP 8.4+** (implicitly nullable `$now` and `$callable_name`).

## JobRunner

- **A non-zero exit code disables the job for good.** It writes `<name>.crash` and an `Error` log entry. While the file exists, `run()` does nothing, not even log.
- **`<name>.disabled` skips the job** the same way.
- **`max_runtime` never kills anything.** If the lock's PID is alive and the file is older than `max_runtime`, a `Warning` is logged and the run is skipped.
- **A lock left by a dead process is ignored.** `getLifetime()` returns `0`, and the run proceeds.
- **A lock held by a running job skips the run with no log entry.**
- **Output is appended** to the `stdout`/`stderr` files, never truncated.
- **The JSON log keeps the last 100 entries.** Each one has `ref`, `date`, `status` and `message`. A success is `Info`/`Fini en Ns`.
- **The lock file is removed after the run.**
- **(bug) Only the last command of a shell list is redirected.** `echo first; echo second` writes only `second` to the stdout file. Wrap lists in `sh -c '…'`.

## Lock

- **`acquire()` writes the PID into the file** and returns `true`.
- **Acquiring twice on the same instance throws** `Lock already acquired`. **A second instance on the same file throws** `Too much attempts`.
- **`acquire()` throws when the file can't be created**, e.g. in a missing directory.
- **`release()` deletes the file**, even if this instance never acquired it.
- **`getLifetime()` is `0` without file, without PID, or when the PID is dead.** Otherwise it is the file's age in seconds.
- **(bug) The 5 attempts last about 1 ms in total** (`usleep(250)` instead of 250 ms).
- **(bug) Deleting the file on release allows two holders.** A handle opened before the release can lock the deleted inode while a new `Lock` creates and locks a new file.

## Helper

- **`escape()` lower-cases, drops everything outside `[a-z0-9_. -]` and turns spaces into `_`.** `/` is dropped, so names can't escape `tmpdir`.
- **`escape()` can return an empty string** (`'!!!'`), and accented letters are dropped (`'Été'` becomes `'t'`).
- **`getTempDir()` is `sys_get_temp_dir()`**, and **`getPhpBinary()`** returns the PHP binary found by `symfony/process`.
