# atlas-mac-native

Swift native Mac runtime for Atlas Agentic Engineering OS blocks that **cannot** run inside Laravel or Python by canonical design.

Declared in `atlas-server/docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md`:

> swift_native_mac: apple_native_context_security_voice_edge_only_behind_decision_receipt

## Scope

This project hosts:

- `AtlasMacAgent` — wake detection, power helper, background presence
- `AtlasVoiceEdge` — Apple-native voice edge surface (consumes Voice Realtime contracts)
- `AtlasAppleContext` — secure native context (keychain, biometrics, notifications)

**Never** decides policy. **Never** mutates the Laravel kernel. Reads canonical `atlas.runtime_invocation_contract.v1` envelopes, executes the Apple-native work, emits a receipt.

## Why this exists separate from atlas-desktop

`atlas-desktop` is Tauri (Rust + Vite + React) for the cross-platform UI surface. `atlas-mac-native` is Apple-only Swift for capabilities that ONLY work via Apple frameworks (kernel-level power management, native voice APIs, keychain, etc.).

## Status

Scaffold. Package.swift + canonical boundary contract validator. Actual Apple framework wiring requires:

- Xcode + Swift toolchain
- Operator decision per capability
- AP per capability

## Layout

```
Package.swift              # SPM manifest
Sources/AtlasMacNative/    # main library
    Boundary/              # contract validator (mirror PHP AP-201)
    MacAgent/              # wake/power/background
    VoiceEdge/             # Apple native voice
    AppleContext/          # keychain/biometrics/notifications
Tests/                     # XCTest
```
