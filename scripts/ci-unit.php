#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Unit-style checks for security/reliability fixes (no network or Asterisk).
 */

use CapAlert\Config;
use CapAlert\EarthquakeParser;
use CapAlert\GeoMath;
use CapAlert\GpsReader;
use CapAlert\HostHardware;
use CapAlert\SeenLog;
use CapAlert\Shell;
use CapAlert\WildfireParser;

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

$assert(Shell::sanitizeNodeId('1998') === '1998', 'sanitizeNodeId keeps digits');
$assert(Shell::sanitizeNodeId("1998'; rm -rf /") === '1998rmrf', 'sanitizeNodeId strips shell metacharacters');
$assert(Shell::sanitizeSoundKey('../etc/passwd') === 'etcpasswd', 'sanitizeSoundKey strips path separators');
$assert(Shell::sanitizeSoundKey('Rip Current Statement') === 'rip-current-statement', 'sanitizeSoundKey normalizes event names');

$defaults = Config::defaults()['paths'];
$assert(is_array($defaults), 'default paths defined');
$assert(str_starts_with((string) $defaults['linked_flag'], '/var/lib/cap-alert/'), 'linked flag under /var/lib');
$assert(str_starts_with((string) $defaults['alerts_merged'], '/var/lib/cap-alert/'), 'alert cache under /var/lib');
$assert($defaults['playback_audio'] === '/tmp/cap-alert-play.ulaw', 'playback audio stays in /tmp for Asterisk');

$tmpdir = sys_get_temp_dir() . '/cap-alert-unit-' . getmypid();
$local = new Config(['node' => '1', 'lat' => '1', 'lon' => '1', 'paths' => ['state_dir' => $tmpdir, 'alerts_merged' => "$tmpdir/cache/merged.json"]]);
$local->ensureStateDirectories();
$assert(is_dir("$tmpdir/cache"), 'ensureStateDirectories creates cache dir');
@exec('rm -rf ' . escapeshellarg($tmpdir));

$soundSource = file_get_contents(__DIR__ . '/../lib/SoundLibrary.php') ?: '';
$assert(str_contains($soundSource, "'nws' => 'weather_service'"), 'nws alias maps to weather_service');
$assert(str_contains($soundSource, "'national-weather-service' => 'weather_service'"), 'national-weather-service alias maps to weather_service');

$nwsSource = file_get_contents(__DIR__ . '/../lib/NwsClient.php') ?: '';
$assert(!str_contains($nwsSource, 'unlink($cacheFile)'), 'NwsClient does not delete cache on fetch failure');

$pushSource = file_get_contents(__DIR__ . '/../lib/PushoverClient.php') ?: '';
$assert(str_contains($pushSource, 'underDailyLimit'), 'Pushover checks limit before send');
$assert(str_contains($pushSource, 'recordDailySend'), 'Pushover records send after success');

$cycloneSource = file_get_contents(__DIR__ . '/../lib/CycloneMonitor.php') ?: '';
$assert(str_contains($cycloneSource, 'isAlreadySeen'), 'cyclone dedup reads before announce');
$assert(str_contains($cycloneSource, 'SeenLog::append'), 'cyclone marks seen via SeenLog');

$assert(HostHardware::parseThrottledHex('0x50005') === 0x50005, 'parseThrottledHex accepts 0x prefix');
$assert(HostHardware::parseThrottledHex('50005') === 0x50005, 'parseThrottledHex accepts bare hex');
$assert(HostHardware::formatLiveThrottleFlags(0x5) === 'under-voltage-detected_ currently-throttled', 'formatLiveThrottleFlags maps live bits');
$assert(HostHardware::formatLiveThrottleFlags(0x10000) === '', 'formatLiveThrottleFlags ignores sticky-only bits');

$hostSource = file_get_contents(__DIR__ . '/../lib/HostHardware.php') ?: '';
$assert(str_contains($hostSource, 'xgene'), 'HostHardware recognizes X-Gene/APX Mustang hwmon');
$assert(str_contains($hostSource, 'coretemp'), 'HostHardware recognizes x86 coretemp hwmon');
$assert(str_contains($hostSource, 'cooling_device'), 'HostHardware checks thermal cooling state');

$tempSource = file_get_contents(__DIR__ . '/../lib/TempMonitor.php') ?: '';
$assert(!str_contains($tempSource, 'isRaspberryPi'), 'TempMonitor does not gate alarms on Pi only');
$assert(str_contains($tempSource, 'HostHardware::readCpuTemperatureCelsius'), 'TempMonitor uses HostHardware for temperature');

$eqJson = file_get_contents(__DIR__ . '/../test/fixtures/usgs-earthquake.json') ?: '';
$eqEvents = EarthquakeParser::parseCollection($eqJson, 29.42, -95.26);
$assert(count($eqEvents) === 1, 'EarthquakeParser parses fixture');
$assert($eqEvents[0]['event_id'] === 'us7000test01', 'EarthquakeParser reads event id');
$assert($eqEvents[0]['distance_miles'] === 0, 'EarthquakeParser computes distance');
$assert(str_contains(EarthquakeParser::ttsText($eqEvents[0]), 'Earthquake magnitude 4.2'), 'EarthquakeParser builds TTS text');

