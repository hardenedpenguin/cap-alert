# CAP-Alert troubleshooting

## No alerts playing

1. Run `sudo cap-alert doctor` and fix any FAIL lines.
2. Confirm the timer: `systemctl status cap-alert.timer`.
3. Check logs: `journalctl -u cap-alert.service` and `/var/log/cap-alert/cap-alert.log`.
4. Verify `net-flag status` is not `on`.
5. Confirm you are inside the playback window (default minutes :10–:50 each hour) unless `debug => true`.

## "Events unchanged" but expected a replay

Tail replay only runs when the events list is unchanged, the replay hold has expired, and no `alert-played.flag` exists. The hold duration is **`replay_hours`** (default 4) in `config.php`, or legacy **`hold_minutes`** when `replay_hours` is unset.

Delete `/var/lib/cap-alert/alert-played.flag` to allow one tail replay sooner. Logs show `NWS replay hold active` when a replay is being suppressed.

## Stale or missing NWS data

CAP-Alert keeps the last good cache when api.weather.gov fails. Check cache age in `cap-alert doctor`. HTTP retries run automatically; persistent HTTP 403 often means a bad User-Agent or blocked egress.

## Quiet hours

Configure `quiet_hours`, `quiet_hours_window.start/end`, and `allow_severe`. Warnings bypass quiet hours when `allow_severe` is true.

## GPS

Enable with `gps.enabled => true` or via the setup wizard. Check `sudo cap-alert doctor` for gpsd status, device readability, and a live fix.

- **No fix, static coords used:** normal indoors or during startup; check antenna and `gps.max_age_seconds`.
- **gpspipe missing:** install `gpsd-clients` when using gpsd.
- **Device not readable:** confirm path in `gps.device` and that `asterisk` is in `dialout`.
- **gpsd and direct serial:** if gpsd owns the device, leave `gps.device` blank and use gpsd only.

## TTS silent

Ensure VoiceRSS key is valid, or install `asl3-tts` / `espeak-ng`. Test with `debug => true` and the bundled test alert file.

## Reconfigure wiped my settings

Since 1.0.4, `cap-alert-configure` preserves keys outside the wizard (alert hooks, playback window, etc.). Wizard-owned keys are replaced.

## Service failures

See `/var/log/cap-alert/failures.log` for exit codes and journal excerpts. Enable `pushover.notify_on_failure` for remote alerts.

## Permission errors after upgrade

Since 1.0.4-3, CAP-Alert runs as `asterisk`. Reinstall or run `sudo dpkg-reconfigure cap-alert` to reset ownership on `/var/lib/cap-alert`, `/var/log/cap-alert`, and `/etc/cap-alert`. Config should be `root:asterisk` mode 640; the config directory should be `root:asterisk` mode 750.

If `cap-alert doctor` reports Asterisk unreachable, confirm Asterisk is running and that you are testing as `asterisk` (`sudo -u asterisk asterisk -rx 'core show version'`).
