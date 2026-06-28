<?php

declare(strict_types=1);

namespace CapAlert;

final class WildfireMonitor
{
    private const WFIGS_QUERY = 'https://services3.arcgis.com/T4QMspbfLg3qTGWY/arcgis/rest/services/WFIGS_Interagency_Perimeters_Current/FeatureServer/0/query';

    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $http,
        private readonly Logger $logger,
        private readonly TtsEngine $tts,
        private readonly SoundSequencer $sequencer,
        private readonly PlaybackQueue $queue,
        private readonly PushoverClient $pushover,
    ) {
    }

    public function run(bool $scheduled, bool $mute): void
    {
        if ($this->config->get('wildfire.enabled') !== true) {
            return;
        }

        $cacheFile = $this->config->path('wildfire_cache');
        $this->refreshCache($cacheFile, $scheduled);

        $json = is_file($cacheFile) ? (string) file_get_contents($cacheFile) : '';
        if ($json === '') {
            $this->logger->line('No wildfire data');
            return;
        }

        $incidents = WildfireParser::parseCollection($json, $this->config->lat(), $this->config->lon());
        $this->maybeSeedHistory($incidents);

        $selected = $this->selectNewIncidents($incidents);
        if ($selected === []) {
            $this->logger->line('No new wildfires to announce');
            return;
        }

        $cap = (int) $this->config->get('wildfire.max_announcements_per_cycle', 3);
        usort($selected, static fn(array $a, array $b): int => ((float) ($b['acres'] ?? 0)) <=> ((float) ($a['acres'] ?? 0)));
        $selected = array_slice($selected, 0, max(1, $cap));

        foreach ($selected as $incident) {
            if ($mute) {
                return;
            }
            if ($this->announce($incident)) {
                SeenLog::append($this->config->path('wildfire_seen_log'), (string) $incident['incident_id']);
            }
        }
    }

    /** @param list<array<string, mixed>> $incidents */
    private function maybeSeedHistory(array $incidents): void
    {
        if ($this->config->get('debug') === true) {
            return;
        }
        if ($this->config->get('wildfire.announce_history_on_enable') === true) {
            return;
        }

        $flag = $this->config->path('wildfire_history_seeded');
        if (is_file($flag)) {
            return;
        }

        $seenFile = $this->config->path('wildfire_seen_log');
        foreach ($incidents as $incident) {
            if ($this->passesFilters($incident)) {
                SeenLog::append($seenFile, (string) $incident['incident_id']);
            }
        }
        file_put_contents($flag, date('c'));
        $this->logger->line('Seeded wildfire history (existing incidents will not be announced)');
    }

    /** @param list<array<string, mixed>> $incidents @return list<array<string, mixed>> */
    private function selectNewIncidents(array $incidents): array
    {
        $selected = [];
        $seenFile = $this->config->path('wildfire_seen_log');
        foreach ($incidents as $incident) {
            if (!$this->passesFilters($incident)) {
                continue;
            }
            if (!$this->config->get('debug') && SeenLog::contains($seenFile, (string) $incident['incident_id'])) {
                $this->logger->line("Skipping wildfire {$incident['incident_id']} (already announced)");
                continue;
            }
            $selected[] = $incident;
            $this->logger->line(sprintf(
                'Wildfire queued: %s (%.0f ac, %d mi)',
                $incident['name'],
                (float) $incident['acres'],
                (int) $incident['distance_miles']
            ));
        }

        return $selected;
    }

    /** @param array<string, mixed> $incident */
    private function passesFilters(array $incident): bool
    {
        $minAcres = (float) $this->config->get('wildfire.min_acres', 250);
        $maxDistance = (int) $this->config->get('wildfire.max_distance_miles', 50);
        $maxAgeHours = (int) $this->config->get('wildfire.max_discovery_age_hours', 48);

        if ((float) $incident['acres'] < $minAcres) {
            return false;
        }
        if ((int) $incident['distance_miles'] > $maxDistance) {
            return false;
        }
        if ($this->config->get('wildfire.exclude_prescribed') === true && WildfireParser::isPrescribedFire($incident)) {
            return false;
        }

        $discovery = $incident['discovery_time'] ?? null;
        if ($discovery !== null) {
            $ageHours = (time() - (int) $discovery) / 3600;
            if ($ageHours > $maxAgeHours) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $incident */
    private function announce(array $incident): bool
    {
        $text = WildfireParser::ttsText($incident);
        $this->logger->line($text);

        if ($this->config->get('pushover.mode') === 'all') {
            $this->pushover->send($text);
        }

        $ulaw = $this->config->path('wildfire_ulaw');
        $txt = $this->config->path('wildfire_text');
        $this->tts->synthesize($text, $ulaw, $txt, 'WILDFIRE');

        if (!is_file($ulaw)) {
            return false;
        }

        $this->sequencer->reset();
        $this->sequencer->add('silence1');
        $this->sequencer->add('fire warning');
        $this->sequencer->appendFiles($ulaw);
        $this->queue->enqueue($this->sequencer->paths(), 'wildfire', false);

        return true;
    }

    private function refreshCache(string $cacheFile, bool $scheduled): void
    {
        $cacheMinutes = (int) $this->config->get('wildfire.cache_minutes', 15);
        if (is_file($cacheFile)) {
            $ageMin = (int) floor((time() - filemtime($cacheFile)) / 60);
            if ($ageMin < $cacheMinutes && !$scheduled) {
                $this->logger->line("Using wildfire cache ({$ageMin}m old)");
                return;
            }
        }

        $url = $this->buildQueryUrl();
        $response = $this->http->get($url, ['Accept: application/geo+json']);
        if ($response['ok']) {
            StateFile::write($cacheFile, $response['body']);
            $this->logger->line('Wildfire cache updated');
        } else {
            $this->logger->line('Wildfire fetch failed — using cache if present');
        }
    }

    private function buildQueryUrl(): string
    {
        $lat = $this->config->lat();
        $lon = $this->config->lon();
        $distanceKm = round((int) $this->config->get('wildfire.max_distance_miles', 50) * 1.60934, 1);

        $params = http_build_query([
            'f' => 'geojson',
            'where' => '1=1',
            'geometry' => number_format($lon, 6, '.', '') . ',' . number_format($lat, 6, '.', ''),
            'geometryType' => 'esriGeometryPoint',
            'inSR' => '4326',
            'spatialRel' => 'esriSpatialRelIntersects',
            'distance' => (string) $distanceKm,
            'units' => 'esriSRUnit_Kilometer',
            'returnGeometry' => 'true',
            'outFields' => implode(',', [
                'poly_IncidentName',
                'poly_GISAcres',
                'attr_PercentContained',
                'attr_FireDiscoveryDateTime',
                'poly_FeatureCategory',
                'poly_IrwinID',
                'attr_IncidentTypeKind',
            ]),
            'resultRecordCount' => '200',
        ]);

        return self::WFIGS_QUERY . '?' . $params;
    }
}
