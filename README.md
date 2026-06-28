# CAP-Alert

[![CI](https://github.com/hardenedpenguin/cap-alert/actions/workflows/ci.yml/badge.svg)](https://github.com/hardenedpenguin/cap-alert/actions/workflows/ci.yml)
![Release](https://img.shields.io/github/v/release/hardenedpenguin/cap-alert?style=flat-square)
[![APT repository](https://img.shields.io/badge/apt-hardenedpenguin.github.io-blue?logo=github)](https://hardenedpenguin.github.io/hardenedpenguin-apt/)
![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)

Weather alert processor for AllStar/Asterisk nodes. Fetches NWS alerts and optional geo hazards (NHC cyclones, USGS earthquakes, NIFC wildfires), builds audio from bundled ulaw sounds and TTS, and plays them on a configured node.

**Current release:** **1.0.0-1** ([all releases](https://github.com/hardenedpenguin/cap-alert/releases))

## Install

**APT repository (recommended):** one-time setup adds the signing key and `sources.list` entry ([hardenedpenguin-apt](https://github.com/hardenedpenguin/hardenedpenguin-apt)). Supports `amd64` and `arm64`.

```bash
cd /tmp
curl -fsSLO https://hardenedpenguin.github.io/hardenedpenguin-apt/pool/main/h/hardenedpenguin-archive-keyring/hardenedpenguin-archive-keyring_1.0_all.deb
sudo apt install ./hardenedpenguin-archive-keyring_1.0_all.deb
sudo apt update
sudo apt install cap-alert
sudo cap-alert-configure
```

After the keyring is installed, use **`apt install`**, **`apt upgrade`**, and **`apt remove`** for cap-alert like any other package.

Test manually with `sudo cap-alert` (the wrapper drops to the `asterisk` user automatically).

CAP-Alert runs as **`asterisk`**, not root: the timer, manual runs, and `net-flag` use the asterisk account. State and logs under `/var/lib/cap-alert/` and `/var/log/cap-alert/` are owned by `asterisk`. Configuration lives in `/etc/cap-alert/` (**`root:asterisk` mode 750**) with **`config.php` mode 640** so only root (via `cap-alert-configure`) and the daemon can read secrets. Only `sudo cap-alert-configure` needs root for debconf. Serial GPS devices are usually in **`dialout`**, which the asterisk account is already a member of on typical AllStar installs.

## Requirements

- PHP 8.0+ with curl
- sox
- asl3-asterisk (AllStarLink 3)
- libespeak-ng1
- **asl3-tts** (recommended) — Piper voices via `asl-tts`; the package also installs **`espeak-ng`**, which CAP-Alert uses only if `asl-tts` is unavailable
- Optional: gpsd, VoiceRSS API key, Pushover credentials (CPU alarms via kernel thermal/hwmon on Pi, ARM64, and x86_64)

Alert sounds are bundled as **ulaw** and installed to `/usr/share/cap-alert/sounds/`.

## Configuration

Settings live in `/etc/cap-alert/config.php`. Use `sudo cap-alert-configure` rather than editing by hand unless you prefer to.

Re-running the wizard updates wizard-owned keys and **preserves** manual settings such as `alert_hooks`, `expand_descriptions`, and `alert_cache_seconds`. Leave Pushover/VoiceRSS password fields blank on reconfigure to keep existing keys.

The wizard configures: GPS, quiet hours, blocklists, tail blocklist, playback window, replay interval, TTS voice, courtesy tone, WX enable, county names, geo hazards, Pushover (including failure/all-clear notifications), and CPU alarm thresholds.

### Core settings

| Setting | Values | Purpose |
|---------|--------|---------|
| `node` | AllStar node number (digits) | Node used for `rpt localplay` / `rpt playback` |
| `lat` / `lon` | Decimal degrees | Static alert location; fallback when GPS has no fix |
| `gps.enabled` | `true` / `false` | Read coordinates from gpsd or serial GPS each run |
| `gps.min_satellites` | Integer (default `3`) | Reject fixes with fewer satellites |
| `gps.max_age_seconds` | Integer (default `120`) | Reject stale gpsd fixes; `0` = any age |
| `gps.device` | Path or `""` | Optional serial device; empty uses gpsd then auto-detect |
| `tts_key` | VoiceRSS API key or `""` | Online TTS; empty uses local backends (see below) |
| `tts_voice` | Piper model filename or `""` | `asl-tts` voice when using local TTS; empty = default Piper model |
| `local_playback` | `true` / `false` | `true` = `rpt localplay`; `false` = `rpt playback` (hub) |
| `quiet_hours` | `true` / `false` | When `true`, suppress lower-priority audio during quiet hours |
| `quiet_hours_window.start` / `.end` | `HH:MM` (default `01:00`–`07:00`) | Local quiet-hours window |
| `quiet_hours_window.allow_severe` | `true` / `false` | Warnings still play during quiet hours when `true` |
| `replay_hours` | Number (default `4`) | Hours before tail-replaying an unchanged NWS alert; new/changed alerts always play immediately |
| `hold_minutes` | Integer (legacy) | Used only when `replay_hours` is unset; prefer `replay_hours` |
| `alert_cache_seconds` | Integer (default `300`) | Minimum age before re-fetching NWS data |
| `expand_descriptions` | `true` / `false` | TTS for Special Weather Statements and expanded text |
| `repeat_expanded_on_tail` | `true` / `false` | Include TTS clip in tail replays |
| `debug` | `true` / `false` | Use `debug_alert_file` instead of live NWS data |
| `debug_alert_file` | Path to GeoJSON | Sample alert file for testing |

### GPS location

When `gps.enabled` is `true`, each run tries **gpsd** first (`gpspipe`), then an optional **`gps.device`**, then common USB/serial paths (`/dev/ttyUSB*`, `/dev/ttyACM*`, `/dev/serial/by-id/*`). A valid fix overrides `lat`/`lon` for that run only (config.php is not rewritten). Static coordinates are used when GPS is disabled or no fix passes quality checks.

Install **`gpsd`** and **`gpsd-clients`** on mobile nodes, or set `gps.device` for a direct serial receiver. Serial devices are usually in the **`dialout`** group; the `asterisk` account is typically already a member on AllStar installs.

Run `sudo cap-alert doctor` with GPS enabled to verify gpsd, device access, and a live fix.

### Text-to-speech (TTS)

CAP-Alert synthesizes spoken descriptions for weather text, cyclone advisories, earthquakes, wildfires, and auto-generated county names. Backends are tried in this order:

1. **VoiceRSS** — when `tts_key` is set (online; uses the VoiceRSS *Amy* voice; `tts_voice` is not used)
2. **`asl-tts`** (from **`asl3-tts`**) — local Piper synthesis when `tts_key` is empty (recommended on AllStar nodes)
3. **`espeak-ng`** — also from **`asl3-tts`**; used only if the `asl-tts` command is missing (unusual when `asl3-tts` is installed)

Install **`asl3-tts`** for local TTS. That package provides **`asl-tts`** (Piper) and **`espeak-ng`** together. Piper models live under **`/var/lib/piper-tts/`** as `*.onnx` files (each needs a matching `*.onnx.json`). List what is installed:

```bash
ls /var/lib/piper-tts/*.onnx
```

When using `asl-tts`, set **`tts_voice`** to the **full model filename**, not a short name. For example, Ryan is `en_US-ryan-low.onnx`, not `ryan`. Leave `tts_voice` empty to use the `asl-tts` default (`en_US-amy-low.onnx`).

Example (`config.php` or `sudo cap-alert-configure`):

```php
'tts_key' => '',
'tts_voice' => 'en_US-ryan-low.onnx',
```

Test a voice before changing config (run as **`asterisk`**, replace `1998` with your node):

```bash
sudo -u asterisk asl-tts -n 1998 -t "test ryan voice" -f /tmp/tts-test -v en_US-ryan-low.onnx
ls -la /tmp/tts-test.ul
```

`sudo cap-alert doctor` reports which TTS backend is available. With `debug => true`, run logs show whether each clip used VoiceRSS, `asl-tts`, or `espeak-ng`.

### Alert replay interval

When an NWS alert is **new or changed**, CAP-Alert always plays the full announcement (intro, event sounds, optional TTS, outro). While the **same** alert stays active, later timer runs normally play a shorter **tail** replay only.

Set **`replay_hours`** (default **4**) to limit how often that tail replay happens. Example: with a 15-minute poll interval and `replay_hours => 4`, you hear the full alert once, then at most one tail replay every four hours until the alert changes or clears.

New or upgraded alerts still play immediately regardless of the replay timer. To replay sooner after a tail, delete `/var/lib/cap-alert/alert-played.flag` (see [TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)).

Legacy configs without `replay_hours` fall back to **`hold_minutes`** (minutes).

### Alert filtering and dedup

| Key | Purpose |
|-----|---------|
| `filtering.blocked_events` | Glob patterns (`Rip Current*`, `Dense Fog*`) never announced |
| `filtering.tail_message_blocked` | Events excluded from tail replays |
| `filtering.collapse_superseded` | Collapse overlapping NWS products for the same event/county |

Change detection uses alert signatures (event + county codes), not raw headline text.

Example:

```php
'filtering' => [
    'blocked_events' => ['Rip Current*', 'Dense Fog Advisory'],
    'collapse_superseded' => true,
],
```

### Alert hooks

Run external commands when alerts change:

```php
'alert_hooks' => [
    ['when' => 'new', 'events' => ['Tornado Warning'], 'command' => '/usr/local/bin/tornado-hook.sh'],
],
```

Environment: `CAP_ALERT_EVENT`, `CAP_ALERT_PHASE` (`new` or `clear`).

### NHC cyclone feeds

Cyclone monitoring uses the NHC **GIS XML** feeds listed under “Dynamic Feeds” on [NHC RSS](https://www.nhc.noaa.gov/aboutrss.shtml). CAP-Alert parses `<nhc:Cyclone>` entries from these files only.

| `cyclone.feed` | Basin | Typical coverage |
|----------------|-------|------------------|
| `/gis-at.xml` | Atlantic / Gulf / Caribbean | LA, TX, MS, AL, FL, GA, SC, NC, VA, MD, NJ, NY, NE, PR |
| `/gis-ep.xml` | Eastern Pacific | CA, AZ, Mexico Pacific coast |
| `/gis-cp.xml` | Central Pacific | Hawaii and nearby waters |

Fetched from `https://www.nhc.noaa.gov/<feed>`.

| `cyclone` key | Values | Purpose |
|---------------|--------|---------|
| `enabled` | `true` / `false` | Poll NHC when `true` |
| `feed` | `/gis-at.xml`, `/gis-ep.xml`, `/gis-cp.xml` | Basin GIS feed |
| `radius_miles` | Integer | Max distance from `lat`/`lon` to announce a storm |
| `hurricanes_only` | `true` / `false` | Skip tropical storms and depressions |
| `cache_minutes` | Integer (default `60`) | Re-fetch interval for cyclone data |
| `max_advisory_age_hours` | Integer (default `5`) | Ignore advisories older than this |

### USGS earthquakes

Earthquake monitoring polls the [USGS FDSN event API](https://earthquake.usgs.gov/fdsnws/event/1/) for recent events near your coordinates. This is separate from NWS alerts (including Earthquake Warning products).

| `earthquake` key | Values | Purpose |
|------------------|--------|---------|
| `enabled` | `true` / `false` | Poll USGS when `true` |
| `min_magnitude` | Float (default `3.5`) | Minimum magnitude to announce |
| `max_distance_miles` | Integer (default `75`) | Max distance from `lat`/`lon` |
| `max_event_age_hours` | Integer (default `6`) | Ignore events older than this |
| `lookback_hours` | Integer (default `24`) | USGS query window |
| `cache_minutes` | Integer (default `10`) | Re-fetch interval |
| `max_announcements_per_cycle` | Integer (default `3`) | Cap announcements per run |
| `announce_history_on_enable` | `true` / `false` | Announce existing events when first enabled |
| `ignore_automatic_below` | Float or `null` | Skip automatic events below this magnitude |

On first enable, existing in-range events are marked seen without playback unless `announce_history_on_enable` is `true`.

### NIFC wildfires

Wildfire monitoring polls the [NIFC WFIGS interagency perimeter feed](https://services3.arcgis.com/T4QMspbfLg3qTGWY/arcgis/rest/services/WFIGS_Interagency_Perimeters_Current/FeatureServer/0) for large fires near your coordinates. Prescribed burns are excluded by default.

| `wildfire` key | Values | Purpose |
|----------------|--------|---------|
| `enabled` | `true` / `false` | Poll WFIGS when `true` |
| `min_acres` | Float (default `250`) | Minimum fire size to announce |
| `max_distance_miles` | Integer (default `50`) | Max distance from `lat`/`lon` |
| `max_discovery_age_hours` | Integer (default `48`) | Ignore older discoveries |
| `cache_minutes` | Integer (default `15`) | Re-fetch interval |
| `max_announcements_per_cycle` | Integer (default `3`) | Cap announcements per run |
| `announce_history_on_enable` | `true` / `false` | Announce existing incidents when first enabled |
| `exclude_prescribed` | `true` / `false` | Skip prescribed burns (default `true`) |

### Pushover

| `pushover.mode` | Behavior |
|-----------------|----------|
| `all` | Weather alerts and CPU temperature alarms (voice + Pushover) |
| `pi_both` | CPU temp/throttle alarms only (voice + Pushover) |
| `pi_pushover` | CPU alarms to Pushover only (no voice) |

Leave `user_key` and `api_token` empty to disable Pushover.

### CPU temperature alarms

Enable with `pi.alarms => true` on any host that exposes CPU temperature via Linux thermal or hwmon sysfs (Raspberry Pi, APX Mustang/X-Gene, x86_64, and most ARM64 SBCs).

| Key | Default | Purpose |
|-----|---------|---------|
| `alarms` | `false` | Poll CPU temperature and health flags when `true` |
| `soft_c` | `65` | Soft temperature warning (°C) |
| `hot_c` | `75` | High-temperature alarm (°C) |
| `high_c` | `80` | Critical two-phase alarm threshold (°C) |
| `label` | `node` | Spoken name before temperature |
| `daily_alarm_limit` | `10` | Max CPU alarm playbacks per day |
| `hold_minutes` | `25` | Minimum time between CPU alarm playbacks |

## Commands

```bash
sudo cap-alert                 # manual run (re-execs as asterisk)
sudo cap-alert run             # scheduled invocation (clears hold flags)
sudo cap-alert doctor          # validate config, Asterisk, TTS, caches, timer
sudo cap-alert-configure       # setup wizard (root; writes config.php)
sudo net-flag on|off|status   # suppress alerts during nets (/var/lib/cap-alert/linked.flag)
```

State files (alert cache, counters, hold flags) live under `/var/lib/cap-alert/` (owned by `asterisk`). Playback artifacts written to `/tmp/` for Asterisk `rpt localplay` / `rpt playback` paths.

Scheduled polling uses **cap-alert.timer** (systemd). Check interval is set in the setup wizard (5, 10, or 15 minutes).

```bash
systemctl status cap-alert.timer    # next run, last result
journalctl -u cap-alert.service     # recent scheduled runs
tail -f /var/log/cap-alert/cap-alert.log
```

Failed runs are logged to `/var/log/cap-alert/failures.log` (includes journal excerpt).

See [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) for common issues.

## Debug

```php
'debug' => true,
'debug_alert_file' => '/usr/share/cap-alert/test_alert.json',
```

## License

Copyright (C) 2026 Jory A. Pratt

CAP-Alert is free software licensed under the [GNU General Public License v3.0 or later](LICENSE) (GPL-3.0-or-later).

See [NOTICE](NOTICE) for third-party services and bundled dependencies.
