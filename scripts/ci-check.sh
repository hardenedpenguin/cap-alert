#!/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "==> PHP syntax check"
find lib cap-alert.php config -name '*.php' -print0 | xargs -0 -n1 php -l

echo "==> PHP smoke test"
php scripts/ci-smoke.php

echo "==> PHP unit checks"
php scripts/ci-unit.php

echo "==> PHP behavior checks"
php scripts/ci-behavior.php

echo "==> ShellCheck"
mapfile -t shell_scripts < <(
  find bin scripts -maxdepth 1 -type f \( -name '*.sh' -o -name 'cap-alert' -o -name 'net-flag' -o -name 'cap-alert-configure' \) -print
  printf '%s\n' install.sh
)
shellcheck "${shell_scripts[@]}"

echo "==> Build Debian binary package"
dpkg-buildpackage -us -uc -b

VERSION="$(dpkg-parsechangelog -S Version)"
DEB="../cap-alert_${VERSION}_all.deb"
CHANGES="../cap-alert_${VERSION}_amd64.changes"
ARTIFACTS="$ROOT/artifacts"
mkdir -p "$ARTIFACTS"

if [[ ! -f "$DEB" ]]; then
  echo "Expected package not found: $DEB" >&2
  exit 1
fi

cp "$DEB" "$ARTIFACTS/"
if [[ -f "$CHANGES" ]]; then
  cp "$CHANGES" "$ARTIFACTS/"
fi

echo "==> Lintian"
lintian "$ARTIFACTS/$(basename "$DEB")"

echo "==> CI checks passed"
echo "Package: $ARTIFACTS/$(basename "$DEB")"
