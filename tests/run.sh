#!/usr/bin/env bash
# Run the plugin's test suites.
#
# Falls back to Docker so no local PHP install is required. The tests stub
# WordPress and drive the real plugin classes against fake API responses - they
# touch no network and no database, so they are safe to run anywhere.
set -uo pipefail
cd "$(dirname "$0")/.."

SUITES="tests/test-webhook-provisioning.php tests/test-refund.php tests/test-email-branding.php"

if command -v php >/dev/null 2>&1; then
    FAILED=0
    for suite in $SUITES; do
        php "$suite" || FAILED=1
    done
    exit $FAILED
fi

echo "No local php found - running under Docker..."
exec docker run --rm -v "/$(pwd)://app" -w //app php:8.2-cli \
    sh -c 'FAILED=0; for s in tests/test-webhook-provisioning.php tests/test-refund.php tests/test-email-branding.php; do php "$s" || FAILED=1; done; exit $FAILED'