$wfJson = file_get_contents(__DIR__ . '/../test/fixtures/wfigs-wildfire.json') ?: '';
$wfIncidents = WildfireParser::parseCollection($wfJson, 29.42, -95.26);
$assert(count($wfIncidents) === 1, 'WildfireParser parses fixture');
$assert($wfIncidents[0]['incident_id'] === 'test-fire-1', 'WildfireParser reads incident id');
$assert(!WildfireParser::isPrescribedFire($wfIncidents[0]), 'WildfireParser identifies non-prescribed fire');
$assert(str_contains(WildfireParser::ttsText($wfIncidents[0]), 'Wildfire alert'), 'WildfireParser builds TTS text');

$assert(GeoMath::haversineMiles(29.42, -95.26, 29.42, -95.26) === 0, 'GeoMath haversine same point');
$assert(GeoMath::sanitizeForTts('  HELLO   WORLD  ') === 'Hello World', 'GeoMath sanitizeForTts normalizes case');

$seenFile = sys_get_temp_dir() . '/cap-alert-seen-' . getmypid() . '.log';
@unlink($seenFile);
$assert(!SeenLog::contains($seenFile, 'abc'), 'SeenLog empty file is not seen');
SeenLog::append($seenFile, 'abc');
$assert(SeenLog::contains($seenFile, 'abc'), 'SeenLog append marks key seen');
@unlink($seenFile);

$eqSource = file_get_contents(__DIR__ . '/../lib/EarthquakeMonitor.php') ?: '';
$assert(str_contains($eqSource, 'SeenLog::append'), 'earthquake marks seen after playback');
$wfSource = file_get_contents(__DIR__ . '/../lib/WildfireMonitor.php') ?: '';
$assert(str_contains($wfSource, 'maybeSeedHistory'), 'wildfire seeds history on first enable');
$assert(isset(Config::defaults()['earthquake']), 'earthquake config defaults defined');
$assert(isset(Config::defaults()['wildfire']), 'wildfire config defaults defined');

$audioSource = file_get_contents(__DIR__ . '/../lib/AudioPlayer.php') ?: '';
$assert(!str_contains($audioSource, 'sudo asterisk'), 'AudioPlayer uses asterisk CLI without sudo');
$astSource = file_get_contents(__DIR__ . '/../lib/AsteriskControl.php') ?: '';
$assert(str_contains($astSource, 'Shell::asteriskRx'), 'AsteriskControl uses Shell::asteriskRx');
$serviceSource = file_get_contents(__DIR__ . '/../etc/systemd/system/cap-alert.service') ?: '';
$assert(str_contains($serviceSource, 'User=asterisk'), 'systemd service runs as asterisk');
$capWarnBin = file_get_contents(__DIR__ . '/../bin/cap-alert') ?: '';
$assert(str_contains($capWarnBin, 'sudo -u'), 'cap-alert wrapper drops from root to asterisk');

$gga = '$GPGGA,123519,4807.038,N,01131.000,E,1,08,0.9,545.4,M,46.9,M,,*47';
$ggaFix = GpsReader::decodeGgaLine($gga, 3);
$assert(is_array($ggaFix), 'GpsReader decodes valid GGA sentence');
$assert($ggaFix !== null && abs($ggaFix[0] - 48.1173) < 0.001, 'GpsReader GGA latitude');
$assert($ggaFix !== null && abs($ggaFix[1] - 11.5167) < 0.001, 'GpsReader GGA longitude');
$assert(GpsReader::decodeGgaLine($gga, 9) === null, 'GpsReader rejects low satellite count');

$tpv = [
    'class' => 'TPV',
    'mode' => 3,
    'lat' => 48.117,
    'lon' => 11.517,
    'time' => gmdate('c'),
    'satellites' => 8,
];
$assert(GpsReader::validateGpsdTpv($tpv, 120, 3), 'GpsReader accepts fresh gpsd TPV');
$stale = $tpv;
$stale['time'] = gmdate('c', time() - 600);
$assert(!GpsReader::validateGpsdTpv($stale, 120, 3), 'GpsReader rejects stale gpsd TPV');
$assert(isset(Config::defaults()['gps']['enabled']), 'gps.enabled default defined');
$ownership = file_get_contents(__DIR__ . '/set-cap-alert-ownership.sh') ?: '';
$assert(str_contains($ownership, 'chown "root:$AST_USER" "$ETC_DIR"'), 'ownership script sets /etc/cap-alert group');

$doctorSource = file_get_contents(__DIR__ . '/../lib/Doctor.php') ?: '';
$assert(str_contains($doctorSource, "get('earthquake.enabled') === true"), 'doctor skips disabled earthquake cache check');

if ($failures > 0) {
    fwrite(STDERR, "ci-unit: $failures failure(s)\n");
    exit(1);
}

echo "ci-unit: OK\n";
