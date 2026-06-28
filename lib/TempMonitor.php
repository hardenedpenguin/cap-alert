<?php

declare(strict_types=1);

namespace CapAlert;

final class TempMonitor
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly SoundLibrary $sounds,
        private readonly SoundSequencer $sequencer,
        private readonly PlaybackQueue $queue,
        private readonly PushoverClient $pushover,
    ) {
    }

    public function run(bool $scheduled): void
    {
        if ($this->config->get('pi.alarms') !== true) {
            return;
        }

        $tempC = HostHardware::readCpuTemperatureCelsius();
        if ($tempC === null) {
            $this->logger->line('Unable to read CPU temperature');
            return;
        }

        $tempF = ($tempC * 9 / 5) + 32;
        $this->logger->line(sprintf('CPU %.1f F (%.1f C)', $tempF, $tempC));

        $this->sequencer->reset();
        $healthMessage = HostHardware::readLiveHealthFlags();
        if ($healthMessage !== '') {
            $this->handleHealthAlert($healthMessage);
        }

        $soft = (float) $this->config->get('pi.soft_c');
        $hot = (float) $this->config->get('pi.hot_c');
        $high = (float) $this->config->get('pi.high_c');

        if ($this->config->get('debug') || $tempC >= $soft) {
            $this->handleTemperature($tempC, $soft, $hot, $high);
        }

        if ($this->sequencer->paths() === []) {
            return;
        }

        if (!$this->allowPlayback($scheduled)) {
            $this->logger->line('Skipping CPU alarm playback (hold active)');
            return;
        }

        if (!$this->incrementDailyCounter($this->config->path('uv_counter'), (int) $this->config->get('pi.daily_alarm_limit'))) {
            $this->logger->line('CPU alarm daily limit reached');
            return;
        }

        $this->queue->enqueue($this->sequencer->paths(), 'cpu-alarm', false);
        StateFile::write($this->config->path('temp_played_flag'), date('c'));
    }

    private function handleHealthAlert(string $message): void
    {
        if (!$this->incrementDailyCounter($this->config->path('low_volt_counter'), 10)) {
            return;
        }

        $this->logger->line($message);
        $mode = (string) $this->config->get('pushover.mode');
        if ($this->config->hasPushover()) {
            $this->pushover->send($message);
            if ($mode === 'pi_pushover') {
                return;
            }
        }

        $this->sequencer->add('star dull');
        $this->sequencer->add('silence1');
        foreach (preg_split('/_ /', $message) as $flag) {
            $flag = trim($flag);
            if ($flag !== '') {
                $this->sequencer->add($flag);
            }
        }
        $this->sequencer->add('star dull');
        $this->sequencer->add('silence1');
    }

    private function handleTemperature(float $tempC, float $soft, float $hot, float $high): void
    {
        $mode = (string) $this->config->get('pushover.mode');
        if ($this->config->hasPushover()) {
            $this->pushover->send(sprintf('CPU %.1f C', $tempC));
        }

        $this->sequencer->add('star dull');
        $this->sequencer->add((string) $this->config->get('pi.label'));

        [$whole, $decimal] = array_pad(explode('.', number_format($tempC, 1, '.', ''), 2), 2, '0');
        foreach ($this->sounds->numberFiles((int) $whole) as $file) {
            $this->sequencer->appendFiles($file);
        }
        if ((int) $decimal >= 1) {
            $this->sequencer->add('point');
            foreach ($this->sounds->numberFiles((int) $decimal) as $file) {
                $this->sequencer->appendFiles($file);
            }
        }
        $this->sequencer->add('degrees');
        $this->sequencer->add('celsius');

        if ($tempC >= $hot) {
            $this->logger->log("CPU high $tempC C");
            if ($tempC >= $high) {
                $this->sequencer->add('emergency');
                $this->sequencer->add('warning');
                $this->sequencer->add('attention-required');
            } else {
                $this->sequencer->add('alert');
                $this->sequencer->add('high');
            }
            $this->sequencer->add('beep');
        }

        $this->sequencer->add('silence1');

        if ($mode === 'pi_pushover') {
            $this->sequencer->reset();
        }
    }

    private function allowPlayback(bool $scheduled): bool
    {
        $flag = $this->config->path('temp_played_flag');
        if (!is_file($flag)) {
            return true;
        }
        $hold = (int) $this->config->get('pi.hold_minutes', 25);
        $age = time() - filemtime($flag);
        if ($age > $hold * 60 || $scheduled) {
            @unlink($flag);
            return true;
        }
        return false;
    }

    private function incrementDailyCounter(string $file, int $limit): bool
    {
        [$date, $count] = $this->readCounter($file);
        $today = date('Ymd');
        if ($date !== $today) {
            $count = 0;
        }
        if ($count >= $limit) {
            return false;
        }
        StateFile::write($file, $today . ',' . ($count + 1));
        return true;
    }

    /** @return array{0: string, 1: int} */
    private function readCounter(string $file): array
    {
        if (!is_file($file)) {
            return ['', 0];
        }
        $parts = explode(',', trim((string) file_get_contents($file)));
        if (count($parts) !== 2) {
            return ['', 0];
        }
        return [$parts[0], (int) $parts[1]];
    }
}
