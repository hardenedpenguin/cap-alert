<?php

declare(strict_types=1);

namespace CapAlert;

final class Logger
{
    private string $logFile;

    public function __construct(Config $config)
    {
        $dir = $config->path('log_dir');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $dir = sys_get_temp_dir() . '/cap-alert';
            mkdir($dir, 0755, true);
        }
        $this->logFile = $dir . '/cap-alert.log';
    }

    public function line(string $message): void
    {
        echo date('Y-m-d H:i:s') . " $message\n";
    }

    public function log(string $status): void
    {
        $status = str_replace(',', ' ', $status);
        file_put_contents(
            $this->logFile,
            date('Y-m-d H:i:s') . ",$status\n",
            FILE_APPEND | LOCK_EX
        );
    }

    public function archive(string $sourcePath): void
    {
        if (!is_file($sourcePath)) {
            return;
        }
        $dest = dirname($this->logFile) . '/archive.log';
        $header = "\n===== " . date('Y-m-d H:i:s') . " ===== " . basename($sourcePath) . " =====\n";
        file_put_contents($dest, $header . file_get_contents($sourcePath) . "\n", FILE_APPEND);
    }
}
