<?php

declare(strict_types=1);

namespace CapAlert;

final class EarthquakeMonitor
{
    private const USGS_EVENT_API = 'https://earthquake.usgs.gov/fdsnws/event/1/query';

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
        if ($this->config->get('earthquake.enabled') !== true) {
            return;
        }

        $cacheFile = $this->config->path('earthquake_cache');
        $this->refreshCache($cacheFile, $scheduled);

        $json = is_file($cacheFile) ? (string) file_get_contents($cacheFile) : '';
        if ($json === '') {
            $this->logger->line('No earthquake data');
            return;
        }

        $events = EarthquakeParser::parseCollection($json, $this->config->lat(), $this->config->lon());
        $this->maybeSeedHistory($events);

        $selected = $this->selectNewEvents($events);
        if ($selected === []) {
            $this->logger->line('No new earthquakes to announce');
            return;
        }

        $cap = (int) $this->config->get('earthquake.max_announcements_per_cycle', 3);
        usort($selected, static fn(array $a, array $b): int => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
        $selected = array_slice($selected, 0, max(1, $cap));

        foreach ($selected as $event) {
            if ($mute) {
                return;
            }
            if ($this->announce($event)) {
                SeenLog::append($this->config->path('earthquake_seen_log'), (string) $event['event_id']);
            }
        }
    }

    /** @param list<array<string, mixed>> $events */
    private function maybeSeedHistory(array $events): void
    {
        if ($this->config->get('debug') === true) {
            return;
        }
        if ($this->config->get('earthquake.announce_history_on_enable') === true) {
            return;
        }

        $flag = $this->config->path('earthquake_history_seeded');
        if (is_file($flag)) {
            return;
        }

        $seenFile = $this->config->path('earthquake_seen_log');
        foreach ($events as $event) {
            if ($this->passesFilters($event)) {
                SeenLog::append($seenFile, (string) $event['event_id']);
            }
        }
        file_put_contents($flag, date('c'));
        $this->logger->line('Seeded earthquake history (existing events will not be announced)');
    }

    /** @param list<array<string, mixed>> $events @return list<array<string, mixed>> */
    private function selectNewEvents(array $events): array
    {
        $selected = [];
        $seenFile = $this->config->path('earthquake_seen_log');
        foreach ($events as $event) {
            if (!$this->passesFilters($event)) {
                continue;
            }
            if (!$this->config->get('debug') && SeenLog::contains($seenFile, (string) $event['event_id'])) {
                $this->logger->line("Skipping earthquake {$event['event_id']} (already announced)");
                continue;
            }
            $selected[] = $event;
            $this->logger->line(sprintf(
                'Earthquake queued: M%.1f %s (%d mi)',
                (float) $event['magnitude'],
                $event['place'],
                (int) $event['distance_miles']
            ));
        }

        return $selected;
    }

    /** @param array<string, mixed> $event */
    private function passesFilters(array $event): bool
    {
        $minMag = (float) $this->config->get('earthquake.min_magnitude', 3.5);
        $maxDistance = (int) $this->config->get('earthquake.max_distance_miles', 75);
        $maxAgeHours = (int) $this->config->get('earthquake.max_event_age_hours', 6);

        if ((float) $event['magnitude'] < $minMag) {
            return false;
        }
        if ((int) $event['distance_miles'] > $maxDistance) {
            return false;
        }
        $ageHours = (time() - (int) $event['time']) / 3600;
        if ($ageHours > $maxAgeHours) {
            return false;
        }

        $ignoreBelow = $this->config->get('earthquake.ignore_automatic_below');
        if ($ignoreBelow !== null && $ignoreBelow !== ''
            && ($event['status'] ?? '') === 'automatic'
            && (float) $event['magnitude'] < (float) $ignoreBelow) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $event */
    private function announce(array $event): bool
    {
        $text = EarthquakeParser::ttsText($event);
        $this->logger->line($text);

        if ($this->config->get('pushover.mode') === 'all') {
            $this->pushover->send($text);
        }

        $ulaw = $this->config->path('earthquake_ulaw');
        $txt = $this->config->path('earthquake_text');
        $this->tts->synthesize($text, $ulaw, $txt, 'USGS');

        if (!is_file($ulaw)) {
            return false;
        }

        $this->sequencer->reset();
        $this->sequencer->add('silence1');
        $this->sequencer->add('earthquake warning');
        $this->sequencer->appendFiles($ulaw);
        $this->queue->enqueue($this->sequencer->paths(), 'earthquake', false);

        return true;
    }

    private function refreshCache(string $cacheFile, bool $scheduled): void
    {
        $cacheMinutes = (int) $this->config->get('earthquake.cache_minutes', 10);
        if (is_file($cacheFile)) {
            $ageMin = (int) floor((time() - filemtime($cacheFile)) / 60);
            if ($ageMin < $cacheMinutes && !$scheduled) {
                $this->logger->line("Using earthquake cache ({$ageMin}m old)");
                return;
            }
        }

        $url = $this->buildQueryUrl();
        $response = $this->http->get($url, ['Accept: application/geo+json']);
        if ($response['ok']) {
            StateFile::write($cacheFile, $response['body']);
            $this->logger->line('Earthquake cache updated');
        } else {
            $this->logger->line('Earthquake fetch failed — using cache if present');
        }
    }

    private function buildQueryUrl(): string
    {
        $lat = $this->config->lat();
        $lon = $this->config->lon();
        $lookbackHours = (int) $this->config->get('earthquake.lookback_hours', 24);
        $maxKm = round((int) $this->config->get('earthquake.max_distance_miles', 75) * 1.60934, 1);
        $start = gmdate('Y-m-d\TH:i:s', time() - ($lookbackHours * 3600));

        $params = http_build_query([
            'format' => 'geojson',
            'latitude' => number_format($lat, 4, '.', ''),
            'longitude' => number_format($lon, 4, '.', ''),
            'maxradiuskm' => (string) $maxKm,
            'minmagnitude' => (string) $this->config->get('earthquake.min_magnitude', 3.5),
            'starttime' => $start,
            'orderby' => 'time',
            'limit' => '100',
        ]);

        return self::USGS_EVENT_API . '?' . $params;
    }
}
