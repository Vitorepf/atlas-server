#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LABEL="com.atlas.power-helper"
PLIST="/Library/LaunchDaemons/$LABEL.plist"
STDOUT="$ROOT_DIR/storage/logs/$LABEL.log"
STDERR="$ROOT_DIR/storage/logs/$LABEL.err.log"

mkdir -p "$ROOT_DIR/storage/logs"
chmod +x "$ROOT_DIR/scripts/run-power-helper.sh"

TMP_PLIST="$(mktemp)"
cat > "$TMP_PLIST" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>$LABEL</string>
    <key>ProgramArguments</key>
    <array>
        <string>$ROOT_DIR/scripts/run-power-helper.sh</string>
    </array>
    <key>WorkingDirectory</key>
    <string>$ROOT_DIR</string>
    <key>RunAtLoad</key>
    <true/>
    <key>StartInterval</key>
    <integer>300</integer>
    <key>StandardOutPath</key>
    <string>$STDOUT</string>
    <key>StandardErrorPath</key>
    <string>$STDERR</string>
</dict>
</plist>
PLIST

plutil -lint "$TMP_PLIST" >/dev/null

sudo cp "$TMP_PLIST" "$PLIST"
sudo chown root:wheel "$PLIST"
sudo chmod 644 "$PLIST"
rm -f "$TMP_PLIST"

sudo launchctl bootout system "$PLIST" >/dev/null 2>&1 || true
sudo launchctl bootstrap system "$PLIST"
sudo launchctl kickstart -k "system/$LABEL"

echo "Atlas Power Helper installed: $PLIST"
echo "Status: sudo launchctl print system/$LABEL"
