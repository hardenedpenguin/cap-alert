<?php

declare(strict_types=1);

namespace CapAlert;

final class PlaybackQueue
{
    /** @var list<array{paths: list<string>, label: string}> */
    private array $segments = [];

    /** @param list<string> $paths @param bool $severeActions Apply courtesy tone / WX enable for warnings */
    public function enqueue(array $paths, string $label = '', bool $severeActions = false): void
    {
        $paths = array_values(array_filter($paths, 'is_file'));
        if ($paths === []) {
            return;
        }

        $this->segments[] = [
            'paths' => $paths,
            'label' => $label,
            'severe_actions' => $severeActions,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->segments === [];
    }

    public function flush(AudioPlayer $player, AsteriskControl $asterisk, Config $config): void
    {
        if ($this->segments === []) {
            return;
        }

        $delayMs = max(0, (int) $config->get('audio_delay_ms', 0));
        $output = $config->path('playback_audio');
        $index = 0;
        foreach ($this->segments as $segment) {
            $index++;
            $suffix = count($this->segments) > 1 ? "-$index" : '';
            $file = preg_replace('/\.ulaw$/', "$suffix.ulaw", $output) ?? ($output . $suffix);
            if ($segment['label'] !== '') {
                $player->logLabel($segment['label']);
            }
            $severe = !empty($segment['severe_actions'])
                || AlertFilter::isWarningEvent($segment['label'])
                || self::eventsContainWarning($segment['label']);
            if ($config->get('asterisk.courtesy_tone') === true && $severe) {
                $asterisk->courtesyTone();
            }
            if ($config->get('asterisk.wx_enable_on_severe') === true && $severe) {
                $asterisk->enableWxTransmit();
            }
            $player->play($segment['paths'], $file, $delayMs);
        }
        $this->segments = [];
    }

    private static function eventsContainWarning(string $eventsCsv): bool
    {
        foreach (explode(',', $eventsCsv) as $event) {
            if (AlertFilter::isWarningEvent($event)) {
                return true;
            }
        }

        return false;
    }
}
