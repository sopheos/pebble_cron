<?php

use Pebble\Cron\Cron;
use Pebble\Cron\Job;
use PHPUnit\Framework\TestCase;

class ExposedCron extends Cron
{
    public function config(): array
    {
        return $this->config;
    }

    public function command(Job $job): string
    {
        return $this->getExecutableCommand($job);
    }
}

class CronTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pebble_cron_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/{,.}[!.]*', GLOB_BRACE | GLOB_NOSORT) ?: []);
        rmdir($this->dir);
    }

    private function waitFor(string $file): bool
    {
        for ($i = 0; $i < 100 && !is_file($file); $i++) {
            usleep(50000);
        }
        return is_file($file);
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public function testTmpdirGetsATrailingSlash()
    {
        self::assertSame($this->dir . '/', (new ExposedCron(['tmpdir' => $this->dir]))->config()['tmpdir']);
    }

    public function testMissingTmpdirFallsBackToTheSystemTempDir()
    {
        $cron = new ExposedCron(['tmpdir' => $this->dir . '/missing']);

        self::assertSame(rtrim(sys_get_temp_dir(), '/') . '/', $cron->config()['tmpdir']);
    }

    public function testAddPrefixesTheNameWithTheApp()
    {
        $cron = new Cron(['app' => 'shop', 'tmpdir' => $this->dir]);

        self::assertSame('shop_backup', $cron->add('backup')->export()['name']);
        self::assertSame('backup', (new Cron(['tmpdir' => $this->dir]))->add('backup')->export()['name']);
    }

    public function testAddAppliesTheConfigDefaults()
    {
        $job = (new Cron(['max_runtime' => 60, 'stdout' => '/var/log/out.log', 'tmpdir' => $this->dir]))->add('a');

        self::assertSame(60, $job->export()['max_runtime']);
        self::assertSame('/var/log/out.log', $job->export()['stdout']);
    }

    public function testExecutableCommandPassesTheJobAsAQueryString()
    {
        $cron = new ExposedCron(['tmpdir' => $this->dir]);
        $job = $cron->add('a')->command('echo "$HOME" && ls');

        self::assertSame(1, preg_match('#^"(.+/src/run-job\.php)" "(.+)"$#', $cron->command($job), $m));
        self::assertFileExists($m[1]);

        parse_str($m[2], $data);
        self::assertSame('echo "$HOME" && ls', $data['command']);
        self::assertSame($this->dir . '/', $data['tmpdir']);
        self::assertStringNotContainsString('"', $m[2]);
    }

    // -------------------------------------------------------------------------
    // Run
    // -------------------------------------------------------------------------

    public function testRunLaunchesDueJobsInTheBackground()
    {
        if (!extension_loaded('posix')) {
            self::markTestSkipped('posix extension is required');
        }

        $cron = new Cron(['app' => 'test', 'tmpdir' => $this->dir]);
        $cron->add('due')->command('touch ' . $this->dir . '/due.done');
        $cron->add('later')->command('touch ' . $this->dir . '/later.done')->schedule('2000-01-01 00:00:00');
        $cron->run();

        self::assertTrue($this->waitFor($this->dir . '/test_due.json'));
        self::assertFileExists($this->dir . '/due.done');

        for ($i = 0; $i < 100 && is_file($this->dir . '/test_due.lock'); $i++) {
            usleep(10000);
        }
        self::assertFileDoesNotExist($this->dir . '/later.done');
        self::assertFileDoesNotExist($this->dir . '/test_later.json');
    }

    public function testRunWritesTheJobListToCronJson()
    {
        if (!extension_loaded('posix')) {
            self::markTestSkipped('posix extension is required');
        }

        $cron = new Cron(['app' => 'shop', 'tmpdir' => $this->dir]);
        $cron->add('later')->schedule('2000-01-01 00:00:00');
        $cron->run();

        $jobs = json_decode(file_get_contents($this->dir . '/cron.json'), true);
        self::assertSame(['shop_later'], array_column($jobs, 'name'));
    }

    // -------------------------------------------------------------------------
    // Known bugs (see TODO.md)
    // -------------------------------------------------------------------------

    public function testAddCopiesStdoutIntoStderr()
    {
        // BUG: Cron::add() calls $job->stderr($this->config['stdout']): the 'stderr'
        // config key is ignored.
        $job = (new Cron(['stdout' => '/var/log/out.log', 'stderr' => '/var/log/err.log', 'tmpdir' => $this->dir]))->add('a');

        self::assertSame('/var/log/out.log', $job->export()['stderr']);
    }
}
