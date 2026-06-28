<?php

declare(strict_types=1);

namespace CapAlert;

final class AsteriskControl
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function courtesyTone(): void
    {
        $dtmf = preg_replace('/[^0-9A-D*#]/', '', (string) $this->config->get('asterisk.courtesy_dtmf', '73')) ?? '73';
        if ($dtmf === '') {
            return;
        }

        $node = Shell::sanitizeNodeId((string) $this->config->get('node'));
        $this->run("rpt fun $node $dtmf", 'Courtesy tone');
    }

    public function enableWxTransmit(): void
    {
        $node = Shell::sanitizeNodeId((string) $this->config->get('node'));
        $this->run("radio tune $node", 'WX transmit enabled');
    }

    public function ping(): bool
    {
        [, $code] = Shell::asteriskRx('core show version');

        return $code === 0;
    }

    private function run(string $command, string $label): void
    {
        [, $code] = Shell::asteriskRx($command);
        if ($code === 0) {
            $this->logger->line($label);
            return;
        }
        $this->logger->line("$label failed (exit $code)");
    }
}
