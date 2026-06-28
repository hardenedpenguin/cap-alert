<?php

declare(strict_types=1);

namespace CapAlert;

final class AlertFilter
{
    /** @param list<string> $patterns */
    public static function isBlocked(string $event, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matchesGlob($event, (string) $pattern)) {
                return true;
            }
        }

        return false;
    }

    public static function matchesGlob(string $value, string $pattern): bool
    {
        $value = trim($value);
        $pattern = trim($pattern);
        if ($pattern === '') {
            return false;
        }

        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';

        return (bool) preg_match($regex, $value);
    }

    /** @param array<string, mixed> $alert */
    public static function isSevere(array $alert): bool
    {
        $severity = strtolower((string) ($alert['severity'] ?? ''));
        if (in_array($severity, ['severe', 'extreme'], true)) {
            return true;
        }

        $event = strtolower((string) ($alert['event'] ?? ''));
        if (str_contains($event, 'warning') && !str_contains($event, 'watch')) {
            return true;
        }

        return false;
    }

    public static function isWarningEvent(string $event): bool
    {
        $event = strtolower(trim($event));

        return str_contains($event, 'warning') && !str_contains($event, 'watch');
    }

    /**
     * @param list<array<string, mixed>> $alerts
     * @return list<array<string, mixed>>
     */
    public static function filterBlocked(array $alerts, Config $config): array
    {
        /** @var list<string> $blocked */
        $blocked = $config->get('filtering.blocked_events', []);
        if (!is_array($blocked) || $blocked === []) {
            return $alerts;
        }

        $kept = [];
        foreach ($alerts as $alert) {
            $event = (string) ($alert['event'] ?? '');
            if (self::isBlocked($event, $blocked)) {
                continue;
            }
            $kept[] = $alert;
        }

        return $kept;
    }

    /**
     * @param list<string> $events
     * @return list<string>
     */
    public static function filterTailEvents(array $events, Config $config): array
    {
        /** @var list<string> $blocked */
        $blocked = $config->get('filtering.tail_message_blocked', []);
        if (!is_array($blocked) || $blocked === []) {
            return $events;
        }

        $kept = [];
        foreach ($events as $event) {
            if (self::isBlocked($event, $blocked)) {
                continue;
            }
            $kept[] = $event;
        }

        return $kept;
    }
}
