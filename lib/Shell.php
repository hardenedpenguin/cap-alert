<?php

declare(strict_types=1);

namespace CapAlert;

final class Shell
{
    public static function sanitizeNodeId(string $node): string
    {
        return preg_replace('/[^a-zA-Z0-9]/', '', $node) ?? '';
    }

    public static function sanitizeSoundKey(string $name): string
    {
        $key = strtolower(str_replace(['_', ' '], '-', trim($name)));
        $key = str_replace(['/', '\\', '..'], '', $key);

        return preg_replace('/[^a-z0-9_-]/', '', $key) ?? '';
    }

    /** @param list<string> $paths */
    public static function soxConcat(array $paths, string $outputFile): bool
    {
        $parts = ['sox'];
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $real = realpath($path);
            if ($real === false) {
                continue;
            }
            foreach (self::soxInputArgs($real) as $arg) {
                $parts[] = $arg;
            }
            $parts[] = escapeshellarg($real);
        }
        if (count($parts) === 1) {
            return false;
        }
        $parts[] = '-t';
        $parts[] = 'ul';
        $parts[] = escapeshellarg($outputFile);
        $cmd = implode(' ', $parts);
        exec($cmd, $output, $return_var);
        return $return_var === 0;
    }

    public static function toUlaw(string $inputFile, string $outputFile): bool
    {
        if (!is_file($inputFile)) {
            return false;
        }
        $parts = ['sox'];
        foreach (self::soxInputArgs($inputFile) as $arg) {
            $parts[] = $arg;
        }
        $parts[] = escapeshellarg($inputFile);
        if (self::soxInputArgs($inputFile) === []) {
            $parts[] = '-r';
            $parts[] = '8000';
            $parts[] = '-c';
            $parts[] = '1';
        }
        $parts[] = '-t';
        $parts[] = 'ul';
        $parts[] = escapeshellarg($outputFile);
        $cmd = implode(' ', $parts);
        exec($cmd, $output, $code);
        return $code === 0 && is_file($outputFile);
    }

    /** @return list<string> */
    private static function soxInputArgs(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'ul' || $ext === 'ulaw') {
            return ['-t', 'ul', '-r', '8000', '-c', '1'];
        }
        return [];
    }

    public static function ulawDurationSeconds(string $file): int
    {
        $size = is_file($file) ? filesize($file) : 0;
        return max(1, (int) ceil($size / 8000));
    }

    public static function commandExists(string $command): bool
    {
        $path = trim((string) shell_exec('command -v ' . escapeshellarg($command)));
        return $path !== '';
    }

    /** @return array{0: list<string>, 1: int} */
    public static function asteriskRx(string $command): array
    {
        exec('asterisk -rx ' . escapeshellarg($command), $output, $code);

        return [$output, $code];
    }
}
