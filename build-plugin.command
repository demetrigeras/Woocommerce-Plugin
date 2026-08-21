#!/bin/bash

set -euo pipefail

cd "$(dirname "$0")"
chmod +x ./create-plugin-package.sh
./create-plugin-package.sh

echo ""
read -r -p "Press Enter to close..."
