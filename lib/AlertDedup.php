<?php

declare(strict_types=1);

namespace CapAlert;

final class AlertDedup
{
    /**
     * @param list<array<string, mixed>> $alerts
     * @return list<array<string, mixed>>
     */
    public static function process(array $alerts, Config $config): array
    {
        if ($config->get('filtering.collapse_superseded') !== true || count($alerts) < 2) {
            return $alerts;
        }

        $alerts = self::mergeZoneSplits($alerts);
        return self::collapseSuperseded($alerts);
    }

    /**
     * @param list<array<string, mixed>> $alerts
     * @return list<string>
     */
    public static function signatures(array $alerts): array
    {
        $sigs = [];
        foreach ($alerts as $alert) {
            $sigs[] = self::signature($alert);
        }
        sort($sigs);

        return $sigs;
    }

    /** @param array<string, mixed> $alert */
    public static function signature(array $alert): string
    {
        $event = self::normalizeEvent((string) ($alert['event'] ?? ''));
        $codes = $alert['county_codes'] ?? [];
        if (!is_array($codes)) {
            $codes = [];
        }
        $codes = array_values(array_unique(array_map(
            static fn($c): string => strtoupper(trim((string) $c)),
            $codes
        )));
        sort($codes);

        return $event . '|' . implode(',', $codes);
    }

    /** @param list<string> $left @param list<string> $right */
    public static function sameSignatureSet(array $left, array $right): bool
    {
        sort($left);
        sort($right);

        return $left === $right;
    }

    /**
     * @param list<array<string, mixed>> $alerts
     * @return list<array<string, mixed>>
     */
    private static function collapseSuperseded(array $alerts): array
    {
        $byEvent = [];
        foreach ($alerts as $alert) {
            $key = self::normalizeEvent((string) ($alert['event'] ?? ''));
            $byEvent[$key][] = $alert;
        }

        $kept = [];
        foreach ($byEvent as $group) {
            if (count($group) < 2) {
                $kept = array_merge($kept, $group);
                continue;
            }

            usort($group, static fn(array $a, array $b): int => self::issueTime($b) <=> self::issueTime($a));
            $winners = [];
            foreach ($group as $alert) {
                $overlap = false;
                foreach ($winners as $winner) {
                    if (self::shareCounty($alert, $winner)) {
                        $overlap = true;
                        break;
                    }
                }
                if (!$overlap) {
                    $winners[] = $alert;
                }
            }
            $kept = array_merge($kept, $winners);
        }

        return $kept;
    }

    /**
     * @param list<array<string, mixed>> $alerts
     * @return list<array<string, mixed>>
     */
    private static function mergeZoneSplits(array $alerts): array
    {
        $groups = [];
        foreach ($alerts as $alert) {
            $key = self::normalizeEvent((string) ($alert['event'] ?? ''))
                . '|' . self::issueMinute($alert)
                . '|' . trim((string) ($alert['sender_name'] ?? ''));
            $groups[$key][] = $alert;
        }

        $merged = [];
        foreach ($groups as $group) {
            if (count($group) < 2) {
                $merged = array_merge($merged, $group);
                continue;
            }

            usort($group, static fn(array $a, array $b): int => self::issueTime($b) <=> self::issueTime($a));
            $primary = $group[0];
            $codes = [];
            $areas = [];
            foreach ($group as $item) {
                foreach ($item['county_codes'] ?? [] as $code) {
                    $norm = strtoupper(trim((string) $code));
                    if ($norm !== '') {
                        $codes[$norm] = $norm;
                    }
                }
                $area = trim((string) ($item['area_desc'] ?? ''));
                if ($area !== '') {
                    foreach (preg_split('/[;,]/', $area) ?: [] as $part) {
                        $part = trim($part);
                        if ($part !== '') {
                            $areas[$part] = $part;
                        }
                    }
                }
            }
            $primary['county_codes'] = array_values($codes);
            if ($areas !== []) {
                $primary['area_desc'] = implode('; ', array_values($areas));
            }
            $merged[] = $primary;
        }

        return $merged;
    }

    /** @param array<string, mixed> $alert */
    private static function issueTime(array $alert): int
    {
        foreach (['sent', 'effective'] as $key) {
            $value = $alert[$key] ?? null;
            if (is_int($value)) {
                return $value;
            }
        }

        return 0;
    }

    /** @param array<string, mixed> $alert */
    private static function issueMinute(array $alert): string
    {
        $time = self::issueTime($alert);
        if ($time <= 0) {
            return '';
        }

        return gmdate('Y-m-d\TH:i', $time);
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private static function shareCounty(array $a, array $b): bool
    {
        $aCodes = self::countySet($a);
        $bCodes = self::countySet($b);
        if ($aCodes === [] || $bCodes === []) {
            return true;
        }

        return array_intersect($aCodes, $bCodes) !== [];
    }

    /** @param array<string, mixed> $alert @return list<string> */
    private static function countySet(array $alert): array
    {
        $codes = $alert['county_codes'] ?? [];
        if (!is_array($codes)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn($c): string => strtoupper(trim((string) $c)),
            $codes
        )));
    }

    private static function normalizeEvent(string $event): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $event) ?? $event));
    }
}
