<?php

/**
 * Example configuration for the cap-alert Debian package.
 * Run "sudo cap-alert-configure" instead of editing this file directly.
 */
return [
    'node' => '',
    'lat' => '',
    'lon' => '',
    'gps' => [
        'enabled' => false,
        'min_satellites' => 3,
        'max_age_seconds' => 120,
        'device' => '',
    ],
    'tts_key' => '',
    'local_playback' => true,
    'quiet_hours' => false,
    'hold_minutes' => 25,
    'replay_hours' => 4,
    'alert_cache_seconds' => 300,
    'debug' => false,
    'debug_alert_file' => '/usr/share/cap-alert/test_alert.json',
    'expand_descriptions' => true,
    'repeat_expanded_on_tail' => false,
    'cyclone' => [
        'enabled' => false,
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
    ],
    'pi' => [
        'alarms' => false,
        'soft_c' => 65,
        'hot_c' => 75,
        'high_c' => 80,
        'label' => 'node',
    ],
];
