<?php

namespace Pebble\Cron;

use Symfony\Component\Process\PhpExecutableFinder;

class Helper
{
    /**
     * @param string $input
     * @return string
     */
    public static function escape($input): string
    {
        $input = strtolower($input);
        $input = preg_replace('/[^a-z0-9_. -]+/', '', $input);
        $input = trim($input);
        $input = str_replace(' ', '_', $input);
        $input = preg_replace('/_{2,}/', '_', $input);

        return $input;
    }

    /**
     * @return string
     */
    public static function getPhpBinary(): string
    {
        $executableFinder = new PhpExecutableFinder();
        return $executableFinder->find() ?: '';
    }

    /**
     * @return string
     */
    public static function getTempDir(): string
    {
        if (function_exists('sys_get_temp_dir')) {
            $tmp = sys_get_temp_dir();
        } elseif (!empty($_SERVER['TMP'])) {
            $tmp = $_SERVER['TMP'];
        } elseif (!empty($_SERVER['TEMP'])) {
            $tmp = $_SERVER['TEMP'];
        } elseif (!empty($_SERVER['TMPDIR'])) {
            $tmp = $_SERVER['TMPDIR'];
        } else {
            $tmp = getcwd();
        }

        return $tmp;
    }
}
