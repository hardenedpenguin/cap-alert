<?php

declare(strict_types=1);

namespace CapAlert;

final class QuietHours
{
    public function __construct(private readonly Config $config)
    {
    }

    public function enabled(): bool
    {
        return $this->config->get('quiet_hours') === true;
    }

    public function isActive(?int $timestamp = null): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        $timestamp ??= time();
        $hour = (int) date('G', $timestamp);
        $minute = (int) date('i', $timestamp);
        $current = ($hour * 60) + $minute;

        $start = $this->parseMinutes((string) $this->config->get('quiet_hours_window.start', '01:00'));
        $end = $this->parseMinutes((string) $this->config->get('quiet_hours_window.end', '07:00'));

        if ($start === null || $end === null) {
            return $hour >= 1 && $hour <= 6;
        }

        if ($start <= $end) {
            return $current >= $start && $current < $end;
        }

        return $current >= $start || $current < $end;
    }

    /** @param array<string, mixed> $alert */
    public function shouldMuteAlert(array $alert): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($this->config->get('quiet_hours_window.allow_severe') === true && AlertFilter::isSevere($alert)) {
            return false;
        }

        return true;
    }

    public function shouldMuteGeoHazard(): bool
    {
        return $this->isActive();
    }

    private function parseMinutes(string $value): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $match)) {
            return null;
        }

        $hour = (int) $match[1];
        $minute = (int) $match[2];
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return ($hour * 60) + $minute;
    }
}
