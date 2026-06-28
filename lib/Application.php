<?php

declare(strict_types=1);

namespace CapAlert;

final class Application
{
    private Logger $logger;
    private HttpClient $http;
    private NwsClient $nws;
    private AlertParser $parser;
    private TtsEngine $tts;
    private SoundLibrary $sounds;
    private SoundSequencer $sequencer;
    private AudioPlayer $player;
    private PlaybackQueue $queue;
    private AsteriskControl $asterisk;
    private DescriptionBuilder $descriptions;
    private CountyAudio $counties;
    private GpsReader $gps;
    private PushoverClient $pushover;
    private ProcessLock $lock;
    private QuietHours $quietHours;
    private AlertHooks $hooks;
    private CycloneMonitor $cyclones;
    private EarthquakeMonitor $earthquakes;
    private WildfireMonitor $wildfires;
    private TempMonitor $temp;

    public function __construct(private readonly Config $config)
    {
        $this->logger = new Logger($config);
        $this->http = new HttpClient($config, $this->logger);
        $this->nws = new NwsClient($config, $this->http, $this->logger);
        $this->parser = new AlertParser();
        $this->tts = new TtsEngine($config, $this->logger);
        $this->sounds = new SoundLibrary($config, $this->logger, $this->tts);
        $this->sequencer = new SoundSequencer($this->sounds, $config);
        $this->player = new AudioPlayer($config, $this->logger);
        $this->queue = new PlaybackQueue();
        $this->asterisk = new AsteriskControl($config, $this->logger);
        $this->descriptions = new DescriptionBuilder($config, $this->logger);
        $this->counties = new CountyAudio($config, $this->sounds);
        $this->gps = new GpsReader($config, $this->logger);
        $this->pushover = new PushoverClient($config, $this->logger);
        $this->lock = new ProcessLock($config, $this->logger);
        $this->quietHours = new QuietHours($config);
        $this->hooks = new AlertHooks($config, $this->logger);
        $this->cyclones = new CycloneMonitor($config, $this->http, $this->logger, $this->tts, $this->sequencer, $this->queue, $this->pushover);
        $this->earthquakes = new EarthquakeMonitor($config, $this->http, $this->logger, $this->tts, $this->sequencer, $this->queue, $this->pushover);
        $this->wildfires = new WildfireMonitor($config, $this->http, $this->logger, $this->tts, $this->sequencer, $this->queue, $this->pushover);
        $this->temp = new TempMonitor($config, $this->logger, $this->sounds, $this->sequencer, $this->queue, $this->pushover);
    }

    public function run(bool $scheduled = false): void
    {
        $this->config->ensureStateDirectories();
        $this->logger->line('CAP-Alert starting');
        $this->logger->line('Node ' . $this->config->get('node'));

        $fix = $this->gps->read();
        if ($fix !== null) {
            $this->config->setCoords($fix[0], $fix[1]);
            $this->logger->line("GPS lock {$fix[0]}, {$fix[1]}");
        } elseif ($this->gps->enabled()) {
            $this->logger->line('GPS enabled but no fix; using static ' . $this->config->lat() . ', ' . $this->config->lon());
        } else {
            $this->logger->line('Coords ' . $this->config->lat() . ', ' . $this->config->lon());
        }

        $this->lock->assertNotLinked();
        $this->lock->acquire();

        try {
            $geoMute = $this->quietHours->shouldMuteGeoHazard();
            if ($geoMute) {
                $this->logger->line('Quiet hours active (geo hazards muted)');
            }

            $repeatHold = $this->isRepeatHoldActive();
            if ($repeatHold) {
                $this->logger->line('NWS replay hold active (unchanged alert tail replays suppressed)');
            }

            $alerts = $this->loadAlerts();
            $alerts = AlertDedup::process($alerts, $this->config);
            $alerts = AlertFilter::filterBlocked($alerts, $this->config);
            $serialized = $this->parser->serializeEvents($alerts);

            if ($serialized['events'] === '') {
                $this->handleAllClear();
            } else {
                $this->logger->line('Events: ' . $serialized['events']);
                $this->handleAlerts($serialized, $repeatHold);
            }

            if ($this->inPlaybackWindow()) {
                $this->cyclones->run($scheduled, $geoMute);
                $this->earthquakes->run($scheduled, $geoMute);
                $this->wildfires->run($scheduled, $geoMute);
            }

            $this->temp->run($scheduled);
            $this->queue->flush($this->player, $this->asterisk, $this->config);
        } finally {
            $this->lock->release();
            $this->logger->line('CAP-Alert finished');
        }
    }

