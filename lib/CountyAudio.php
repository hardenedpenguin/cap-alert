<?php

declare(strict_types=1);

namespace CapAlert;

final class CountyAudio
{
    public function __construct(
        private readonly Config $config,
        private readonly SoundLibrary $sounds,
    ) {
    }

    /** @param list<string> $paths @param array<string, mixed> $alert @return list<string> */
    public function prependCountyNames(array $paths, array $alert): array
    {
        if ($this->config->get('with_county_names') !== true) {
            return $paths;
        }

        $names = $this->countyNames($alert);
        if ($names === []) {
            return $paths;
        }

        $prefix = [];
        foreach ($names as $name) {
            $file = $this->sounds->find($name);
            if ($file !== null) {
                $prefix[] = $file;
                continue;
            }
            $file = $this->sounds->find(str_replace(' ', '-', strtolower($name)));
            if ($file !== null) {
                $prefix[] = $file;
            }
        }

        return array_merge($prefix, $paths);
    }

    /** @param array<string, mixed> $alert @return list<string> */
    public function countyNames(array $alert): array
    {
        $max = max(1, (int) $this->config->get('max_county_names', 3));
        $area = trim((string) ($alert['area_desc'] ?? ''));
        if ($area === '') {
            return [];
        }

        $parts = preg_split('/[;,]/', $area) ?: [];
        $names = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $names[] = $part;
            }
            if (count($names) >= $max) {
                break;
            }
        }

        return $names;
    }

    /** @param array<string, mixed> $alert */
    public function countyTtsSuffix(array $alert): string
    {
        if ($this->config->get('with_county_names') !== true) {
            return '';
        }

        $names = $this->countyNames($alert);
        if ($names === []) {
            return '';
        }

        return ' for ' . implode(', ', $names);
    }
}
