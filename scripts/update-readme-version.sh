#!/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
README="${2:-$ROOT/README.md}"
DEB_VERSION="${1:-$(dpkg-parsechangelog -S Version -l"$ROOT/debian/changelog")}"
TAG="v${DEB_VERSION}"

if [[ ! -f "$README" ]]; then
  echo "README not found: $README" >&2
  exit 1
fi

tmp="$(mktemp)"
sed \
  -e "s|\\*\\*Current release:\\*\\* \\*\\*[^*]*\\*\\*|**Current release:** **${DEB_VERSION}**|" \
  -e "s|Current release: \\*\\*[^*]*\\*\\*|**Current release:** **${DEB_VERSION}**|" \
  "$README" >"$tmp"
mv "$tmp" "$README"

echo "Updated $README for release ${DEB_VERSION} (${TAG})"
