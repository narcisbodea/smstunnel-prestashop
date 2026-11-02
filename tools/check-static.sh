#!/usr/bin/env bash
# PHPStan (PrestaShop's module extension, level 6, PHP 7.2 rules) against the PrestaShop sources of each
# running test shop, e.g.:  bash tools/check-static.sh smst-int-ps8-prestashop-1 smst-int-ps9-prestashop-1
# Needs tools/dev (cd tools/dev && composer install) and the shops from tests/docker/docker-compose.yml.
set -euo pipefail
export MSYS_NO_PATHCONV=1 # Git Bash: keep container paths such as /tmp as they are
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if command -v cygpath >/dev/null 2>&1; then
  ROOT="$(cygpath -m "$ROOT")"
fi
status=0
for container in "$@"; do
  echo "== $container"
  # A copy left in modules/ by an end-to-end run would be autoloaded instead of the code under test.
  docker exec "$container" rm -rf /tmp/smstunnel-repo /tmp/phpstan-smstunnel /var/www/html/modules/smstunnel
  docker cp "$ROOT/." "$container:/tmp/smstunnel-repo" >/dev/null
  docker exec -w /tmp/smstunnel-repo -e _PS_ROOT_DIR_=/var/www/html "$container" \
    php -d memory_limit=1G tools/dev/vendor/bin/phpstan analyse -c tests/phpstan/phpstan.neon --no-progress --error-format=table || status=1
  docker exec "$container" rm -rf /tmp/smstunnel-repo /tmp/phpstan-smstunnel
done
exit $status
