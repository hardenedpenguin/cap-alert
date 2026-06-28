<?php

declare(strict_types=1);

namespace CapAlert;

final class SeenLog
{
    public static function contains(string $file, string $key): bool
    {
        if ($key === '' || !is_file($file)) {
            return false;
        }

        $seen = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return in_array($key, $seen, true);
    }

    public static function append(string $file, string $key, int $maxLines = 500): void
    {
        if ($key === '') {
            return;
        }

        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return;
        }

        $seen = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] : [];
        if (!in_array($key, $seen, true)) {
            $seen[] = $key;
        }
        if (count($seen) > $maxLines) {
            $seen = array_slice($seen, -$maxLines);
        }
        StateFile::write($file, implode("\n", $seen) . "\n");
    }
}