    public function doctor(bool $fix = false): int
    {
        $doctor = new Doctor($this->config, $this->http, $this->asterisk, $this->logger, $this->gps);

        return $doctor->run($fix);
    }

    /** @return list<array<string, mixed>> */
    private function loadAlerts(): array
    {
        $merged = $this->config->path('alerts_merged');
        $point = $this->config->path('alerts_point');
        $zone = $this->config->path('alerts_zone');

        if ($this->shouldFetchAlerts($merged)) {
            $this->nws->fetchPointAlerts($point);
            $zones = $this->nws->fetchZones($this->config->path('points_debug'));
            if ($zones !== null) {
                $this->logger->line("Zones {$zones['forecast']} {$zones['county']}");
                $this->nws->fetchZoneAlerts($zones['forecast'], $zones['county'], $zone);
                NwsClient::mergeAlertFiles($point, $zone, $merged);
            } elseif (is_file($point)) {
                copy($point, $merged);
            }
        } else {
            $age = StateFile::ageMinutes($merged);
            $this->logger->line('Using cached alerts' . ($age !== null ? " ({$age}m old)" : ''));
        }

        $source = $merged;
        if ($this->config->get('debug') === true) {
            $debugFile = (string) $this->config->get('debug_alert_file');
            if ($debugFile !== '' && is_file($debugFile)) {
                $source = $debugFile;
                $this->logger->line("Debug alert file: $source");
            }
        }

        return $this->parser->parseFile($source);
    }

    private function shouldFetchAlerts(string $merged): bool
    {
        if (!is_file($merged)) {
            return true;
        }
        $cacheSeconds = (int) $this->config->get('alert_cache_seconds', 300);

        return (time() - filemtime($merged)) >= $cacheSeconds;
    }

    /** @param array<string, mixed> $serialized */
    private function handleAlerts(array $serialized, bool $repeatHold): void
    {
        $eventsFile = $this->config->path('events_file');
        $signatureFile = $this->config->path('alert_signature_file');
        $eventsCsv = (string) $serialized['events'];
        $signatures = AlertDedup::signatures($serialized['alert_list'] ?? []);
        $previousSignatures = $this->readSignatures($signatureFile);
        $status = 'new';

        if ($previousSignatures !== [] && AlertDedup::sameSignatureSet($signatures, $previousSignatures)) {
            $status = 'unchanged';
            $this->logger->line('Alerts unchanged (signature match)');
        } elseif (is_file($eventsFile) && trim(StateFile::read($eventsFile)) === $eventsCsv) {
            $status = 'unchanged';
            $this->logger->line('Events unchanged');
        } else {
            $this->logger->log('New alert ' . $eventsCsv);
        }

        $playedFlag = $this->config->path('alert_played_flag');
        if ($status === 'unchanged' && !$repeatHold && !is_file($playedFlag)) {
            $this->playTail($playedFlag);
            return;
        }

        if ($status !== 'new') {
            return;
        }

        /** @var list<array<string, mixed>> $alertList */
        $alertList = $serialized['alert_list'] ?? [];
        if ($this->shouldMuteAllAlerts($alertList)) {
            $this->logger->line('Alerts suppressed by quiet hours');
            StateFile::write($eventsFile, $eventsCsv);
            StateFile::writeJson($signatureFile, $signatures);
            return;
        }

        StateFile::write($eventsFile, $eventsCsv);
        StateFile::writeJson($signatureFile, $signatures);
        StateFile::write($this->config->path('headlines_file'), (string) $serialized['headlines']);
        StateFile::write($this->config->path('descriptions_file'), (string) $serialized['descriptions']);

        foreach ([
            $this->config->path('alerts_merged'),
            $this->config->path('alerts_point'),
            $this->config->path('alerts_zone'),
            $this->config->path('points_debug'),
        ] as $archive) {
            $this->logger->archive($archive);
        }

        if (is_file($this->config->path('tail_audio'))) {
            unlink($this->config->path('tail_audio'));
        }

        $eventList = $serialized['event_list'];
        $descriptionList = $serialized['description_list'];
        if (!is_array($eventList) || !is_array($descriptionList)) {
            return;
        }

        $this->hooks->runForAlerts($alertList, 'new');

        $ttsText = $this->descriptions->build($eventList, $descriptionList);
        if ($this->config->get('with_county_names') === true && $alertList !== []) {
            $ttsText .= $this->counties->countyTtsSuffix($alertList[0]);
        }
        $previous = trim(StateFile::read($this->config->path('special_text')));

        $skipTts = ($ttsText !== '' && $ttsText === $previous);
        if ($skipTts) {
            $this->logger->line('Description unchanged — reusing TTS cache');
        } elseif ($ttsText !== '') {
            $this->tts->synthesize(
                $ttsText,
                $this->config->path('special_ulaw'),
                $this->config->path('special_text'),
                'SWS'
            );
        }

        $this->sequencer->reset();
        $this->sequencer->prefix();
        $this->sequencer->watchStatus($eventsCsv);
        $this->sequencer->playbackIntro();
        $this->sequencer->appendEvents($eventsCsv, false);

        if ($this->config->get('pushover.mode') === 'all' && $this->config->hasPushover()) {
            $this->pushover->send($eventsCsv);
        }

        $this->sequencer->appendFiles($this->config->path('special_ulaw'));
        $this->sequencer->playbackOutro();

        $paths = $this->counties->prependCountyNames($this->sequencer->paths(), $alertList[0] ?? []);
        $this->waitForSafeWindow($alertList);
        $this->queue->enqueue($paths, $eventsCsv, true);
        StateFile::write($playedFlag, date('c'));

        $tail = $this->sequencer->buildTail($eventsCsv);
        if ($this->config->get('repeat_expanded_on_tail') === true) {
            $special = $this->config->path('special_ulaw');
            if (is_file($special)) {
                $tail[] = $special;
            }
        }

        if (Shell::soxConcat($tail, $this->config->path('tail_audio'))) {
            $this->logger->line('Tail file updated');
        }
    }

