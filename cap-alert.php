#!/usr/bin/env php
<?php

declare(strict_types=1);

use CapAlert\Application;
use CapAlert\Config;

require __DIR__ . '/lib/bootstrap.php';

$mode = $argv[1] ?? null;
$scheduled = in_array($mode, ['run', 'scheduled', 'cron'], true);
$configFile = getenv('CAP_ALERT_CONFIG') ?: '/etc/cap-alert/config.php';

$zone = trim((string) shell_exec('timedatectl show -p Timezone --value'));
if ($zone !== '') {
    date_default_timezone_set($zone);
}

$config = Config::load($configFile);
$config->sanitizeCoords();
$app = new Application($config);

if ($mode === 'doctor') {
    $fix = in_array('--fix', $argv, true);
    exit($app->doctor($fix));
}

$app->run($scheduled);
