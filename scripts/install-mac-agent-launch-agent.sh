#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
UID_VALUE="$(id -u)"
LABEL="com.atlas.mac-agent"
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"
STDOUT="$ROOT_DIR/storage/logs/$LABEL.log"
STDERR="$ROOT_DIR/storage/logs/$LABEL.err.log"
RUNNER="$ROOT_DIR/scripts/run-mac-agent.sh"

mkdir -p "$HOME/Library/LaunchAgents" "$ROOT_DIR/storage/logs"
chmod +x "$RUNNER"

if [[ ! -x "/opt/homebrew/bin/php" ]]; then
  echo "Missing /opt/homebrew/bin/php. Install Homebrew PHP before installing Atlas Mac Agent." >&2
  exit 1
fi

cat > "$PLIST" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>$LABEL</string>
    <key>ProgramArguments</key>
    <array>
        <string>$RUNNER</string>
    </array>
    <key>WorkingDirectory</key>
    <string>$ROOT_DIR</string>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>StandardOutPath</key>
    <string>$STDOUT</string>
    <key>StandardErrorPath</key>
    <string>$STDERR</string>
</dict>
</plist>
PLIST

plutil -lint "$PLIST" >/dev/null

launchctl bootout "gui/$UID_VALUE" "$PLIST" >/dev/null 2>&1 || true
launchctl bootstrap "gui/$UID_VALUE" "$PLIST"
launchctl kickstart -k "gui/$UID_VALUE/$LABEL"
launchctl print "gui/$UID_VALUE/$LABEL" >/dev/null

echo "Atlas Mac Agent installed: $PLIST"
echo "Status: launchctl print gui/$UID_VALUE/$LABEL"
echo "Doctor: cd $ROOT_DIR && /opt/homebrew/bin/php artisan atlas:host doctor --json"
