#!/usr/bin/env php
<?php

namespace Pebble\Cron;

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    require_once __DIR__ . '/../../../autoload.php';
}

$config = [];
parse_str($argv[1], $config);

$job = new JobRunner($config);
$job->run();
