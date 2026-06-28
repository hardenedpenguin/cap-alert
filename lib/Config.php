<?php

declare(strict_types=1);

namespace CapAlert;

final class Config
{
    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    public function __construct(array $values)
    {
        $this->values = array_replace_recursive(self::defaults(), $values);
        $this->values['paths'] = array_replace(self::defaultPaths(), $this->values['paths'] ?? []);
    }

    public static function load(string $configFile): self
    {
        if (is_file($configFile) && !is_readable($configFile)) {
            throw new \RuntimeException(
                "Config not readable: $configFile (check /etc/cap-alert ownership for the asterisk user)"
            );
        }
        if (!is_file($configFile)) {
            throw new \RuntimeException("Config not found: $configFile (run cap-alert-configure or install.sh)");
        }

        $values = require $configFile;
        if (!is_array($values)) {
            throw new \RuntimeException("Config must return an array: $configFile");
        }

        return new self($values);
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'node' => '',
            'lat' => '',
            'lon' => '',
            'tts_key' => '',
            'local_playback' => true,
            'quiet_hours' => false,
            'quiet_hours_window' => [
                'start' => '01:00',
                'end' => '07:00',
                'allow_severe' => true,
            ],
            'hold_minutes' => 25,
            'alert_cache_seconds' => 300,
            'debug' => false,
            'debug_alert_file' => '',
            'expand_descriptions' => true,
            'repeat_expanded_on_tail' => false,
            'with_county_names' => false,
            'max_county_names' => 3,
            'audio_delay_ms' => 0,
            'tts_voice' => '',
            'playback_window' => ['start' => 10, 'end' => 50],
            'user_agent' => 'cap-alert weather alert system',
            'http' => [
                'retry_attempts' => 3,
            ],
            'gps' => [
                'enabled' => false,
                'max_age_seconds' => 120,
                'min_satellites' => 3,
                'device' => '',
            ],
            'filtering' => [
                'blocked_events' => [],
                'tail_message_blocked' => [],
                'collapse_superseded' => true,
            ],
            'asterisk' => [
                'courtesy_tone' => false,
                'wx_enable_on_severe' => false,
                'courtesy_dtmf' => '73',
            ],
            'alert_hooks' => [],
            'sounds' => [
                'alert_intro' => '',
                'alert_outro' => '',
                'all_clear' => 'clear',
            ],
            'cyclone' => [
                'enabled' => false,
                'feed' => '/gis-at.xml',
                'radius_miles' => 1000,
                'hurricanes_only' => false,
                'cache_minutes' => 60,
                'max_advisory_age_hours' => 5,
                'max_announcements_per_cycle' => 3,
            ],
            'earthquake' => [
                'enabled' => false,
                'min_magnitude' => 3.5,
                'max_distance_miles' => 75,
                'max_event_age_hours' => 6,
                'lookback_hours' => 24,
                'cache_minutes' => 10,
                'max_announcements_per_cycle' => 3,
                'announce_history_on_enable' => false,
                'ignore_automatic_below' => null,
            ],
            'wildfire' => [
                'enabled' => false,
                'min_acres' => 250,
                'max_distance_miles' => 50,
                'max_discovery_age_hours' => 48,
                'cache_minutes' => 15,
                'max_announcements_per_cycle' => 3,
                'announce_history_on_enable' => false,
                'exclude_prescribed' => true,
            ],
            'pushover' => [
                'user_key' => '',
                'api_token' => '',
                'mode' => 'all',
                'daily_limit' => 50,
                'notify_on_failure' => false,
                'notify_on_all_clear' => false,
            ],
            'pi' => [
                'alarms' => false,
                'soft_c' => 65,
                'hot_c' => 75,
                'high_c' => 80,
                'label' => 'node',
                'daily_alarm_limit' => 10,
                'hold_minutes' => 25,
            ],
            'paths' => self::defaultPaths(),
        ];
    }

    /** @return array<string, string> */
    private static function defaultPaths(): array
    {
        $state = '/var/lib/cap-alert';
        $cache = "$state/cache";

        return [
            'state_dir' => $state,
            'log_dir' => '/var/log/cap-alert',
            'sound_dirs' => [
                '/usr/share/cap-alert/sounds',
                '/var/lib/cap-alert/sounds',
                '/var/lib/cap-alert/new_sounds',
                '/usr/share/asterisk/sounds/en/wx',
                '/usr/share/asterisk/sounds/en/digits',
                '/usr/share/asterisk/sounds/en/silence',
                '/usr/share/asterisk/sounds/en/rpt',
            ],
            // Control/state owned by asterisk; playback artifacts in /tmp for rpt localplay.
            'linked_flag' => "$state/linked.flag",
            'clash_lock' => "$state/run.lock",
            'alert_played_flag' => "$state/alert-played.flag",
            'temp_played_flag' => "$state/alert-temp-played.flag",
            'alerts_merged' => "$cache/alerts-merged.json",
            'alerts_point' => "$cache/alerts-point.json",
            'alerts_zone' => "$cache/alerts-zone.json",
            'points_debug' => "$cache/points-debug.json",
            'events_file' => "$state/events.txt",
            'alert_signature_file' => "$state/alert-signatures.json",
            'headlines_file' => "$state/headlines.txt",
            'descriptions_file' => "$state/descriptions.txt",
            'tail_audio' => '/tmp/warn-tail.ulaw',
            'playback_audio' => '/tmp/cap-alert-play.ulaw',
            'special_ulaw' => "$cache/special-weather.ulaw",
            'special_text' => "$cache/special-weather.txt",
            'cyclone_cache' => "$cache/cyclone.xml",
            'cyclone_ulaw' => "$cache/cyclone.ulaw",
            'cyclone_text' => "$cache/cyclone-text.txt",
            'cyclone_seen_log' => '/var/log/cap-alert/cyclone_key.log',
            'earthquake_cache' => "$cache/usgs-earthquakes.json",
            'earthquake_ulaw' => "$cache/earthquake.ulaw",
            'earthquake_text' => "$cache/earthquake-text.txt",
            'earthquake_seen_log' => '/var/log/cap-alert/earthquake_seen.log',
            'earthquake_history_seeded' => "$state/earthquake-history-seeded.flag",
            'wildfire_cache' => "$cache/wfigs-wildfires.json",
            'wildfire_ulaw' => "$cache/wildfire.ulaw",
            'wildfire_text' => "$cache/wildfire-text.txt",
            'wildfire_seen_log' => '/var/log/cap-alert/wildfire_seen.log',
            'wildfire_history_seeded' => "$state/wildfire-history-seeded.flag",
            'push_counter' => "$state/push-daily-counter.txt",
            'uv_counter' => "$state/temp-alarm-counter.txt",
            'low_volt_counter' => "$state/pi-volt-alarm-counter.txt",
        ];
    }

    /** Create state/cache directories used by default paths. */
    public function ensureStateDirectories(): void
    {
        $dirs = [
            $this->path('state_dir'),
            dirname($this->path('alerts_merged')),
        ];
        foreach (array_unique($dirs) as $dir) {
            if (is_dir($dir)) {
                continue;
            }
            if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create state directory: $dir");
            }
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $value = $this->values;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public function path(string $key): string
    {
        $value = $this->get("paths.$key");
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException("Missing path config: paths.$key");
        }
        return $value;
    }

    /** @return list<string> */
    public function soundDirs(): array
    {
        $dirs = $this->get('paths.sound_dirs', []);
        return is_array($dirs) ? array_values(array_filter($dirs, 'is_string')) : [];
    }

    public function hasPushover(): bool
    {
        return $this->get('pushover.user_key') !== '' && $this->get('pushover.api_token') !== '';
    }

    public function sanitizeCoords(): void
    {
        $this->values['lat'] = preg_replace('/[^0-9.\-]/', '', (string) $this->get('lat'));
        $this->values['lon'] = preg_replace('/[^0-9.\-]/', '', (string) $this->get('lon'));
    }

    public function setCoords(float $lat, float $lon): void
    {
        $this->values['lat'] = (string) $lat;
        $this->values['lon'] = (string) $lon;
    }

    public function lat(): float
    {
        return (float) $this->get('lat');
    }

    public function lon(): float
    {
        return (float) $this->get('lon');
    }
}
