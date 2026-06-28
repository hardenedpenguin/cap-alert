#!/bin/bash
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "Run as root: sudo $0" >&2
  exit 1
fi

REPO="$(cd "$(dirname "$0")" && pwd)"
PREFIX="/usr/share/cap-alert"
CONFIG="/etc/cap-alert/config.php"
BIN="/usr/bin/cap-alert"
STATE="/var/lib/cap-alert"
LOG="/var/log/cap-alert"
SYSTEMD="/etc/systemd/system"
LIB="/usr/lib/cap-alert"
CHECK_INT="${CHECK_INT:-10}"

install -d "$PREFIX/lib" "$PREFIX/sounds" "$STATE/new_sounds" "$LOG" /etc/cap-alert "$LIB"
cp -a "$REPO/lib/"* "$PREFIX/lib/"
cp "$REPO/cap-alert.php" "$PREFIX/"
install -m 644 "$REPO/sounds/"*.ulaw "$PREFIX/sounds/"
install -m 755 "$REPO/bin/cap-alert" "$BIN"
install -m 755 "$REPO/bin/net-flag" /usr/bin/net-flag
install -m 755 "$REPO/scripts/install-systemd-timer.sh" "$LIB/install-systemd-timer"
install -m 755 "$REPO/scripts/log-failure.sh" "$LIB/log-failure"
install -m 755 "$REPO/scripts/set-cap-alert-ownership.sh" "$LIB/set-cap-alert-ownership"

if [[ ! -f "$CONFIG" ]]; then
  cp "$REPO/config/config.example.php" "$CONFIG"
  echo "Created $CONFIG — edit before first run."
fi

if [[ -f "$REPO/test/fixtures/alert.json" ]]; then
  install -m 644 "$REPO/test/fixtures/alert.json" "$PREFIX/test_alert.json"
fi

install -m 644 "$REPO/etc/logrotate.d/cap-alert" /etc/logrotate.d/cap-alert
install -m 644 "$REPO/etc/systemd/system/cap-alert.service" "$SYSTEMD/cap-alert.service"
install -m 644 "$REPO/etc/systemd/system/cap-alert.timer" "$SYSTEMD/cap-alert.timer"
install -m 644 "$REPO/etc/systemd/system/cap-alert-failure.service" "$SYSTEMD/cap-alert-failure.service"

rm -f /etc/cron.d/cap-alert /etc/cron.d/cap-alert.disabled
"$LIB/install-systemd-timer" "$CHECK_INT"
"$LIB/set-cap-alert-ownership" 2>/dev/null || true

echo "Installed to $PREFIX"
echo "Installed $(find "$PREFIX/sounds" -name '*.ulaw' | wc -l) ulaw sound files"
echo "Configure: $CONFIG"
echo "Run: sudo $BIN"
echo "Timer: systemctl status cap-alert.timer"
