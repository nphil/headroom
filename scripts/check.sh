#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
for file in src/*.php src/lib/*.php src/*.page scripts/*.php tests/*.php; do php -l "$file" >/dev/null; done
shellcheck src/rc.headroom src/event/* scripts/check.sh
node --check src/headroom.js
php tests/run.php
php scripts/build.php
php scripts/check-installer.php headroom.plg
