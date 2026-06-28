#!/bin/sh
set -e

LOG="/var/log/cap-alert/failures.log"
JOURNAL_LINES="${CAP_ALERT_FAILURE_JOURNAL_LINES:-40}"

install -d -m 755 /var/log/cap-alert

{
	printf '%s cap-alert.service failed' "$(date -Is)"
	if command -v systemctl >/dev/null 2>&1; then
		status="$(systemctl show cap-alert.service -p ExecMainStatus --value 2>/dev/null || true)"
		if [ -n "$status" ]; then
			printf ' exit=%s' "$status"
		fi
	fi
	printf '\n'
} >>"$LOG"

if command -v journalctl >/dev/null 2>&1; then
	{
		echo "--- journal cap-alert.service ($(date -Is)) ---"
		journalctl -u cap-alert.service -n "$JOURNAL_LINES" --no-pager 2>/dev/null || true
		echo "--- end journal ---"
	} >>"$LOG"
fi

if [ -f /var/log/cap-alert/cap-alert.log ]; then
	{
		echo "--- cap-alert.log tail ---"
		tail -n 20 /var/log/cap-alert/cap-alert.log 2>/dev/null || true
		echo "--- end log tail ---"
	} >>"$LOG"
fi

if [ -f /etc/cap-alert/config.php ] && command -v php >/dev/null 2>&1; then
	# shellcheck disable=SC2016
	php -r '
require "/usr/share/cap-alert/lib/bootstrap.php";
$configFile = "/etc/cap-alert/config.php";
if (!is_file($configFile)) { exit(0); }
$config = CapAlert\Config::load($configFile);
if (!$config->get("pushover.notify_on_failure") || !$config->hasPushover()) { exit(0); }
$push = new CapAlert\PushoverClient($config, new CapAlert\Logger($config));
$push->send("cap-alert.service failed — see /var/log/cap-alert/failures.log");
' >>"$LOG" 2>&1 || true
fi
