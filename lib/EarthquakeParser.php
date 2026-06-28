<?php

declare(strict_types=1);

namespace CapAlert;

final class EarthquakeParser
{
    /**
     * @return list<array{
     *   event_id: string,
     *   magnitude: float,
     *   place: string,
     *   lat: float,
     *   lon: float,
     *   depth_km: float,
     *   time: int,
     *   status: string,
     *   tsunami: bool,
     *   distance_miles: int
     * }>
     */
    public static function parseCollection(string $json, float $originLat, float $originLon): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['features']) || !is_array($data['features'])) {
            return [];
        }

        $events = [];
        foreach ($data['features'] as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $parsed = self::parseFeature($feature, $originLat, $originLon);
            if ($parsed !== null) {
                $events[] = $parsed;
            }
        }

        return $events;
    }

    /** @param array<string, mixed> $feature */
    public static function parseFeature(array $feature, float $originLat, float $originLon): ?array
    {
        $eventId = trim((string) ($feature['id'] ?? ''));
        if ($eventId === '') {
            return null;
        }

        $props = $feature['properties'] ?? null;
        $geom = $feature['geometry'] ?? null;
        if (!is_array($props) || !is_array($geom)) {
            return null;
        }

        $coords = $geom['coordinates'] ?? null;
        if (!is_array($coords) || count($coords) < 2) {
            return null;
        }

        $lon = (float) $coords[0];
        $lat = (float) $coords[1];
        $depthKm = isset($coords[2]) ? (float) $coords[2] : 0.0;
        if (!isset($props['mag']) || !is_numeric($props['mag'])) {
            return null;
        }

        $timeMs = $props['time'] ?? null;
        if (!is_numeric($timeMs)) {
            return null;
        }

        $time = (int) floor(((float) $timeMs) / 1000);
        $place = trim((string) ($props['place'] ?? $props['title'] ?? 'unknown location'));
        if ($place === '') {
            $place = 'unknown location';
        }

        return [
            'event_id' => $eventId,
            'magnitude' => (float) $props['mag'],
            'place' => $place,
            'lat' => $lat,
            'lon' => $lon,
            'depth_km' => $depthKm,
            'time' => $time,
            'status' => strtolower(trim((string) ($props['status'] ?? ''))),
            'tsunami' => (int) ($props['tsunami'] ?? 0) === 1,
            'distance_miles' => GeoMath::haversineMiles($originLat, $originLon, $lat, $lon),
        ];
    }

    /** @param array<string, mixed> $event */
    public static function ttsText(array $event): string
    {
        $mag = (float) $event['magnitude'];
        $magStr = $mag < 10 ? number_format($mag, 1, '.', '') : (string) (int) round($mag);
        $depthPart = '';
        $depthKm = (float) ($event['depth_km'] ?? 0);
        if ($depthKm >= 1) {
            $depthPart = ', depth ' . (int) round($depthKm) . ' kilometers';
        }
        $tsunamiPart = !empty($event['tsunami']) ? ' Tsunami information is available for this event.' : '';

        return trim(sprintf(
            'Earthquake magnitude %s, %d miles from your position, %s%s.%s',
            $magStr,
            (int) $event['distance_miles'],
            GeoMath::sanitizeForTts((string) $event['place']),
            $depthPart,
            $tsunamiPart
        ));
    }
}
