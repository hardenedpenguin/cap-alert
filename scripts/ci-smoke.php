#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Lightweight CI smoke test — no Asterisk or network required.
 */

use CapAlert\Config;

require __DIR__ . '/../lib/bootstrap.php';

$config = Config::load(__DIR__ . '/../config/config.example.php');
$config->sanitizeCoords();

if ($config->get('node') === '') {
    fwrite(STDERR, "Expected example config to define node\n");
    exit(1);
}

if ($config->lat() === 0.0 && $config->lon() === 0.0) {
    fwrite(STDERR, "Expected non-zero example coordinates\n");
    exit(1);
}

echo "ci-smoke: OK\n";