    private function handleAllClear(): void
    {
        $eventsFile = $this->config->path('events_file');
        if (!is_file($eventsFile)) {
            return;
        }

        $this->hooks->runForAlerts([], 'clear');
        $this->cleanupAlertState();
        $this->logger->log('All clear');
        $this->logger->line('All clear');

        if ($this->config->get('pushover.notify_on_all_clear') === true && $this->config->hasPushover()) {
            $this->pushover->send('All clear');
        }

        $paths = $this->sequencer->buildClear();
        $this->queue->enqueue($paths, 'all-clear', false);
    }

    private function cleanupAlertState(): void
    {
        foreach ([
            'events_file', 'alert_signature_file', 'headlines_file', 'descriptions_file', 'tail_audio',
            'special_ulaw', 'special_text',
        ] as $key) {
            $path = $this->config->path($key);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function playTail(string $playedFlag): void
    {
        $tail = $this->config->path('tail_audio');
        if (!is_file($tail)) {
            return;
        }
        if (!$this->inPlaybackWindow()) {
            $this->logger->line('Outside playback window');
            return;
        }
        $this->queue->enqueue([$tail], 'tail', false);
        StateFile::write($playedFlag, date('c'));
        $this->logger->line('Queued tail replay');
    }

    private function isRepeatHoldActive(): bool
    {
        $flag = $this->config->path('alert_played_flag');
        if (!is_file($flag)) {
            return false;
        }

        $holdSeconds = $this->config->replayHoldSeconds();
        if ($holdSeconds <= 0) {
            return false;
        }

        $age = time() - filemtime($flag);
        if ($age >= $holdSeconds) {
            unlink($flag);

            return false;
        }

        return true;
    }

    /** @param list<array<string, mixed>> $alerts */
    private function shouldMuteAllAlerts(array $alerts): bool
    {
        if (!$this->quietHours->isActive()) {
            return false;
        }

        foreach ($alerts as $alert) {
            if (!$this->quietHours->shouldMuteAlert($alert)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, mixed>> $alerts */
    private function waitForSafeWindow(array $alerts): void
    {
        if ($this->config->get('debug') === true) {
            return;
        }
        if (!$this->shouldMuteAllAlerts($alerts)) {
            return;
        }
        while (!$this->inPlaybackWindow()) {
            sleep(30);
        }
    }

    /** @return list<string> */
    private function readSignatures(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode(StateFile::read($file), true);

        return is_array($data) ? array_values(array_filter($data, 'is_string')) : [];
    }

    private function inPlaybackWindow(): bool
    {
        if ($this->config->get('debug') === true) {
            return true;
        }
        $start = (int) $this->config->get('playback_window.start', 10);
        $end = (int) $this->config->get('playback_window.end', 50);
        $minute = (int) date('i');

        return $minute >= $start && $minute <= $end;
    }
}
