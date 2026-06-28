<?php

declare(strict_types=1);

namespace CapAlert;

final class AudioPlayer
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /** @param list<string> $inputs */
    public function play(array $inputs, string $outputFile, int $extraDelayMs = 0): void
    {
        if (!Shell::soxConcat($inputs, $outputFile)) {
            $this->logger->line("Failed to build audio: $outputFile");
            return;
        }
        $this->playFile($outputFile, $extraDelayMs);
    }

    public function playFile(string $file, int $extraDelayMs = 0): void
    {
        if (!is_file($file)) {
            $this->logger->line("Missing audio file: $file");
            return;
        }

        $this->logger->log("Playing $file");
        $this->logger->line("Playing $file");

        $base = preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($file, PATHINFO_FILENAME));
        $node = Shell::sanitizeNodeId((string) $this->config->get('node'));
        $cmd = $this->config->get('local_playback')
            ? "rpt localplay $node /tmp/$base"
            : "rpt playback $node /tmp/$base";

        [, $code] = Shell::asteriskRx($cmd);
        if ($code !== 0) {
            $this->logger->line("Asterisk playback failed (exit $code)");
        }

        $duration = Shell::ulawDurationSeconds($file);
        $this->logger->line("Duration ~{$duration}s");
        $pad = max(0, (int) $this->config->get('audio_delay_ms', 0)) + max(0, $extraDelayMs);
        sleep($duration + 9 + (int) floor($pad / 1000));
    }

    public function logLabel(string $label): void
    {
        $this->logger->line("Playback queue: $label");
    }
}
