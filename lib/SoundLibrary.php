<?php

declare(strict_types=1);

namespace CapAlert;

final class SoundLibrary
{
    private const EXT = 'ulaw';

    /** @var array<string, string> */
    private static array $aliases = [
        'updated-weather-information' => 'updated_weather_info',
        'strong-click' => 'boop',
        'star-dull' => 'boop',
        'light-click' => 'boop',
        'nws' => 'weather_service',
        'national-weather-service' => 'weather_service',
        'burn-ban' => 'burn_ban',
        'special-weather-statement' => 'special_weather_statement',
        'tornado' => 'tornado_warning',
        'earthquake-warning' => 'earthquake_warning',
        'fire-warning' => 'fire_warning',
        'currently-throttled' => 'throttling-has-occurred',
    ];

    private string $cacheFile;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly TtsEngine $tts,
    ) {
        $cacheDir = $this->config->path('log_dir');
        $this->cacheFile = $cacheDir . '/sound_cache.csv';
    }

    public function find(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $lookup = Shell::sanitizeSoundKey($name);
        if ($lookup === '') {
            return null;
        }
        if (isset(self::$aliases[$lookup])) {
            $lookup = self::$aliases[$lookup];
        }

        $cached = $this->readCache($lookup);
        if ($cached !== null) {
            return $cached;
        }

        $path = $this->resolve($lookup);
        if ($path !== null) {
            $this->writeCache($lookup, $path);
            return $path;
        }

        $this->logger->line("Sound not found: $name");
        return $this->generateMissing($name, $lookup);
    }

    /** @return list<string> */
    public function numberFiles(int $value): array
    {
        $files = [];
        $n = abs($value);

        if ($n >= 1000) {
            $thousands = intdiv($n, 1000);
            $this->appendIfFound($files, (string) $thousands);
            $this->appendIfFound($files, 'thousand');
            $n %= 1000;
        }
        if ($n >= 100) {
            $hundreds = intdiv($n, 100);
            $this->appendIfFound($files, (string) $hundreds);
            $this->appendIfFound($files, 'hundred');
            $n %= 100;
        }
        if ($n >= 20) {
            $tens = intdiv($n, 10) * 10;
            $this->appendIfFound($files, (string) $tens);
            $n %= 10;
        }
        if ($n > 0) {
            $this->appendIfFound($files, (string) $n);
        }
        if ($files === []) {
            $this->appendIfFound($files, '0');
        }

        return $files;
    }

    /** @param list<string> $files */
    private function appendIfFound(array &$files, string $name): void
    {
        $path = $this->resolve($name);
        if ($path !== null) {
            $files[] = $path;
        }
    }

    private function resolve(string $lookup): ?string
    {
        $variants = array_unique([
            $lookup,
            str_replace('-', '_', $lookup),
            str_replace('_', '-', $lookup),
        ]);

        foreach ($variants as $variant) {
            if ($variant === '') {
                continue;
            }
            foreach ($this->config->soundDirs() as $dir) {
                $path = "$dir/$variant." . self::EXT;
                if (is_file($path) && $this->isPathAllowed($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    private function isPathAllowed(string $path): bool
    {
        $real = realpath($path);
        if ($real === false) {
            return false;
        }

        $allowedRoots = array_merge(
            $this->config->soundDirs(),
            [
                $this->config->path('state_dir'),
                $this->config->path('state_dir') . '/new_sounds',
            ],
        );

        foreach ($allowedRoots as $root) {
            $base = realpath($root);
            if ($base === false) {
                continue;
            }
            if ($real === $base || str_starts_with($real, $base . '/')) {
                return true;
            }
        }

        return false;
    }

    private function readCache(string $lookup): ?string
    {
        if (!is_file($this->cacheFile)) {
            return null;
        }
        $handle = fopen($this->cacheFile, 'r');
        if (!$handle) {
            return null;
        }
        while (($line = fgetcsv($handle, 0, '|')) !== false) {
            if (isset($line[0], $line[1]) && strcasecmp($line[0], $lookup) === 0 && is_file($line[1])) {
                if (!$this->isPathAllowed($line[1])) {
                    continue;
                }
                fclose($handle);
                return $line[1];
            }
        }
        fclose($handle);
        return null;
    }

    private function writeCache(string $lookup, string $path): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return;
        }
        @file_put_contents($this->cacheFile, "$lookup|$path\n", FILE_APPEND);
    }

    private function generateMissing(string $rawName, string $lookup): ?string
    {
        $outDir = $this->config->path('state_dir') . '/new_sounds';
        if (!is_dir($outDir) && !@mkdir($outDir, 0755, true)) {
            $outDir = sys_get_temp_dir() . '/cap-alert/sounds';
            if (!is_dir($outDir)) {
                mkdir($outDir, 0755, true);
            }
        }

        $ulaw = "$outDir/$lookup." . self::EXT;
        $spoken = str_replace(['_', '-'], ' ', $rawName);
        $textFile = "$outDir/$lookup.txt";

        if ($this->tts->synthesize($spoken, $ulaw, $textFile, 'AUTO') !== null) {
            return $ulaw;
        }

        return null;
    }
}
