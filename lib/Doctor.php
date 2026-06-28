<?php

declare(strict_types=1);

namespace CapAlert;

final class Doctor
{
    private int $failures = 0;
    private int $warnings = 0;

    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $http,
        private readonly AsteriskControl $asterisk,
        private readonly Logger $logger,
        private readonly GpsReader $gps,
    ) {
    }

    public function run(bool $fix = false): int
    {
        $this->ok('Config loaded for node ' . $this->config->get('node'));
        $this->checkCoords();
        $this->checkConfigReadable();
        $this->checkStateDirs($fix);
        $this->checkAsterisk();
        $this->checkTts();
        $this->checkNws();
        $this->checkCaches();
        $this->checkTimer();
        $this->checkGps();

        $this->logger->line(sprintf('Doctor complete: %d failure(s), %d warning(s)', $this->failures, $this->warnings));

        return $this->failures > 0 ? 1 : 0;
    }

    private function checkCoords(): void
    {
        $lat = $this->config->lat();
        $lon = $this->config->lon();
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 || ($lat === 0.0 && $lon === 0.0)) {
            $this->warn('Coordinates look invalid or unset');
            return;
        }
        $this->ok("Static coordinates $lat, $lon");
    }

    private function checkConfigReadable(): void
    {
        $configFile = getenv('CAP_ALERT_CONFIG') ?: '/etc/cap-alert/config.php';
        $dir = dirname($configFile);
        if (!is_dir($dir)) {
            $this->fail("Config directory missing: $dir");
            return;
        }
        if (!is_readable($dir)) {
            $this->fail("Config directory not readable: $dir (expected root:asterisk mode 750)");
            return;
        }
        if (!is_readable($configFile)) {
            $this->fail("Config not readable: $configFile (expected root:asterisk mode 640)");
            return;
        }
        $this->ok('Config readable');
    }

    private function checkStateDirs(bool $fix): void
    {
        try {
            if ($fix) {
                $this->config->ensureStateDirectories();
            }
            $state = $this->config->path('state_dir');
            if (!is_dir($state)) {
                $this->fail("State directory missing: $state");
                return;
            }
            if (!is_writable($state)) {
                $this->fail("State directory not writable: $state");
                return;
            }
            $this->ok("State directory writable: $state");
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }

    private function checkAsterisk(): void
    {
        if ($this->asterisk->ping()) {
            $this->ok('Asterisk responds');
            return;
        }
        $this->fail('Asterisk not reachable (asterisk -rx)');
    }

    private function checkTts(): void
    {
        if ((string) $this->config->get('tts_key') !== '') {
            $this->ok('VoiceRSS key configured');
        } elseif (Shell::commandExists('asl-tts')) {
            $this->ok('asl-tts available');
        } elseif (Shell::commandExists('espeak-ng')) {
            $this->ok('espeak-ng available');
        } else {
            $this->fail('No TTS backend available');
        }
    }

    private function checkNws(): void
    {
        $url = sprintf(
            'https://api.weather.gov/points/%s,%s',
            $this->config->lat(),
            $this->config->lon()
        );
        $response = $this->http->get($url, ['Accept: application/geo+json']);
        if ($response['ok']) {
            $this->ok('NWS points API reachable');
            return;
        }
        $this->warn("NWS points API HTTP {$response['code']} {$response['error']}");
    }

    private function checkCaches(): void
    {
        $caches = [
            'alerts_merged' => ['label' => 'NWS alerts', 'enabled' => true],
            'cyclone_cache' => ['label' => 'cyclone', 'enabled' => $this->config->get('cyclone.enabled') === true],
            'earthquake_cache' => ['label' => 'earthquake', 'enabled' => $this->config->get('earthquake.enabled') === true],
            'wildfire_cache' => ['label' => 'wildfire', 'enabled' => $this->config->get('wildfire.enabled') === true],
        ];

        foreach ($caches as $key => $meta) {
            if (!$meta['enabled']) {
                continue;
            }
            try {
                $path = $this->config->path($key);
            } catch (\Throwable) {
                continue;
            }
            $age = StateFile::ageMinutes($path);
            if ($age === null) {
                $this->warn("{$meta['label']} cache missing");
                continue;
            }
            $this->ok("{$meta['label']} cache age {$age}m");
        }
    }

    private function checkTimer(): void
    {
        $status = trim((string) shell_exec('systemctl is-active cap-alert.timer 2>/dev/null'));
        if ($status === 'active') {
            $this->ok('cap-alert.timer active');
            return;
        }
        $this->warn('cap-alert.timer not active');
    }

    private function checkGps(): void
    {
        if (!$this->gps->enabled()) {
            $this->ok('GPS disabled (static coordinates only)');
            return;
        }

        $device = trim((string) $this->config->get('gps.device', ''));
        $gpsd = trim((string) shell_exec('pgrep -x gpsd 2>/dev/null'));
        if ($gpsd !== '') {
            if (Shell::commandExists('gpspipe')) {
                $this->ok('gpsd running');
            } else {
                $this->fail('gpsd running but gpspipe missing (install gpsd-clients)');
            }
        } elseif ($device !== '') {
            if (!is_readable($device)) {
                $this->fail("GPS device not readable: $device");
            } else {
                $this->ok("GPS device readable: $device");
            }
        } else {
            $this->warn('GPS enabled but gpsd not running (will try serial auto-detect)');
        }

        $fix = $this->gps->read();
        if ($fix !== null) {
            $this->ok(sprintf('GPS fix %.4f, %.4f', $fix[0], $fix[1]));
            return;
        }

        $this->warn('GPS enabled but no valid fix (static coordinates used this run)');
    }

    private function ok(string $message): void
    {
        $this->logger->line("ok: $message");
    }

    private function warn(string $message): void
    {
        $this->warnings++;
        $this->logger->line("warn: $message");
    }

    private function fail(string $message): void
    {
        $this->failures++;
        $this->logger->line("FAIL: $message");
    }
}
