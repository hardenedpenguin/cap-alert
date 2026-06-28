#!/bin/sh
set -e

# shellcheck disable=SC1091
. /usr/share/debconf/confmodule

CONFIG="/etc/cap-alert/config.php"
INSTALL_TIMER="/usr/lib/cap-alert/install-systemd-timer"
MERGE_CONFIG="/usr/lib/cap-alert/merge-debconf-config"

php_bool() {
	case "$1" in
		true) echo "true" ;;
		*) echo "false" ;;
	esac
}

php_quote() {
	printf '%s' "$1" | sed "s/'/\\\\'/g"
}

php_string_array() {
	# shellcheck disable=SC2059
	printf '%s' "$1" | tr ',' '\n' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | grep -v '^$' | sed "s/'/\\\\'/g" | sed "s/^/'/;s/$/'/" | paste -sd, - | sed 's/^/[/;s/$/]/;s/^$/[]/'
}

feed_path_for_basin() {
	case "$1" in
		"Atlantic/Gulf/Caribbean") echo "/gis-at.xml" ;;
		"Eastern Pacific") echo "/gis-ep.xml" ;;
		"Central Pacific") echo "/gis-cp.xml" ;;
		*) echo "/gis-at.xml" ;;
	esac
}

db_get cap-alert/node; node="$RET"
db_get cap-alert/lat; lat="$RET"
db_get cap-alert/lon; lon="$RET"
db_get cap-alert/gps_enabled || RET=false; gps_enabled="$RET"
db_get cap-alert/gps_min_satellites || RET=3; gps_min_satellites="$RET"
db_get cap-alert/gps_max_age_seconds || RET=120; gps_max_age_seconds="$RET"
db_get cap-alert/gps_device || RET=""; gps_device="$RET"
db_get cap-alert/local_playback || RET=true; local_playback="$RET"
db_get cap-alert/quiet_hours || RET=false; quiet_hours="$RET"
db_get cap-alert/quiet_hours_start || RET="01:00"; quiet_hours_start="$RET"
db_get cap-alert/quiet_hours_end || RET="07:00"; quiet_hours_end="$RET"
db_get cap-alert/allow_severe || RET=true; allow_severe="$RET"
db_get cap-alert/replay_hours || RET=4; replay_hours="$RET"
db_get cap-alert/blocked_events || RET=""; blocked_events="$RET"
db_get cap-alert/tail_message_blocked || RET=""; tail_message_blocked="$RET"
db_get cap-alert/repeat_expanded_on_tail || RET=false; repeat_expanded_on_tail="$RET"
db_get cap-alert/playback_window_start || RET=10; playback_window_start="$RET"
db_get cap-alert/playback_window_end || RET=50; playback_window_end="$RET"
db_get cap-alert/alert_sound_intro || RET=""; alert_sound_intro="$RET"
db_get cap-alert/alert_sound_outro || RET=""; alert_sound_outro="$RET"
db_get cap-alert/tts_key || RET=""; tts_key="$RET"
db_get cap-alert/tts_voice || RET=""; tts_voice="$RET"
db_get cap-alert/courtesy_tone || RET=false; courtesy_tone="$RET"
db_get cap-alert/wx_enable_on_severe || RET=false; wx_enable_on_severe="$RET"
db_get cap-alert/with_county_names || RET=false; with_county_names="$RET"
db_get cap-alert/cyclone_enabled || RET=false; cyclone_enabled="$RET"
db_get cap-alert/cyclone_feed || RET="Atlantic/Gulf/Caribbean"; cyclone_feed="$RET"
db_get cap-alert/cyclone_radius || RET=1000; cyclone_radius="$RET"
db_get cap-alert/cyclone_hurricanes_only || RET=false; cyclone_hurricanes_only="$RET"
db_get cap-alert/earthquake_enabled || RET=false; earthquake_enabled="$RET"
db_get cap-alert/earthquake_min_magnitude || RET=3.5; earthquake_min_magnitude="$RET"
db_get cap-alert/earthquake_radius || RET=75; earthquake_radius="$RET"
db_get cap-alert/earthquake_history_on_enable || RET=false; earthquake_history_on_enable="$RET"
db_get cap-alert/wildfire_enabled || RET=false; wildfire_enabled="$RET"
db_get cap-alert/wildfire_radius || RET=50; wildfire_radius="$RET"
db_get cap-alert/wildfire_min_acres || RET=250; wildfire_min_acres="$RET"
db_get cap-alert/wildfire_exclude_prescribed || RET=true; wildfire_exclude_prescribed="$RET"
db_get cap-alert/wildfire_history_on_enable || RET=false; wildfire_history_on_enable="$RET"
db_get cap-alert/pushover_enable || RET=false; pushover_enable="$RET"
db_get cap-alert/pushover_mode || RET=all; pushover_mode="$RET"
db_get cap-alert/pushover_user_key || RET=""; pushover_user_key="$RET"
db_get cap-alert/pushover_api_token || RET=""; pushover_api_token="$RET"
db_get cap-alert/pushover_notify_failure || RET=false; pushover_notify_failure="$RET"
db_get cap-alert/pushover_notify_all_clear || RET=false; pushover_notify_all_clear="$RET"
db_get cap-alert/pi_alarms || RET=false; pi_alarms="$RET"
db_get cap-alert/pi_soft_c || RET=65; pi_soft_c="$RET"
db_get cap-alert/pi_hot_c || RET=75; pi_hot_c="$RET"
db_get cap-alert/pi_high_c || RET=80; pi_high_c="$RET"
db_get cap-alert/pi_hold_minutes || RET=25; pi_hold_minutes="$RET"
db_get cap-alert/pi_daily_limit || RET=10; pi_daily_limit="$RET"
db_get cap-alert/cron_interval || RET=10; cron_interval="$RET"

