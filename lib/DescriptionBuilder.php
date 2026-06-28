<?php

declare(strict_types=1);

namespace CapAlert;

final class DescriptionBuilder
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /** @param list<string> $events @param list<string> $descriptions */
    public function build(array $events, array $descriptions): string
    {
        $parts = [];
        foreach ($events as $i => $event) {
            $expand = $this->config->get('expand_descriptions') === true;
            if (!$expand && stripos($event, 'special weather statement') === false) {
                continue;
            }
            $clean = $this->clean($descriptions[$i] ?? '');
            if ($clean !== '') {
                $parts[] = $clean;
                $this->logger->line("TTS description #$i: $clean");
            }
        }

        if ($parts === []) {
            return '';
        }

        $combined = '';
        foreach ($parts as $idx => $text) {
            $combined .= '[Event ' . ($idx + 1) . ':] ' . $text . '. ';
        }
        $combined = trim($combined);

        if (strlen($combined) > 8000) {
            $this->logger->line('TTS text truncated');
            $combined = substr($combined, 0, 7950) . '... End of alert.';
        }

        return $combined;
    }

    private function clean(string $description): string
    {
        $text = preg_replace('/^\.\.\.\s*/', '', $description) ?? $description;
        $text = preg_replace('/\s*\.\.\.$/', '', $text) ?? $text;
        $text = str_replace('...', ' ', $text);
        $text = str_replace(['*', '•', '- ', ','], ' ', $text);
        $text = preg_replace('/\b[A-Z]{3,4}[A-Z]{3}\b/', '', $text) ?? $text;
        $text = preg_replace('/\b(WGUS\d+|K[A-Z]{3}|NWS|National Weather Service in)\b/i', '', $text) ?? $text;
        $text = preg_replace('/\b(WHAT|WHERE|WHEN|IMPACTS|ADDITIONAL DETAILS|HAZARD|SOURCE|DISCUSSION)\b[:]?/i', '', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text);

        $sentences = preg_split('/(?<=[.?!])\s+/', $text) ?: [];
        return trim(implode(' ', array_slice($sentences, 0, 2)));
    }
}
