<?php

use Pebble\Cron\ScheduleChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScheduleTarget
{
    public function nonStatic()
    {
        return true;
    }
}

class ScheduleCheckerTest extends TestCase
{
    private function checker(string $now = '2026-10-06 14:30:45'): ScheduleChecker
    {
        return new ScheduleChecker(new DateTimeImmutable($now));
    }

    // -------------------------------------------------------------------------
    // Cron expressions
    // -------------------------------------------------------------------------

    #[DataProvider('expressionProvider')]
    public function testCronExpressions(string $expression, bool $expected)
    {
        // 2026-10-06 is a Tuesday.
        self::assertSame($expected, $this->checker()->isDue($expression));
    }

    public static function expressionProvider(): array
    {
        return [
            'every minute' => ['* * * * *', true],
            'exact minute' => ['30 14 * * *', true],
            'other minute' => ['31 14 * * *', false],
            'step' => ['*/15 * * * *', true],
            'day of week' => ['30 14 * * 2', true],
            'other day of week' => ['30 14 * * 3', false],
            'macro' => ['@daily', false],
        ];
    }

    public function testInvalidExpressionThrows()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->checker()->isDue('not a cron');
    }

    public function testDefaultNowIsTheCurrentTime()
    {
        self::assertTrue((new ScheduleChecker())->isDue('* * * * *'));
    }

    // -------------------------------------------------------------------------
    // One-shot dates & callables
    // -------------------------------------------------------------------------

    public function testDateTimeScheduleMatchesTheMinuteAndIgnoresSeconds()
    {
        self::assertTrue($this->checker()->isDue('2026-10-06 14:30:00'));
        self::assertFalse($this->checker()->isDue('2026-10-06 14:31:00'));
        self::assertFalse($this->checker()->isDue('2025-10-06 14:30:00'));
    }

    public function testCallableReceivesNow()
    {
        $received = null;
        $due = $this->checker()->isDue(function (DateTimeImmutable $now) use (&$received) {
            $received = $now->format('Y-m-d H:i:s');
            return true;
        });

        self::assertTrue($due);
        self::assertSame('2026-10-06 14:30:45', $received);
    }

    public function testNonStaticArrayCallableTriggersADeprecationThenATypeError()
    {
        $messages = [];
        set_error_handler(function ($errno, $errstr) use (&$messages) {
            $messages[] = $errstr;
            return true;
        }, E_USER_DEPRECATED);

        try {
            $this->checker()->isDue([ScheduleTarget::class, 'nonStatic']);
            self::fail('Expected a TypeError');
        } catch (TypeError $ex) {
            // Not callable, so it falls through to DateTime::createFromFormat().
            self::assertStringContainsString('createFromFormat', $ex->getMessage());
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $messages);
        self::assertStringContainsString('ScheduleTarget::nonStatic', $messages[0]);
    }

    // -------------------------------------------------------------------------
    // Known bugs (see TODO.md)
    // -------------------------------------------------------------------------

    public function testLoadingTheClassEmitsImplicitNullableDeprecations()
    {
        // BUG: `DateTimeImmutable $now = null` and `string &$callable_name = null`
        // are implicitly nullable, deprecated since PHP 8.4: every load of the class
        // emits two E_DEPRECATED.
        $file = escapeshellarg(__DIR__ . '/../src/ScheduleChecker.php');
        $output = shell_exec(escapeshellarg(PHP_BINARY) . " -d error_reporting=-1 -d display_errors=stdout -d log_errors=0 -l $file");

        self::assertSame(2, substr_count($output, 'Implicitly marking parameter'));
        self::assertStringContainsString('$now', $output);
        self::assertStringContainsString('$callable_name', $output);
    }
}