blocked_php="$(php_string_array "$blocked_events")"
tail_blocked_php="$(php_string_array "$tail_message_blocked")"

if ! echo "$node" | grep -Eq '^[0-9]+$'; then
	echo "cap-alert: missing debconf node number" >&2
	exit 1
fi

feed_path="$(feed_path_for_basin "$cyclone_feed")"

if [ "$pushover_enable" != "true" ]; then
	pushover_user_key=""
	pushover_api_token=""
	pushover_mode="all"
	pushover_notify_failure="false"
	pushover_notify_all_clear="false"
fi

if [ "$cyclone_enabled" != "true" ]; then
	cyclone_radius="1000"
	cyclone_hurricanes_only="false"
fi

if [ "$earthquake_enabled" != "true" ]; then
	earthquake_radius="75"
	earthquake_min_magnitude="3.5"
	earthquake_history_on_enable="false"
fi

if [ "$wildfire_enabled" != "true" ]; then
	wildfire_radius="50"
	wildfire_min_acres="250"
	wildfire_exclude_prescribed="true"
	wildfire_history_on_enable="false"
fi

if [ "$pi_alarms" != "true" ]; then
	pi_soft_c="65"
	pi_hot_c="75"
	pi_high_c="80"
	pi_hold_minutes="25"
	pi_daily_limit="10"
fi

if [ "$gps_enabled" != "true" ]; then
	gps_min_satellites="3"
	gps_max_age_seconds="120"
	gps_device=""
fi

install -d -m 750 /etc/cap-alert

tmp="$(mktemp)"
cat >"$tmp" <<EOF
<?php

