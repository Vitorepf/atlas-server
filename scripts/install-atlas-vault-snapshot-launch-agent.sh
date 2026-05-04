#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
UID_VALUE="$(id -u)"
LABEL="com.vitorepf.atlas-vault-snapshot"
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"
STDOUT="$HOME/Library/Logs/atlas-vault-snapshot.log"
STDERR="$HOME/Library/Logs/atlas-vault-snapshot.err.log"
RUNNER="$ROOT_DIR/scripts/atlas-vault-snapshot.sh"

mkdir -p "$HOME/Library/LaunchAgents" "$HOME/Library/Logs"
chmod +x "$RUNNER"

if [[ ! -x "/usr/bin/osascript" ]]; then
  echo "Missing /usr/bin/osascript; cannot install iCloud-safe LaunchAgent." >&2
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
    <string>/usr/bin/osascript</string>
    <string>-e</string>
    <string>do shell script "$RUNNER"</string>
  </array>

  <key>EnvironmentVariables</key>
  <dict>
    <key>PATH</key>
    <string>/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin</string>
  </dict>

  <key>StartInterval</key>
  <integer>1800</integer>

  <key>RunAtLoad</key>
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

echo "AtlasVault snapshot LaunchAgent installed: $PLIST"
echo "Status: launchctl print gui/$UID_VALUE/$LABEL"
echo "Logs: tail -n 20 '$STDOUT' '$STDERR'"
