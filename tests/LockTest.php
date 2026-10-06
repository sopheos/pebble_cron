<?php

use Pebble\Cron\Exception;
use Pebble\Cron\Lock;
use PHPUnit\Framework\TestCase;

class LockTest extends TestCase
{
    private string $dir;
    private string $file;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pebble_cron_' . uniqid();
        mkdir($this->dir);
        $this->file = $this->dir . '/job.lock';
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/{,.}[!.]*', GLOB_BRACE | GLOB_NOSORT) ?: []);
        rmdir($this->dir);
    }

    // -------------------------------------------------------------------------
    // acquire / release
    // -------------------------------------------------------------------------

    public function testAcquireWritesThePidIntoTheLockFile()
    {
        $lock = new Lock($this->file);

        self::assertTrue($lock->acquire());
        self::assertSame((string) getmypid(), file_get_contents($this->file));

        $lock->release();
    }

    public function testAcquireTwiceOnTheSameInstanceThrows()
    {
        $lock = new Lock($this->file);
        $lock->acquire();

        try {
            $this->expectException(Exception::class);
            $this->expectExceptionMessage('Lock already acquired');
            $lock->acquire();
        } finally {
            $lock->release();
        }
    }

    public function testASecondLockOnTheSameFileGivesUp()
    {
        $first = new Lock($this->file);
        $first->acquire();

        try {
            $this->expectException(Exception::class);
            $this->expectExceptionMessage('Too much attempts');
            (new Lock($this->file))->acquire();
        } finally {
            $first->release();
        }
    }

    public function testReleaseDeletesTheLockFile()
    {
        $lock = new Lock($this->file);
        $lock->acquire();
        $lock->release();

        self::assertFileDoesNotExist($this->file);
        self::assertTrue((new Lock($this->file))->acquire());
    }

    public function testReleaseWithoutAcquireDeletesAnExistingFile()
    {
        file_put_contents($this->file, '123');
        (new Lock($this->file))->release();

        self::assertFileDoesNotExist($this->file);
    }

    public function testAcquireFailsInAMissingDirectory()
    {
        $this->expectException(Throwable::class);

        @(new Lock($this->dir . '/missing/job.lock'))->acquire();
    }

    // -------------------------------------------------------------------------
    // getLifetime
    // -------------------------------------------------------------------------

    public function testLifetimeIsZeroWithoutFileOrPid()
    {
        self::assertSame(0, (new Lock($this->file))->getLifetime());

        touch($this->file);
        self::assertSame(0, (new Lock($this->file))->getLifetime());
    }

    public function testLifetimeIsZeroWhenTheProcessIsDead()
    {
        file_put_contents($this->file, '2147483646');
        touch($this->file, time() - 600);

        self::assertSame(0, (new Lock($this->file))->getLifetime());
    }

    public function testLifetimeIsTheAgeOfTheFileWhenTheProcessIsAlive()
    {
        file_put_contents($this->file, (string) getmypid());
        touch($this->file, time() - 600);

        self::assertGreaterThanOrEqual(600, (new Lock($this->file))->getLifetime());
        self::assertLessThan(610, (new Lock($this->file))->getLifetime());
    }

    // -------------------------------------------------------------------------
    // Known bugs (see TODO.md)
    // -------------------------------------------------------------------------

    public function testAcquireGivesUpAfterAboutOneMillisecond()
    {
        // BUG: usleep(250) waits 0.25 ms, not 250 ms: the 5 attempts take about
        // 1.25 ms in total, so the retry loop is useless.
        $first = new Lock($this->file);
        $first->acquire();

        $start = microtime(true);
        try {
            (new Lock($this->file))->acquire();
        } catch (Exception $ex) {
        }
        $elapsed = microtime(true) - $start;
        $first->release();

        self::assertLessThan(0.1, $elapsed);
    }

    public function testReleaseUnlinksTheFileSoAStaleHandleAndANewLockBothSucceed()
    {
        // BUG: release() unlinks the file after unlocking it. A process that opened
        // the old file before the release locks the deleted inode, while a new
        // process creates and locks a new file: both believe they hold the lock.
        $first = new Lock($this->file);
        $first->acquire();
        $stale = fopen($this->file, 'rb+');
        $first->release();

        $second = new Lock($this->file);
        self::assertTrue($second->acquire());
        self::assertTrue(flock($stale, LOCK_EX | LOCK_NB));

        fclose($stale);
        $second->release();
    }
}
