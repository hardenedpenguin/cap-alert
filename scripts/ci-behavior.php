#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Behavioral tests for alert parsing, filtering, and dedup.
 */

use CapAlert\AlertDedup;
use CapAlert\AlertFilter;
use CapAlert\AlertParser;
use CapAlert\Config;
use CapAlert\NwsClient;
use CapAlert\QuietHours;

require __DIR__ . '/../lib/bootstrap.php';

$failures = 0;

$assert = static function (bool $ok, string $message) use (&$failures): void {
    if ($ok) {
        echo "ok: $message\n";
        return;
    }
    fwrite(STDERR, "FAIL: $message\n");
    $failures++;
};

$parser = new AlertParser();
$fixture = __DIR__ . '/../test/fixtures/alert.json';
$alerts = $parser->parseFile($fixture);
$assert($alerts !== [], 'AlertParser loads fixture');
$assert(isset($alerts[0]['county_codes']) && $alerts[0]['county_codes'] !== [], 'AlertParser extracts county codes');

$serialized = $parser->serializeEvents($alerts);
$assert(str_contains((string) $serialized['events'], 'Test Watch'), 'AlertParser serializes events');

$blockedConfig = new Config([
    'node' => '1',
    'lat' => '1',
    'lon' => '1',
    'filtering' => ['blocked_events' => ['*Watch*']],
]);
$filtered = AlertFilter::filterBlocked($alerts, $blockedConfig);
$assert($filtered === [], 'AlertFilter blocks glob match');

$quietConfig = new Config([
    'node' => '1',
    'lat' => '1',
    'lon' => '1',
    'quiet_hours' => true,
    'quiet_hours_window' => ['start' => '00:00', 'end' => '23:59', 'allow_severe' => true],
]);
$qh = new QuietHours($quietConfig);
$assert($qh->isActive(), 'QuietHours detects active window');
$severe = ['event' => 'Tornado Warning', 'severity' => 'Extreme'];
$assert($qh->shouldMuteAlert($severe) === false, 'QuietHours allow_severe bypasses warnings');

$tmpdir = sys_get_temp_dir() . '/cap-alert-merge-' . getmypid();
@mkdir($tmpdir);
$point = "$tmpdir/point.json";
$zone = "$tmpdir/zone.json";
$merged = "$tmpdir/merged.json";
file_put_contents($point, json_encode([
    'type' => 'FeatureCollection',
    'features' => [['id' => 'a1', 'properties' => ['event' => 'Test']]],
]));
file_put_contents($zone, json_encode([
    'type' => 'FeatureCollection',
    'features' => [['id' => 'a1', 'properties' => ['event' => 'Test']], ['id' => 'a2', 'properties' => ['event' => 'Other']]],
]));
NwsClient::mergeAlertFiles($point, $zone, $merged);
$mergedAlerts = $parser->parseFile($merged);
$assert(count($mergedAlerts) === 2, 'NwsClient merge deduplicates by id');
@exec('rm -rf ' . escapeshellarg($tmpdir));

$sigA = AlertDedup::signature(['event' => 'Flood Advisory', 'county_codes' => ['TXZ001']]);
$sigB = AlertDedup::signature(['event' => 'Flood Advisory', 'county_codes' => ['TXZ001']]);
$assert($sigA === $sigB, 'AlertDedup stable signatures');

if ($failures > 0) {
    fwrite(STDERR, "ci-behavior: $failures failure(s)\n");
    exit(1);
}

echo "ci-behavior: OK\n";
