<?php

declare(strict_types=1);

namespace CapAlert;

final class CycloneMonitor
{
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
        if ($this->config->get('cyclone.enabled') !== true) {
            $this->logger->line('Cyclone monitoring disabled');
            return;
        }

        $cacheFile = $this->config->path('cyclone_cache');
        $this->refreshCache($cacheFile, $scheduled);

        $xml = is_file($cacheFile) ? (string) file_get_contents($cacheFile) : '';
        if ($xml === '') {
            $this->logger->line('No cyclone data');
            return;
        }

        $storms = $this->parseXml($xml);
        $active = $this->filterActive($storms);
        if ($this->config->get('cyclone.hurricanes_only') === true) {
            $active = array_values(array_filter($active, fn(array $s): bool => str_contains(strtolower($s['type']), 'hurricane')));
        }

        if ($active === []) {
            $this->logger->line('No active cyclones');
            return;
        }

        $messages = [];
        /** @var list<array<string, string>> $toMarkSeen */
        $toMarkSeen = [];
        foreach ($active as $storm) {
            if (!$this->config->get('debug') && !$this->isCurrent($storm)) {
                $this->logger->line("Skipping {$storm['name']} (stale advisory)");
                continue;
            }

            [$lat, $lon] = $this->parseCenter($storm['center']);
            if ($lat === null || $lon === null) {
                continue;
            }

            $distance = $this->haversine($this->config->lat(), $this->config->lon(), $lat, $lon);
            if ($distance > (int) $this->config->get('cyclone.radius_miles')) {
                $this->logger->line("Skipping {$storm['name']} ({$distance} mi)");
                continue;
            }

            if (!$this->config->get('debug') && $this->isAlreadySeen($storm)) {
                $this->logger->line("Skipping {$storm['name']} (already announced)");
                continue;
            }

            $headline = $this->cleanHeadline($storm['headline']);
            $summary = $this->summary($storm);
            $messages[] = "{$storm['type']} {$storm['name']}. $headline $summary";
            $toMarkSeen[] = $storm;
            $this->logger->line("Cyclone queued: {$storm['name']} ({$distance} mi)");
        }

        $cap = (int) $this->config->get('cyclone.max_announcements_per_cycle', 3);
        $cap = max(1, $cap);
        $messages = array_slice($messages, 0, $cap);
        $toMarkSeen = array_slice($toMarkSeen, 0, count($messages));

        if ($messages === [] || $mute) {
            return;
        }

        $text = implode(' ', $messages);
        $this->logger->line($text);

        if ($this->config->get('pushover.mode') === 'all') {
            $this->pushover->send($text);
        }

        $ulaw = $this->config->path('cyclone_ulaw');
        $txt = $this->config->path('cyclone_text');
        $this->tts->synthesize($text, $ulaw, $txt, 'CYCLONE');

        if (!is_file($ulaw)) {
            return;
        }

        $this->sequencer->reset();
        $this->sequencer->add('silence1');
        $this->sequencer->add('nbc');
        $this->sequencer->appendFiles($ulaw);
        $this->queue->enqueue($this->sequencer->paths(), 'cyclone', false);

