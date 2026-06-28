#!/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
README="${2:-$ROOT/README.md}"
DEB_VERSION="${1:-$(dpkg-parsechangelog -S Version -l"$ROOT/debian/changelog")}"
TAG="v${DEB_VERSION}"
BASE_URL="https://github.com/hardenedpenguin/cap-alert/releases/download/${TAG}"

if [[ ! -f "$README" ]]; then
  echo "README not found: $README" >&2
  exit 1
fi

tmp="$(mktemp)"
sed \
  -e "s|Current release: \\*\\*[^*]*\\*\\*|Current release: **${DEB_VERSION}**|" \
  -e "s|wget -O /tmp/cap-alert_[^ ]*_all\\.deb|wget -O /tmp/cap-alert_${DEB_VERSION}_all.deb|" \
  -e "s|${BASE_URL%/*}/v[^/]*/cap-alert_[^ ]*_all\\.deb|${BASE_URL}/cap-alert_${DEB_VERSION}_all.deb|" \
  -e "s|https://github.com/hardenedpenguin/cap-alert/releases/download/v[^/]*/cap-alert_[^ ]*_all\\.deb|${BASE_URL}/cap-alert_${DEB_VERSION}_all.deb|" \
  -e "s|sudo apt install /tmp/cap-alert_[^ ]*_all\\.deb|sudo apt install /tmp/cap-alert_${DEB_VERSION}_all.deb|" \
  "$README" >"$tmp"
mv "$tmp" "$README"

echo "Updated $README for release ${DEB_VERSION} (${TAG})"
