<?php

use Pebble\Cron\JobRunner;
use Pebble\Cron\Lock;
use PHPUnit\Framework\TestCase;

class JobRunnerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pebble_cron_' . uniqid() . '/';
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '{,.}[!.]*', GLOB_BRACE | GLOB_NOSORT) ?: []);
        rmdir($this->dir);
    }

    private function runner(string $command, array $config = []): JobRunner
    {
        return new JobRunner($config + [
            'tmpdir' => $this->dir,
            'name' => 'My Job',
            'command' => $command,
            'max_runtime' => 300,
            'stdout' => $this->dir . 'out.log',
            'stderr' => $this->dir . 'err.log',
        ]);
    }

    private function logs(): array
    {
        return json_decode(file_get_contents($this->dir . 'my_job.json'), true);
    }

    // -------------------------------------------------------------------------
    // Run
    // -------------------------------------------------------------------------

    public function testSuccessfulRunAppendsAnInfoLog()
    {
        $this->runner('true')->run();

        $logs = $this->logs();
        self::assertCount(1, $logs);
        self::assertSame('Info', $logs[0]['status']);
        self::assertSame('Fini en 0s', $logs[0]['message']);
        self::assertSame(['ref', 'date', 'status', 'message'], array_keys($logs[0]));
        self::assertFileDoesNotExist($this->dir . 'my_job.lock');
    }

    public function testOutputsAreAppendedToTheirFiles()
    {
        $runner = $this->runner("sh -c 'echo out; echo err >&2'");
        $runner->run();
        $runner->run();

        self::assertSame("out\nout\n", file_get_contents($this->dir . 'out.log'));
        self::assertSame("err\nerr\n", file_get_contents($this->dir . 'err.log'));
        self::assertCount(2, $this->logs());
    }

    public function testFailureWritesACrashFileAndAnErrorLog()
    {
        $this->runner('exit 3')->run();

        self::assertFileExists($this->dir . 'my_job.crash');
        self::assertSame('Error', $this->logs()[0]['status']);
        self::assertSame("Cron exited with status '3'.", $this->logs()[0]['message']);
    }

    public function testCrashFileBlocksEveryLaterRun()
    {
        touch($this->dir . 'my_job.crash');
        $this->runner('touch ' . $this->dir . 'ran')->run();

        self::assertFileDoesNotExist($this->dir . 'ran');
        self::assertFileDoesNotExist($this->dir . 'my_job.json');
    }

    public function testDisabledFileSkipsTheJob()
    {
        touch($this->dir . 'my_job.disabled');
        $this->runner('touch ' . $this->dir . 'ran')->run();

        self::assertFileDoesNotExist($this->dir . 'ran');
    }

    public function testLogsAreCappedToTheLast100Entries()
    {
        $old = array_fill(0, 100, ['ref' => 'x', 'date' => 'x', 'status' => 'Old', 'message' => '']);
        file_put_contents($this->dir . 'my_job.json', json_encode($old));

        $this->runner('true')->run();

        $logs = $this->logs();
        self::assertCount(100, $logs);
        self::assertSame('Info', $logs[99]['status']);
    }

    // -------------------------------------------------------------------------
    // Locking & max runtime
    // -------------------------------------------------------------------------

    public function testLockHeldElsewhereSkipsTheRunSilently()
    {
        $lock = new Lock($this->dir . 'my_job.lock');
        $lock->acquire();

        $this->runner('touch ' . $this->dir . 'ran')->run();
        $lock->release();

        self::assertFileDoesNotExist($this->dir . 'ran');
        self::assertSame([], $this->logs());
    }

    public function testMaxRuntimeExceededLogsAWarningAndDoesNotKillAnything()
    {
        file_put_contents($this->dir . 'my_job.lock', (string) getmypid());
        touch($this->dir . 'my_job.lock', time() - 600);

        $this->runner('touch ' . $this->dir . 'ran')->run();

        self::assertFileDoesNotExist($this->dir . 'ran');
        self::assertFileExists($this->dir . 'my_job.lock');
        self::assertSame('Warning', $this->logs()[0]['status']);
        self::assertStringStartsWith('Max runtime of 300 secs exceeded!', $this->logs()[0]['message']);
    }

    public function testStaleLockFromADeadProcessIsIgnored()
    {
        file_put_contents($this->dir . 'my_job.lock', '2147483646');
        touch($this->dir . 'my_job.lock', time() - 600);

        $this->runner('true')->run();

        self::assertSame('Info', $this->logs()[0]['status']);
    }

    // -------------------------------------------------------------------------
    // Known bugs (see TODO.md)
    // -------------------------------------------------------------------------

    public function testRedirectionOnlyAppliesToTheLastCommandOfAList()
    {
        // BUG: the command is not grouped before `1>> stdout 2>> stderr` is appended,
        // so only the last command of `a; b` or `a && b` is redirected. The output
        // of the others is swallowed by exec() or by the parent's /dev/null.
        $this->runner('echo first; echo second')->run();

        self::assertSame("second\n", file_get_contents($this->dir . 'out.log'));
    }
}
