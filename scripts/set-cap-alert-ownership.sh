#!/bin/sh
set -e

AST_USER="${CAP_ALERT_USER:-asterisk}"
STATE="/var/lib/cap-alert"
LOG="/var/log/cap-alert"
CONFIG="/etc/cap-alert/config.php"

if ! id "$AST_USER" >/dev/null 2>&1; then
	echo "cap-alert: user $AST_USER not found; skip ownership setup" >&2
	exit 0
fi

install -d -m 750 "$STATE" "$STATE/cache" "$STATE/new_sounds"
install -d -m 755 "$LOG"
ETC_DIR="$(dirname "$CONFIG")"
install -d -m 750 "$ETC_DIR"

chown -R "$AST_USER:$AST_USER" "$STATE" "$LOG"
find "$STATE" -type d -exec chmod 750 {} \;
find "$STATE" -type f -exec chmod 640 {} \; 2>/dev/null || true
chmod 755 "$LOG"
chown "root:$AST_USER" "$ETC_DIR"
chmod 750 "$ETC_DIR"

if [ -f "$CONFIG" ]; then
	chown "root:$AST_USER" "$CONFIG"
	chmod 640 "$CONFIG"
fi
