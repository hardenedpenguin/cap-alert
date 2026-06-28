<?php

declare(strict_types=1);

namespace CapAlert;

final class HttpClient
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /** @return array{ok: bool, body: string, code: int, error: string} */
    public function get(string $url, array $headers = []): array
    {
        $attempts = max(1, (int) $this->config->get('http.retry_attempts', 3));
        $last = ['ok' => false, 'body' => '', 'code' => 0, 'error' => ''];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $last = $this->request($url, $headers);
            if ($last['ok']) {
                return $last;
            }
            if ($attempt < $attempts) {
                $delay = min(8, $attempt * 2);
                $this->logger->line("HTTP retry in {$delay}s (HTTP {$last['code']})");
                sleep($delay);
            }
        }

        return $last;
    }

    /** @return array{ok: bool, body: string, code: int, error: string} */
    public function postForm(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'body' => '', 'code' => $code, 'error' => $error];
        }
        return ['ok' => $code >= 200 && $code < 300, 'body' => $body, 'code' => $code, 'error' => $error];
    }

    /** @param list<string> $headers @return array{ok: bool, body: string, code: int, error: string} */
    private function request(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => array_merge([
                'Accept-Language: en',
                'User-Agent: ' . $this->config->get('user_agent'),
            ], $headers),
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'body' => '', 'code' => $code, 'error' => $error];
        }

        return ['ok' => $code >= 200 && $code < 300, 'body' => $body, 'code' => $code, 'error' => $error];
    }
}
