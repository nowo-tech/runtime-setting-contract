<?php

declare(strict_types=1);

// Process B of the live-change test: a separate PHP process with its own kernel, same database.

use Nowo\RuntimeSettingContract\Tests\Fixtures\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$dsn = getenv('RS_TEST_DSN');
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('RS_TEST_DSN is not set.');
}

// Application::run() exits with the command's status code (auto-exit is on by default).
(new Application(new TestKernel($dsn, versionTtl: 0, commandEnabled: true)))->run();
