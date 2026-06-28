<?php

declare(strict_types=1);

namespace CapAlert;

final class PushoverClient
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function send(string $message): void
    {
        if (!$this->config->hasPushover()) {
            return;
        }

        $message = trim($message);
        if ($message === '') {
            return;
        }
        if (strlen($message) > 1024) {
            $message = substr($message, 0, 1020) . '...';
        }

        $counterFile = $this->config->path('push_counter');
        $limit = (int) $this->config->get('pushover.daily_limit', 50);
        if (!$this->underDailyLimit($counterFile, $limit)) {
            $this->logger->line('Pushover daily limit reached');
            return;
        }

        $node = Shell::sanitizeNodeId((string) $this->config->get('node'));
        $payload = [
            'token' => (string) $this->config->get('pushover.api_token'),
            'user' => (string) $this->config->get('pushover.user_key'),
            'message' => "[Node:$node] $message",
            'priority' => 1,
        ];

        $http = new HttpClient($this->config, $this->logger);
        $response = $http->postForm('https://api.pushover.net/1/messages.json', $payload);
        if (!$response['ok']) {
            $this->logger->line('Pushover failed: ' . ($response['error'] !== '' ? $response['error'] : 'HTTP ' . $response['code']));
            return;
        }

        $this->recordDailySend($counterFile);
    }

    private function underDailyLimit(string $file, int $limit): bool
    {
        [$date, $count] = $this->readCounter($file);
        $today = date('Ymd');
        if ($date !== $today) {
            return true;
        }
        return $count < $limit;
    }

    private function recordDailySend(string $file): void
    {
        [$date, $count] = $this->readCounter($file);
        $today = date('Ymd');
        if ($date !== $today) {
            $count = 0;
        }
        file_put_contents($file, $today . ',' . ($count + 1));
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
