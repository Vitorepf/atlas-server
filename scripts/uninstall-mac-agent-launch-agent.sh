#!/usr/bin/env bash
set -euo pipefail

UID_VALUE="$(id -u)"
LABEL="com.atlas.mac-agent"
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"

launchctl bootout "gui/$UID_VALUE" "$PLIST" >/dev/null 2>&1 || true
launchctl remove "$LABEL" >/dev/null 2>&1 || true
rm -f "$PLIST"

echo "Atlas Mac Agent uninstalled: $PLIST"
