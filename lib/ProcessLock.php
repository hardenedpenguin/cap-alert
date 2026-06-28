<?php

declare(strict_types=1);

namespace CapAlert;

final class ProcessLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function assertNotLinked(): void
    {
        $flag = $this->config->path('linked_flag');
        if (!is_file($flag)) {
            return;
        }

        $ageHours = (time() - filemtime($flag)) / 3600;
        if ($ageHours > 10) {
            unlink($flag);
            return;
        }

        $this->logger->log('Abort: link mute flag active');
        $this->logger->line("Link mute active ($flag) — remove flag to continue");
        exit(0);
    }

    public function acquire(): void
    {
        $lock = $this->config->path('clash_lock');
        $dir = dirname($lock);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create lock directory: $dir");
        }

        $handle = fopen($lock, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open lock file: $lock");
        }

        $waited = 0;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if ($waited === 0) {
                $this->logger->line('Waiting for lock');
            }
            if ($waited >= 90) {
                $this->logger->line('Lock wait timed out — proceeding');
                break;
            }
            sleep(5);
            $waited += 5;
        }

        ftruncate($handle, 0);
        fwrite($handle, date('c') . ' pid=' . getmypid() . "\n");
        fflush($handle);

        $this->handle = $handle;
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }
}
