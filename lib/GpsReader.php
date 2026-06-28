<?php

declare(strict_types=1);

namespace CapAlert;

final class GpsReader
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function enabled(): bool
    {
        return $this->config->get('gps.enabled') === true;
    }

    /** @return array{0: float, 1: float}|null */
    public function read(): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $maxAge = max(0, (int) $this->config->get('gps.max_age_seconds', 120));
        $minSats = max(1, (int) $this->config->get('gps.min_satellites', 3));
        $device = trim((string) $this->config->get('gps.device', ''));

        if ($this->gpsdRunning()) {
            $fix = $this->readGpsd($maxAge, $minSats);
            if (is_array($fix)) {
                return $fix;
            }
        } elseif ($device === '' && !Shell::commandExists('gpspipe')) {
            $this->logger->line('GPS: gpsd not running and gpspipe unavailable');
        }

        if ($device !== '') {
            $fix = $this->readNmea($device, $minSats);
            if (is_array($fix)) {
                $this->logger->line("GPS via $device");
                return $fix;
            }
            $this->logger->line("GPS: no fix from configured device $device");
            return null;
        }

        foreach ($this->deviceCandidates() as $candidate) {
            $fix = $this->readNmea($candidate, $minSats);
            if (is_array($fix)) {
                $this->logger->line("GPS via $candidate");
                return $fix;
            }
        }

        return null;
    }

    /** @return array{0: float, 1: float}|null */
    public static function decodeGgaLine(string $line, int $minSats = 3): ?array
    {
        $line = trim($line);
        if ($line === '' || !str_contains($line, 'GGA')) {
            return null;
        }

        $parts = explode(',', $line);
        $fix = isset($parts[6]) && is_numeric($parts[6]) ? (int) $parts[6] : 0;
        $sats = isset($parts[7]) && is_numeric($parts[7]) ? (int) $parts[7] : 0;
        if ($fix === 0 || $sats < max(1, $minSats)) {
            return null;
        }

        return self::decodeGgaParts($parts);
    }

    /** @param array<string, mixed> $json */
    public static function validateGpsdTpv(array $json, int $maxAge, int $minSats): bool
    {
        $mode = (int) ($json['mode'] ?? 0);
        if ($mode < 2) {
            return false;
        }

        $sats = (int) ($json['satellites'] ?? $json['nSat'] ?? 0);
        if ($sats > 0 && $sats < max(1, $minSats)) {
            return false;
        }

        if ($maxAge <= 0) {
            return true;
        }

        $timestamp = $json['time'] ?? null;
        if (!is_string($timestamp) || $timestamp === '') {
            return true;
        }
        $fixTime = strtotime($timestamp);

        return $fixTime !== false && (time() - $fixTime) <= $maxAge;
    }

    /** @return array{0: float, 1: float}|null */
    private function readGpsd(int $maxAge, int $minSats): ?array
    {
        if (!Shell::commandExists('gpspipe')) {
            $this->logger->line('GPS: gpspipe not installed (install gpsd-clients)');
            return null;
        }

        $line = shell_exec('gpspipe -w -n 5 2>/dev/null | grep TPV | head -n 1');
        $json = json_decode((string) $line, true);
        if (!is_array($json) || empty($json['lat']) || empty($json['lon'])) {
            return null;
        }
        if (!self::validateGpsdTpv($json, $maxAge, $minSats)) {
            $this->logger->line('GPS fix rejected (stale or low quality)');
            return null;
        }

        $this->logger->line('GPS via gpsd');
        return [(float) $json['lat'], (float) $json['lon']];
    }

    private function gpsdRunning(): bool
    {
        return trim((string) shell_exec('pgrep -x gpsd 2>/dev/null')) !== '';
    }

    /** @return list<string> */
    private function deviceCandidates(): array
    {
        $devices = [];
        foreach (['/dev/serial/by-id/*', '/dev/ttyACM*', '/dev/ttyUSB*'] as $pattern) {
            foreach (glob($pattern) ?: [] as $path) {
                $devices[] = $path;
            }
        }

        return $devices;
    }

    /** @return array{0: float, 1: float}|null */
    private function readNmea(string $device, int $minSats): ?array
    {
        if (!preg_match('#^/dev/[a-zA-Z0-9_./-]+$#', $device) || !is_readable($device)) {
            return null;
        }

        $line = shell_exec('timeout 1 cat ' . escapeshellarg($device) . ' 2>/dev/null | grep GGA | head -n 1');

        return self::decodeGgaLine((string) $line, $minSats);
    }

    /** @param list<string> $parts @return array{0: float, 1: float}|null */
    private static function decodeGgaParts(array $parts): ?array
    {
        if (count($parts) < 6) {
            return null;
        }
        $latRaw = $parts[2];
        $lonRaw = $parts[4];
        if (!is_numeric($latRaw) || !is_numeric($lonRaw)) {
            return null;
        }

        $lat = floor((float) $latRaw / 100) + fmod((float) $latRaw, 100) / 60;
        $lon = floor((float) $lonRaw / 100) + fmod((float) $lonRaw, 100) / 60;
        if (($parts[3] ?? '') === 'S') {
            $lat = -$lat;
        }
        if (($parts[5] ?? '') === 'W') {
            $lon = -$lon;
        }

        return [round($lat, 4), round($lon, 4)];
    }
}
