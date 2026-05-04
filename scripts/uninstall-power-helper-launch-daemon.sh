#!/usr/bin/env bash
set -euo pipefail

LABEL="com.atlas.power-helper"
PLIST="/Library/LaunchDaemons/$LABEL.plist"

sudo launchctl bootout system "$PLIST" >/dev/null 2>&1 || true
sudo launchctl remove "$LABEL" >/dev/null 2>&1 || true
sudo rm -f "$PLIST"

echo "Atlas Power Helper uninstalled: $PLIST"
