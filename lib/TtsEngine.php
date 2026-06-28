<?php

declare(strict_types=1);

namespace CapAlert;

final class TtsEngine
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function synthesize(string $text, string $ulawFile, string $textFile, string $label = ''): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        $prefix = $label !== '' ? "$label " : '';
        $tmpWav = preg_replace('/\.ulaw$/', '.wav', $ulawFile);
        if ($tmpWav === $ulawFile) {
            $tmpWav .= '.wav';
        }

        $key = (string) $this->config->get('tts_key');
        if ($key !== '') {
            $this->logger->line("{$prefix}TTS via VoiceRSS");
            $voice = new VoiceRss();
            $response = $voice->speech([
                'key' => $key,
                'src' => $text,
                'hl' => 'en-us',
                'v' => 'Amy',
                'r' => '0',
                'f' => '8khz_16bit_mono',
                'c' => 'wav',
            ]);
            if ($response['error'] === null && $response['response'] !== null) {
                if (file_put_contents($tmpWav, $response['response']) !== false
                    && Shell::toUlaw($tmpWav, $ulawFile)) {
                    file_put_contents($textFile, $text);
                    @unlink($tmpWav);
                    return $ulawFile;
                }
            }
            $this->logger->line("{$prefix}VoiceRSS failed: " . ($response['error'] ?? 'write error'));
        }

        if (Shell::commandExists('asl-tts')) {
            $this->logger->line("{$prefix}TTS via asl-tts");
            $ulBase = preg_replace('/\.ulaw$/', '', $ulawFile);
            $node = Shell::sanitizeNodeId((string) $this->config->get('node'));
            $voice = trim((string) $this->config->get('tts_voice'));
            $voiceArg = $voice !== '' ? ' -v ' . escapeshellarg($voice) : '';
            $cmd = 'asl-tts -n ' . escapeshellarg($node)
                . ' -t ' . escapeshellarg($text)
                . ' -f ' . escapeshellarg($ulBase)
                . $voiceArg;
            exec($cmd, $output, $code);
            if ($code === 0) {
                $aslUl = "$ulBase.ul";
                if (is_file($aslUl) && Shell::toUlaw($aslUl, $ulawFile)) {
                    @unlink($aslUl);
                    file_put_contents($textFile, $text);
                    return $ulawFile;
                }
                if (is_file($ulawFile)) {
                    file_put_contents($textFile, $text);
                    return $ulawFile;
                }
            }
        }

        if (Shell::commandExists('espeak-ng')) {
            $this->logger->line("{$prefix}TTS via espeak-ng");
            $cmd = 'espeak-ng -w ' . escapeshellarg($tmpWav) . ' ' . escapeshellarg($text);
            exec($cmd, $output, $code);
            if ($code === 0 && Shell::toUlaw($tmpWav, $ulawFile)) {
                file_put_contents($textFile, $text);
                @unlink($tmpWav);
                return $ulawFile;
            }
        }

        @unlink($tmpWav);
        $this->logger->line("{$prefix}No TTS backend available");
        return null;
    }
}