return [
    'node' => '$(php_quote "$node")',
    'lat' => '$(php_quote "$lat")',
    'lon' => '$(php_quote "$lon")',
    'gps' => [
        'enabled' => $(php_bool "$gps_enabled"),
        'min_satellites' => $(php_quote "$gps_min_satellites"),
        'max_age_seconds' => $(php_quote "$gps_max_age_seconds"),
        'device' => '$(php_quote "$gps_device")',
    ],
    'tts_key' => '$(php_quote "$tts_key")',
    'tts_voice' => '$(php_quote "$tts_voice")',
    'local_playback' => $(php_bool "$local_playback"),
    'quiet_hours' => $(php_bool "$quiet_hours"),
    'quiet_hours_window' => [
        'start' => '$(php_quote "$quiet_hours_start")',
        'end' => '$(php_quote "$quiet_hours_end")',
        'allow_severe' => $(php_bool "$allow_severe"),
    ],
    'replay_hours' => $(php_quote "$replay_hours"),
    'repeat_expanded_on_tail' => $(php_bool "$repeat_expanded_on_tail"),
    'playback_window' => [
        'start' => $(php_quote "$playback_window_start"),
        'end' => $(php_quote "$playback_window_end"),
    ],
    'with_county_names' => $(php_bool "$with_county_names"),
    'filtering' => [
        'blocked_events' => ${blocked_php:-[]},
        'tail_message_blocked' => ${tail_blocked_php:-[]},
        'collapse_superseded' => true,
    ],
    'sounds' => [
        'alert_intro' => '$(php_quote "$alert_sound_intro")',
        'alert_outro' => '$(php_quote "$alert_sound_outro")',
        'all_clear' => 'clear',
    ],
    'asterisk' => [
        'courtesy_tone' => $(php_bool "$courtesy_tone"),
        'wx_enable_on_severe' => $(php_bool "$wx_enable_on_severe"),
        'courtesy_dtmf' => '73',
    ],
    'cyclone' => [
        'enabled' => $(php_bool "$cyclone_enabled"),
        'feed' => '$(php_quote "$feed_path")',
        'radius_miles' => $(php_quote "$cyclone_radius"),
        'hurricanes_only' => $(php_bool "$cyclone_hurricanes_only"),
        'cache_minutes' => 60,
        'max_advisory_age_hours' => 5,
        'max_announcements_per_cycle' => 3,
    ],
    'earthquake' => [
        'enabled' => $(php_bool "$earthquake_enabled"),
        'min_magnitude' => $(php_quote "$earthquake_min_magnitude"),
        'max_distance_miles' => $(php_quote "$earthquake_radius"),
        'max_event_age_hours' => 6,
        'lookback_hours' => 24,
        'cache_minutes' => 10,
        'max_announcements_per_cycle' => 3,
        'announce_history_on_enable' => $(php_bool "$earthquake_history_on_enable"),
    ],
    'wildfire' => [
        'enabled' => $(php_bool "$wildfire_enabled"),
        'min_acres' => $(php_quote "$wildfire_min_acres"),
        'max_distance_miles' => $(php_quote "$wildfire_radius"),
        'max_discovery_age_hours' => 48,
        'cache_minutes' => 15,
        'max_announcements_per_cycle' => 3,
        'announce_history_on_enable' => $(php_bool "$wildfire_history_on_enable"),
        'exclude_prescribed' => $(php_bool "$wildfire_exclude_prescribed"),
    ],
    'pushover' => [
        'user_key' => '$(php_quote "$pushover_user_key")',
        'api_token' => '$(php_quote "$pushover_api_token")',
        'mode' => '$(php_quote "$pushover_mode")',
        'notify_on_failure' => $(php_bool "$pushover_notify_failure"),
        'notify_on_all_clear' => $(php_bool "$pushover_notify_all_clear"),
    ],
    'pi' => [
        'alarms' => $(php_bool "$pi_alarms"),
        'soft_c' => $(php_quote "$pi_soft_c"),
        'hot_c' => $(php_quote "$pi_hot_c"),
        'high_c' => $(php_quote "$pi_high_c"),
        'label' => 'node',
        'hold_minutes' => $(php_quote "$pi_hold_minutes"),
        'daily_alarm_limit' => $(php_quote "$pi_daily_limit"),
    ],
];
EOF

if [ -x "$MERGE_CONFIG" ]; then
	"$MERGE_CONFIG" "$CONFIG" "$tmp"
fi

install -m 640 "$tmp" "$CONFIG"
rm -f "$tmp"
chmod 640 "$CONFIG" 2>/dev/null || true

SET_OWNERSHIP="/usr/lib/cap-alert/set-cap-alert-ownership"
if [ -x "$SET_OWNERSHIP" ]; then
	"$SET_OWNERSHIP"
fi

if [ -x "$INSTALL_TIMER" ]; then
	"$INSTALL_TIMER" "$cron_interval"
fi
