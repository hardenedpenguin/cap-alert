<?php

/**
 * Copy to /etc/cap-alert/config.php and edit.
 * Only include settings you need; omitted keys use defaults from lib/Config.php.
 */
return [
    'node' => '1998',
    'lat' => '30.4515',
    'lon' => '-91.1871',
    'gps' => [
        'enabled' => false,
        'min_satellites' => 3,
        'max_age_seconds' => 120,
        'device' => '', // e.g. /dev/ttyUSB0; empty = gpsd or auto-detect
    ],
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
    'debug_alert_file' => __DIR__ . '/../test/fixtures/alert.json',
    'expand_descriptions' => true,
    'repeat_expanded_on_tail' => false,
    'with_county_names' => false,
    'tts_voice' => '',
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
    'cyclone' => [
        'enabled' => false,
        // Supported NHC GIS feeds (see README):
        //   /gis-at.xml  Atlantic / Gulf / Caribbean
        //   /gis-ep.xml  Eastern Pacific
        //   /gis-cp.xml  Central Pacific (Hawaii)
        'feed' => '/gis-at.xml',
        'radius_miles' => 1000,
        'hurricanes_only' => false,
        'cache_minutes' => 60,
        'max_advisory_age_hours' => 5,
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
        // Set to a magnitude (e.g. 4.5) to skip automatic USGS events below that level.
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
        // all | pi_both | pi_pushover
        'mode' => 'all',
    ],
    'pi' => [
        'alarms' => false,
        'soft_c' => 65,
        'hot_c' => 75,
        'high_c' => 80,
        'label' => 'node',
    ],
];
