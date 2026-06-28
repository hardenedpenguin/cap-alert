<?php

declare(strict_types=1);

namespace CapAlert;

final class WildfireParser
{
    /** @return list<array<string, mixed>> */
    public static function parseCollection(string $json, float $originLat, float $originLon): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['features']) || !is_array($data['features'])) {
            return [];
        }

        $incidents = [];
        foreach ($data['features'] as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $parsed = self::parseFeature($feature, $originLat, $originLon);
            if ($parsed !== null) {
                $incidents[] = $parsed;
            }
        }

        return $incidents;
    }

    /** @param array<string, mixed> $feature @return array<string, mixed>|null */
    public static function parseFeature(array $feature, float $originLat, float $originLon): ?array
    {
        $props = $feature['properties'] ?? null;
        $geom = $feature['geometry'] ?? null;
        if (!is_array($props) || !is_array($geom)) {
            return null;
        }

        $centroid = self::geometryCentroid($geom);
        if ($centroid === null) {
            return null;
        }
        [$lat, $lon] = $centroid;

        $acres = (float) ($props['poly_GISAcres'] ?? $props['GISAcres'] ?? 0);
        $name = trim((string) (
            $props['poly_IncidentName']
            ?? $props['IncidentName']
            ?? $props['poly_IncidentNameShort']
            ?? 'Unknown fire'
        ));
        $incidentId = trim((string) (
            $props['poly_IrwinID']
            ?? $props['IrwinID']
            ?? $feature['id']
            ?? $name
        ));
        if ($incidentId === '') {
            return null;
        }

        $typeKind = strtolower(trim((string) ($props['attr_IncidentTypeKind'] ?? $props['IncidentTypeKind'] ?? '')));
        $featureCategory = strtolower(trim((string) ($props['poly_FeatureCategory'] ?? $props['FeatureCategory'] ?? '')));

        $percentRaw = $props['attr_PercentContained'] ?? $props['PercentContained'] ?? null;
        $percentContained = null;
        if ($percentRaw !== null && trim((string) $percentRaw) !== '' && is_numeric($percentRaw)) {
            $percentContained = (int) round((float) $percentRaw);
        }

        $discoveryTime = self::parseDiscoveryTime(
            $props['attr_FireDiscoveryDateTime'] ?? $props['FireDiscoveryDateTime'] ?? null
        );

        return [
            'incident_id' => $incidentId,
            'name' => $name !== '' ? $name : 'Unknown fire',
            'acres' => $acres,
            'percent_contained' => $percentContained,
            'discovery_time' => $discoveryTime,
            'incident_type_kind' => $typeKind,
            'feature_category' => $featureCategory,
            'lat' => $lat,
            'lon' => $lon,
            'distance_miles' => GeoMath::haversineMiles($originLat, $originLon, $lat, $lon),
        ];
    }

    public static function isPrescribedFire(array $incident): bool
    {
        $kind = (string) ($incident['incident_type_kind'] ?? '');
        if (in_array($kind, ['rx', 'prescribed'], true)) {
            return true;
        }

        return str_contains((string) ($incident['feature_category'] ?? ''), 'prescribed');
    }

    /** @param array<string, mixed> $incident */
    public static function ttsText(array $incident): string
    {
        $acres = (float) ($incident['acres'] ?? 0);
        $acresStr = $acres >= 100 ? number_format((int) round($acres)) : (string) (int) round($acres);
        $containedPart = '';
        if (isset($incident['percent_contained']) && $incident['percent_contained'] !== null) {
            $containedPart = ', ' . (int) $incident['percent_contained'] . ' percent contained';
        }

        return sprintf(
            'Wildfire alert: %s, %s acres, %d miles from your position%s.',
            GeoMath::sanitizeForTts((string) $incident['name']),
            $acresStr,
            (int) $incident['distance_miles'],
            $containedPart
        );
    }

    /** @param array<string, mixed> $geometry @return array{0: float, 1: float}|null */
    public static function geometryCentroid(array $geometry): ?array
    {
        $type = $geometry['type'] ?? null;
        $coords = $geometry['coordinates'] ?? null;
        if (!is_array($coords) || $coords === []) {
            return null;
        }

        if ($type === 'Point' && count($coords) >= 2) {
            return [(float) $coords[1], (float) $coords[0]];
        }

        $ring = null;
        if ($type === 'Polygon' && is_array($coords[0] ?? null)) {
            $ring = $coords[0];
        } elseif ($type === 'MultiPolygon' && is_array($coords[0][0] ?? null)) {
            $ring = $coords[0][0];
        }

        if (!is_array($ring) || $ring === []) {
            return null;
        }

        $lats = [];
        $lons = [];
        foreach ($ring as $point) {
            if (!is_array($point) || count($point) < 2) {
                continue;
            }
            $lons[] = (float) $point[0];
            $lats[] = (float) $point[1];
        }
        if ($lats === []) {
            return null;
        }

        return [array_sum($lats) / count($lats), array_sum($lons) / count($lons)];
    }

    public static function parseDiscoveryTime(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_numeric($value)) {
            $num = (float) $value;
            if ($num > 1e12) {
                return (int) floor($num / 1000);
            }

            return (int) floor($num);
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (str_ends_with($text, 'Z')) {
            $text = substr($text, 0, -1) . '+00:00';
        }
        $time = strtotime($text);

        return $time === false ? null : $time;
    }
}
