<?php

use Pebble\Cron\Helper;
use PHPUnit\Framework\TestCase;

class HelperTest extends TestCase
{
    // -------------------------------------------------------------------------
    // escape
    // -------------------------------------------------------------------------

    public function testEscapeBuildsAFileName()
    {
        self::assertSame('my_app_backup', Helper::escape('My App  Backup'));
        self::assertSame('foo_bar..x', Helper::escape('Foo  Bar/../x'));
    }

    public function testEscapeCanReturnAnEmptyString()
    {
        self::assertSame('', Helper::escape('!!!'));
        self::assertSame('t', Helper::escape('Été'));
    }

    // -------------------------------------------------------------------------
    // Environment
    // -------------------------------------------------------------------------

    public function testGetTempDirIsSysGetTempDir()
    {
        self::assertSame(sys_get_temp_dir(), Helper::getTempDir());
    }

    public function testGetPhpBinaryFindsAnExecutable()
    {
        self::assertTrue(is_executable(Helper::getPhpBinary()));
    }
}
