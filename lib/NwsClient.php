<?php

declare(strict_types=1);

namespace CapAlert;

final class NwsClient
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $http,
        private readonly Logger $logger,
    ) {
    }

    public function fetchPointAlerts(string $cacheFile): bool
    {
        $url = sprintf(
            'https://api.weather.gov/alerts/active?point=%s,%s',
            $this->config->lat(),
            $this->config->lon()
        );
        return $this->fetchToFile($url, $cacheFile, ['Accept: application/geo+json']);
    }

    /** @return array{forecast: string, county: string}|null */
    public function fetchZones(string $debugFile): ?array
    {
        $url = sprintf(
            'https://api.weather.gov/points/%s,%s',
            $this->config->lat(),
            $this->config->lon()
        );
        $response = $this->http->get($url, ['Accept: application/geo+json']);
        file_put_contents($debugFile, $response['body']);

        if (!$response['ok']) {
            $this->logger->log("NWS points fetch failed HTTP {$response['code']} {$response['error']}");
            $this->logger->line("Points API error (HTTP {$response['code']})");
            return null;
        }

        $json = json_decode($response['body'], true);
        $props = $json['properties'] ?? null;
        if (!is_array($props)) {
            $this->logger->line('Invalid points API response');
            return null;
        }

        $forecast = isset($props['forecastZone']) ? basename((string) $props['forecastZone']) : '';
        $county = isset($props['county']) ? basename((string) $props['county']) : '';
        if ($forecast === '' || $county === '') {
            $this->logger->line('Missing forecast or county zone');
            return null;
        }

        return ['forecast' => $forecast, 'county' => $county];
    }

    public function fetchZoneAlerts(string $zone1, string $zone2, string $cacheFile): void
    {
        $features = [];
        foreach ([$zone1, $zone2] as $zone) {
            $url = "https://api.weather.gov/alerts/active?zone=$zone";
            $response = $this->http->get($url, ['Accept: application/geo+json']);
            if (!$response['ok']) {
                $this->logger->log("NWS zone fetch failed for $zone HTTP {$response['code']}");
                continue;
            }
            $json = json_decode($response['body'], true);
            if (isset($json['features']) && is_array($json['features'])) {
                $features = array_merge($features, $json['features']);
            }
        }

        $unique = [];
        foreach ($features as $alert) {
            if (isset($alert['id'])) {
                $unique[$alert['id']] = $alert;
            }
        }

        file_put_contents($cacheFile, json_encode([
            'type' => 'FeatureCollection',
            'features' => array_values($unique),
        ], JSON_PRETTY_PRINT));
    }

    public static function mergeAlertFiles(string $pointFile, string $zoneFile, string $mergedFile): void
    {
        $read = static fn(string $file): array => is_file($file)
            ? (json_decode((string) file_get_contents($file), true)['features'] ?? [])
            : [];

        $combined = array_merge($read($pointFile), $read($zoneFile));
        $unique = [];
        foreach ($combined as $alert) {
            if (isset($alert['id'])) {
                $unique[$alert['id']] = $alert;
            }
        }

        StateFile::writeJson($mergedFile, [
            'type' => 'FeatureCollection',
            'features' => array_values($unique),
        ]);
    }

    /** @param list<string> $headers */
    private function fetchToFile(string $url, string $cacheFile, array $headers): bool
    {
        $response = $this->http->get($url, $headers);
        if (!$response['ok']) {
            $this->logger->log("NWS alert fetch failed HTTP {$response['code']} $url");
            if (is_file($cacheFile)) {
                $this->logger->line("Alert fetch error (HTTP {$response['code']}) — using cached alerts");
            } else {
                $this->logger->line("Alert fetch error (HTTP {$response['code']}) — no cache available");
            }
            return false;
        }
        StateFile::write($cacheFile, $response['body']);
        return true;
    }
}
