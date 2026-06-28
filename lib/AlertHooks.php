<?php

declare(strict_types=1);

namespace CapAlert;

final class AlertHooks
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /** @param list<array<string, mixed>> $alerts */
    public function runForAlerts(array $alerts, string $phase): void
    {
        /** @var list<array<string, mixed>> $hooks */
        $hooks = $this->config->get('alert_hooks', []);
        if (!is_array($hooks) || $hooks === []) {
            return;
        }

        foreach ($hooks as $hook) {
            if (!is_array($hook)) {
                continue;
            }
            $command = trim((string) ($hook['command'] ?? ''));
            if ($command === '') {
                continue;
            }
            $when = (string) ($hook['when'] ?? 'new');
            if ($when !== $phase && $when !== 'any') {
                continue;
            }
            /** @var list<string> $matchEvents */
            $matchEvents = $hook['events'] ?? ['*'];
            if (!is_array($matchEvents)) {
                $matchEvents = ['*'];
            }

            foreach ($alerts as $alert) {
                $event = (string) ($alert['event'] ?? '');
                if (!$this->matches($event, $matchEvents)) {
                    continue;
                }
                $this->execute($command, $event, $phase);
            }
        }
    }

    /** @param list<string> $patterns */
    private function matches(string $event, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '*' || AlertFilter::matchesGlob($event, (string) $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function execute(string $command, string $event, string $phase): void
    {
        $env = 'CAP_ALERT_EVENT=' . escapeshellarg($event) . ' CAP_ALERT_PHASE=' . escapeshellarg($phase);
        $wrapped = $env . ' ' . $command;
        exec($wrapped, $output, $code);
        $this->logger->line("Alert hook ($phase/$event) exit $code");
    }
}
