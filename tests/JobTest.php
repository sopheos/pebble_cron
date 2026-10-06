<?php

use Pebble\Cron\Job;
use PHPUnit\Framework\TestCase;

class JobTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Defaults & export
    // -------------------------------------------------------------------------

    public function testExportDefaults()
    {
        self::assertSame([
            'name' => 'backup',
            'command' => 'pwd',
            'schedule' => '* * * * *',
            'max_runtime' => 300,
            'stdout' => '/dev/null',
            'stderr' => '/dev/null',
        ], (new Job('backup'))->export());
    }

    public function testSettersAreFluent()
    {
        $job = (new Job('backup'))
            ->command('php bin/backup.php')
            ->schedule('*/5 * * * *')
            ->maxRuntime(60)
            ->stdout('/var/log/out.log')
            ->stderr('/var/log/err.log');

        self::assertSame([
            'name' => 'backup',
            'command' => 'php bin/backup.php',
            'schedule' => '*/5 * * * *',
            'max_runtime' => 60,
            'stdout' => '/var/log/out.log',
            'stderr' => '/var/log/err.log',
        ], $job->export());
    }

    public function testJsonSerializeIsExport()
    {
        $job = (new Job('backup'))->command('ls');

        self::assertSame(json_encode($job->export()), json_encode($job));
    }

    public function testEmptyNameFallsBackToTheMd5OfTheCommand()
    {
        $job = (new Job(''))->command('ls -la');

        self::assertSame(md5('ls -la'), $job->export()['name']);
    }

    // -------------------------------------------------------------------------
    // Schedule shortcuts
    // -------------------------------------------------------------------------

    public function testEveryBuildsAFiveFieldExpression()
    {
        $job = (new Job('a'))->every(dayWeek: 1, hour: 6, minute: 30);

        self::assertSame('30 6 * * 1', $job->getSchedule());
    }

    public function testMinutlyIsTheEveryMinuteShortcut()
    {
        self::assertSame('* * * * *', (new Job('a'))->schedule('0 0 * * *')->minutly()->getSchedule());
    }

    public function testHourly()
    {
        self::assertSame('0 * * * *', (new Job('a'))->hourly()->getSchedule());
    }

    public function testDailyRunsAtMinuteZeroOfTheGivenHour()
    {
        self::assertSame('0 3 * * *', (new Job('a'))->daily(3)->getSchedule());
    }

    public function testScheduleIsNotValidated()
    {
        self::assertSame('not a cron', (new Job('a'))->schedule('not a cron')->getSchedule());
    }
}
