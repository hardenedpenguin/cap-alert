<?php

declare(strict_types=1);

namespace CapAlert;

final class SoundSequencer
{
    /** @var list<string> */
    private array $paths = [];

    public function __construct(
        private readonly SoundLibrary $sounds,
        private readonly Config $config,
    ) {
    }

    public function reset(): void
    {
        $this->paths = [];
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->paths;
    }

    public function add(string $name): void
    {
        $file = $this->sounds->find($name);
        if ($file !== null) {
            $this->paths[] = $file;
        }
    }

    public function prefix(): void
    {
        $this->add('silence1');
        $this->add('strong click');
        $this->add('updated weather information');
        $this->add('weather service');
    }

    public function playbackIntro(): void
    {
        $custom = trim((string) $this->config->get('sounds.alert_intro'));
        if ($custom !== '') {
            $this->add($custom);
            return;
        }
        $this->add('silence1');
        $this->add('strong click');
    }

    public function playbackOutro(): void
    {
        $custom = trim((string) $this->config->get('sounds.alert_outro'));
        if ($custom !== '') {
            $this->add($custom);
            return;
        }
        $this->add('silence1');
    }

    public function watchStatus(string $eventsCsv): void
    {
        $all = strtolower($eventsCsv);
        if (str_contains($all, 'special weather statement')) {
            $this->add('Special Weather Statement');
        } elseif (str_contains($all, 'advisory')) {
            $this->add('advisory');
        } elseif (str_contains($all, 'watch')) {
            $this->add('watch');
        } elseif (str_contains($all, 'warning')) {
            $this->add('warning');
        }
    }

    public function appendEvents(string $eventsCsv, bool $tailMode = false): void
    {
        foreach (explode(',', $eventsCsv) as $event) {
            $event = strtolower(trim($event));
            if ($event === '') {
                continue;
            }
            if ($tailMode) {
                $this->add('star dull');
                $file = $this->sounds->find($event);
                if ($file !== null) {
                    $this->paths[] = $file;
                }
                $this->add('silence1');
            } else {
                $this->add($event);
            }
            $this->appendMajorWarning($event);
        }
    }

    public function appendMajorWarning(string $event): void
    {
        $event = strtolower(trim($event));
        $map = [
            'tornado warning' => ['persons in path of', 'tornado', 'advised to seek shelter'],
            'severe thunderstorm warning' => ['persons in path of', 'thunderstorm', 'advised to seek shelter'],
            'hurricane warning' => ['persons in path of', 'hurricane', 'advised to seek shelter'],
            'blizzard warning' => ['persons in path of', 'blizzard', 'advised to seek shelter'],
            'flash flood warning' => ['persons in path of', 'flash flood', 'advised to seek shelter'],
            'extreme wind warning' => ['persons in path of', 'extreme wind', 'advised to seek shelter'],
            'tsunami warning' => ['persons in path of', 'tsunami', 'advised to seek shelter'],
            'earthquake warning' => ['earthquake warning'],
            'flood warning' => ['persons in path of', 'flood', 'advised to seek shelter'],
            'dust storm warning' => ['persons in path of', 'dust storm', 'advised to seek shelter'],
            'ice storm warning' => ['persons in path of', 'ice storm', 'advised to seek shelter'],
            'winter storm warning' => ['persons in path of', 'winter storm', 'advised to seek shelter'],
            'high wind warning' => ['persons in path of', 'high wind', 'advised to seek shelter'],
            'storm surge warning' => ['persons in path of', 'storm surge', 'advised to seek shelter'],
        ];
        if (isset($map[$event])) {
            foreach ($map[$event] as $sound) {
                $this->add($sound);
            }
            return;
        }
        if (in_array($event, ['red flag warning', 'fire weather watch'], true)) {
            $this->add('Burn Ban');
        }
    }

    public function appendFiles(string ...$files): void
    {
        foreach ($files as $file) {
            if (is_file($file)) {
                $this->paths[] = $file;
            }
        }
    }

    public function buildTail(string $eventsCsv): array
    {
        $events = AlertFilter::filterTailEvents(explode(',', $eventsCsv), $this->config);
        $filtered = implode(',', array_map('trim', $events));
        $this->reset();
        $this->add('silence1');
        $this->appendEvents($filtered, true);
        $this->add('silence1');
        return $this->paths;
    }

    public function buildClear(): array
    {
        $this->reset();
        $this->add('silence1');
        $this->add('updated weather information');
        $this->add('strong click');
        $clear = trim((string) $this->config->get('sounds.all_clear'));
        $this->add($clear !== '' ? $clear : 'clear');
        return $this->paths;
    }
}
