<?php

declare(strict_types=1);

namespace CapAlert;

final class AlertParser
{
    /** @return list<array<string, mixed>> */
    public function parseFile(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!isset($data['features']) || !is_array($data['features'])) {
            return [];
        }

        $alerts = [];
        foreach ($data['features'] as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $parsed = $this->parseFeature($feature);
            if ($parsed !== null) {
                $alerts[] = $parsed;
            }
        }

        return $alerts;
    }

    /** @param array<string, mixed> $feature @return array<string, mixed>|null */
    public function parseFeature(array $feature): ?array
    {
        $props = $feature['properties'] ?? null;
        if (!is_array($props)) {
            return null;
        }

        $event = trim((string) ($props['event'] ?? ''));
        if ($event === '') {
            return null;
        }

        $id = trim((string) ($feature['id'] ?? $props['id'] ?? ''));
        $geocode = $props['geocode'] ?? [];
        $countyCodes = [];
        if (is_array($geocode)) {
            foreach (['UGC', 'SAME'] as $key) {
                if (!isset($geocode[$key]) || !is_array($geocode[$key])) {
                    continue;
                }
                foreach ($geocode[$key] as $code) {
                    $norm = strtoupper(trim((string) $code));
                    if ($norm !== '') {
                        $countyCodes[] = $norm;
                    }
                }
            }
        }
        $countyCodes = array_values(array_unique($countyCodes));

        return [
            'id' => $id,
            'event' => $event,
            'headline' => (string) ($props['headline'] ?? ''),
            'description' => (string) ($props['description'] ?? ''),
            'instruction' => (string) ($props['instruction'] ?? ''),
            'area_desc' => (string) ($props['areaDesc'] ?? ''),
            'severity' => (string) ($props['severity'] ?? ''),
            'sender_name' => (string) ($props['senderName'] ?? ''),
            'sent' => $this->parseTime($props['sent'] ?? null),
            'effective' => $this->parseTime($props['effective'] ?? null),
            'county_codes' => $countyCodes,
        ];
    }

    /** @param list<array<string, mixed>> $alerts */
    public function serializeEvents(array $alerts): array
    {
        if ($alerts === []) {
            return [
                'events' => '',
                'headlines' => '',
                'descriptions' => '',
                'instructions' => '',
                'event_list' => [],
                'description_list' => [],
                'alert_list' => [],
            ];
        }

        $events = [];
        $headlines = [];
        $descriptions = [];
        $instructions = [];

        foreach ($alerts as $alert) {
            $events[] = (string) $alert['event'];
            $headlines[] = (string) $alert['headline'];
            $descriptions[] = (string) $alert['description'];
            $instructions[] = (string) $alert['instruction'];
        }

        $descJoined = str_replace(["\n", "\r", '*', '#', '~'], ' ', implode('|', $descriptions));

        return [
            'events' => implode(',', $events),
            'headlines' => implode('|', $headlines),
            'descriptions' => trim($descJoined),
            'instructions' => implode('|', $instructions),
            'event_list' => $events,
            'description_list' => $descriptions,
            'alert_list' => $alerts,
        ];
    }

    private function parseTime(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $text = trim($value);
        if (str_ends_with($text, 'Z')) {
            $text = substr($text, 0, -1) . '+00:00';
        }
        $time = strtotime($text);

        return $time === false ? null : $time;
    }
}
