#!/usr/bin/env bash
# php -l on every PHP file and the unit tests, on each PHP version the module supports:
# 7.2 and 7.4 (PrestaShop 8.1/8.2), 8.1 (both), 8.5 (PrestaShop 9.1/9.2).
# With SMSTUNNEL_E2E_URL / SMSTUNNEL_E2E_KEY set, the unit tests also call that test server
# (from Docker, the host's local API is http://host.docker.internal:3101).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if command -v cygpath >/dev/null 2>&1; then
  ROOT="$(cygpath -m "$ROOT")"
fi
IMAGES=("php:7.2-cli-alpine" "prestashop/prestashop:8.1.7-7.4-apache" "php:8.1-cli-alpine" "composer:2")
status=0
for image in "${IMAGES[@]}"; do
  echo "== $image"
  MSYS_NO_PATHCONV=1 docker run --rm -v "$ROOT:/repo:ro" -w /repo \
    -e SMSTUNNEL_E2E_URL -e SMSTUNNEL_E2E_KEY \
    --add-host=host.docker.internal:host-gateway \
    --entrypoint sh "$image" -c '
      php -v | head -1
      bad=0
      for f in $(find smstunnel tests tools -name "*.php" -not -path "*/vendor/*" -not -path "*/node_modules/*"); do
        out=$(php -l "$f" 2>&1) || { echo "$out"; bad=1; }
      done
      [ $bad -eq 0 ] && echo "php -l: all files OK"
      php tests/unit/run.php || bad=1
      exit $bad' || status=1
done
exit $status
