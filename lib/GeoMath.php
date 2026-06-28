<?php

declare(strict_types=1);

namespace CapAlert;

final class GeoMath
{
    public static function haversineMiles(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        $r = 3959;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    public static function sanitizeForTts(string $text, int $maxLength = 200): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);
        if ($text !== '' && strtoupper($text) === $text && strlen($text) > 4) {
            $text = ucwords(strtolower($text));
        }
        if (strlen($text) > $maxLength) {
            $text = substr($text, 0, $maxLength - 3) . '...';
        }

        return $text;
    }
}
