#!/usr/bin/env bash
# Builds build/smstunnel.zip (see tools/build.php) in the composer:2 Docker image.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if command -v cygpath >/dev/null 2>&1; then
  ROOT="$(cygpath -m "$ROOT")" # Git Bash on Windows: D:/... for Docker
fi
MSYS_NO_PATHCONV=1 docker run --rm -v "$ROOT:/repo" -w /repo --entrypoint php composer:2 tools/build.php
