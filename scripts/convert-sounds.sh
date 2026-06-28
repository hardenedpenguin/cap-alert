#!/bin/bash
# Convert legacy GSM/UL alert sounds to 8 kHz mono ulaw for CAP-Alert.
set -euo pipefail

SRC="${1:-/home/anarchy/Downloads/tmp/var/lib/cap-alert/sounds}"
DEST="$(cd "$(dirname "$0")/.." && pwd)/sounds"

if [[ ! -d "$SRC" ]]; then
  echo "Source directory not found: $SRC" >&2
  exit 1
fi

command -v sox >/dev/null || { echo "sox required" >&2; exit 1; }

mkdir -p "$DEST"
count=0

for f in "$SRC"/*.{gsm,ul}; do
  [[ -f "$f" ]] || continue
  base=$(basename "$f")
  name="${base%.*}"
  out="$DEST/${name}.ulaw"
  sox "$f" -r 8000 -c 1 -t ul "$out"
  ((count++)) || true
done

echo "Converted $count files to $DEST"