        if (!$this->config->get('debug')) {
            foreach ($toMarkSeen as $storm) {
                SeenLog::append($this->config->path('cyclone_seen_log'), $storm['atcf'] . '_' . $storm['datetime']);
            }
        }
    }

    private function refreshCache(string $cacheFile, bool $scheduled): void
    {
        $cacheMinutes = (int) $this->config->get('cyclone.cache_minutes', 60);
        if (is_file($cacheFile)) {
            $ageMin = (int) floor((time() - filemtime($cacheFile)) / 60);
            if ($ageMin < $cacheMinutes && !$scheduled) {
                $this->logger->line("Using cyclone cache ({$ageMin}m old)");
                return;
            }
        }

        $feed = ltrim((string) $this->config->get('cyclone.feed'), '/');
        $url = "https://www.nhc.noaa.gov/$feed";
        $response = $this->http->get($url, ['Accept: application/xml']);
        if ($response['ok']) {
            StateFile::write($cacheFile, $response['body']);
            $this->logger->line('Cyclone cache updated');
        } else {
            $this->logger->line('Cyclone fetch failed — using cache if present');
        }
    }

    /** @return list<array<string, string>> */
    private function parseXml(string $xml): array
    {
        $storms = [];
        $fields = array_fill_keys(['center', 'type', 'name', 'wallet', 'atcf', 'datetime', 'movement', 'pressure', 'wind', 'headline'], '');
        $pubDate = '';
        $inside = false;

        foreach (explode("\n", $xml) as $line) {
            $line = trim($line);
            if (str_contains($line, '<pubDate>')) {
                $pubDate = $this->xmlValue($line);
            }
            if (str_contains($line, '<nhc:Cyclone')) {
                $inside = true;
                continue;
            }
            if (str_contains($line, '</nhc:Cyclone>')) {
                $fields['pubdate'] = $pubDate;
                if (!preg_match('/\b\d{4}\b/', $fields['datetime'])) {
                    $fields['datetime'] .= ' ' . date('Y');
                }
                $storms[] = $fields;
                $fields = array_fill_keys(['center', 'type', 'name', 'wallet', 'atcf', 'datetime', 'movement', 'pressure', 'wind', 'headline'], '');
                $inside = false;
                continue;
            }
            if (!$inside) {
                continue;
            }
            foreach (array_keys($fields) as $key) {
                if (str_contains($line, "<nhc:$key>")) {
                    $fields[$key] = $this->xmlValue($line);
                }
            }
        }

        return $storms;
    }

    /** @param list<array<string, string>> $storms @return list<array<string, string>> */
    private function filterActive(array $storms): array
    {
        $active = [];
        foreach ($storms as $storm) {
            if ((int) $storm['wind'] === 0) {
                continue;
            }
            $headline = strtoupper($storm['headline']);
            $type = strtoupper($storm['type']);
            if (str_contains($headline, 'DISSIPATED') || str_contains($headline, 'POST-TROPICAL') || str_contains($type, 'POST-TROPICAL')) {
                continue;
            }
            $active[] = $storm;
        }
        return $active;
    }

    /** @param array<string, string> $storm */
    private function isCurrent(array $storm): bool
    {
        $maxHours = (int) $this->config->get('cyclone.max_advisory_age_hours', 5);
        $time = strtotime($storm['pubdate'] ?? '');
        if ($time === false) {
            return false;
        }
        return (time() - $time) / 3600 <= $maxHours;
    }

    /** @param array<string, string> $storm */
    private function isAlreadySeen(array $storm): bool
    {
        $key = $storm['atcf'] . '_' . $storm['datetime'];

        return SeenLog::contains($this->config->path('cyclone_seen_log'), $key);
    }

    /** @return array{0: ?float, 1: ?float} */
    private function parseCenter(string $center): array
    {
        $parts = explode(',', $center);
        if (count($parts) !== 2) {
            return [null, null];
        }
        return [(float) trim($parts[0]), (float) trim($parts[1])];
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        $r = 3959;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /** @param array<string, string> $storm */
    private function summary(array $storm): string
    {
        $parts = [];
        if ($storm['center'] !== '') {
            $parts[] = "Located near {$storm['center']}.";
        }
        if ($storm['wind'] !== '') {
            $parts[] = "Wind speed {$storm['wind']}.";
        }
        if ($storm['movement'] !== '') {
            $parts[] = 'Moving ' . $this->expandDirections($storm['movement']) . '.';
        }
        return implode(' ', $parts);
    }

    private function expandDirections(string $text): string
    {
        $map = ['NE' => 'Northeast', 'NW' => 'Northwest', 'SE' => 'Southeast', 'SW' => 'Southwest', 'N' => 'North', 'S' => 'South', 'E' => 'East', 'W' => 'West'];
        foreach ($map as $abbr => $full) {
            $text = preg_replace('/\b' . $abbr . '\b/i', $full, $text) ?? $text;
        }
        return $text;
    }

    private function cleanHeadline(string $headline): string
    {
        $text = trim(str_replace('...', ' ', $headline));
        $text = str_replace(['*', '•', '#', '|', '<', '>'], ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        if (strtoupper($text) === $text) {
            $text = ucwords(strtolower($text));
        }
        if (!preg_match('/[.!?]$/', $text)) {
            $text .= '.';
        }
        return trim($text);
    }

    private function xmlValue(string $line): string
    {
        $start = strpos($line, '>') + 1;
        $end = strrpos($line, '<');
        return trim(substr($line, $start, $end - $start));
    }
}
