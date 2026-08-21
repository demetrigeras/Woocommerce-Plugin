#!/usr/bin/env bash
# Run the webhook provisioning / signature test suite.
#
# Falls back to Docker so no local PHP install is required. The tests stub
# WordPress and drive the real plugin classes against a fake API - they touch no
# network and no database, so they are safe to run anywhere.
set -euo pipefail
cd "$(dirname "$0")/.."

if command -v php >/dev/null 2>&1; then
    exec php tests/test-webhook-provisioning.php
fi

echo "No local php found - running under Docker..."
exec docker run --rm -v "/$(pwd)://app" -w //app php:8.2-cli php tests/test-webhook-provisioning.php
