<?php

declare(strict_types=1);

namespace CapAlert;

final class VoiceRss
{
    /** @param array<string, string> $settings */
    public function speech(array $settings): array
    {
        if (empty($settings['key']) || empty($settings['src'])) {
            return ['error' => 'Missing key or text', 'response' => null];
        }

        $ch = curl_init('https://api.voicerss.org/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8'],
            CURLOPT_POSTFIELDS => http_build_query([
                'key' => $settings['key'],
                'src' => $settings['src'],
                'hl' => $settings['hl'] ?? 'en-us',
                'v' => $settings['v'] ?? 'Amy',
                'r' => $settings['r'] ?? '0',
                'c' => $settings['c'] ?? 'wav',
                'f' => $settings['f'] ?? '8khz_16bit_mono',
            ]),
        ]);

        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp === false || str_starts_with((string) $resp, 'ERROR')) {
            return ['error' => (string) $resp, 'response' => null];
        }
        return ['error' => null, 'response' => $resp];
    }
}
