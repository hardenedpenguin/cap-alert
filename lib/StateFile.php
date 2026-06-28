<?php

declare(strict_types=1);

namespace CapAlert;

final class StateFile
{
    public static function read(string $file): string
    {
        if (!is_file($file)) {
            return '';
        }

        return (string) file_get_contents($file);
    }

    public static function write(string $file, string $content): bool
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return false;
        }

        $tmp = $dir . '/.' . basename($file) . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    public static function writeJson(string $file, mixed $data): bool
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return self::write($file, $json . "\n");
    }

    public static function ageMinutes(string $file): ?int
    {
        if (!is_file($file)) {
            return null;
        }

        return (int) floor((time() - filemtime($file)) / 60);
    }
}
