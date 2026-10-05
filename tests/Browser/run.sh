#!/usr/bin/env bash
# Test klikany w panelu admina na działającej instancji dev (docker compose up), bez instalacji niczego na hoście.
set -euo pipefail
cd "$(dirname "$0")/../.."

IDS=$(docker compose exec -T php php tests/Browser/fixtures.php)
trap 'docker compose exec -T php php tests/Browser/fixtures.php cleanup >/dev/null' EXIT
mkdir -p tests/Browser/screenshots

docker run --rm --network host -u "$(id -u):$(id -g)" -e HOME=/tmp -e PYTHONPATH=/work/.pylib \
    -e ADMIN_USER="${ADMIN_USER:-admin}" -e ADMIN_PASSWORD="${ADMIN_PASSWORD:-admin}" \
    -v "$PWD/tests/Browser":/work -w /work mcr.microsoft.com/playwright/python:v1.49.0-noble \
    bash -c '[ -d .pylib ] || python3 -m pip install -q --target .pylib playwright==1.49.0; python3 admin_ui.py "$0"' "$IDS"
