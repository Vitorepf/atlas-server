# Atlas Voice Realtime Python Runtime

Status: scaffold.

This runtime is the future LiveKit Agents SDK worker for `voice_realtime`.
It is not a decision engine. It only consumes the Kernel bootstrap manifest,
submits turns to the Laravel Kernel, and reports callbacks.

Hard rules:

1. never call LLM providers directly;
2. never execute tools directly;
3. never persist raw audio, raw transcript, or raw response text;
4. fail closed when the Kernel manifest is missing or invalid;
5. always call the Kernel before TTS or provider execution.
6. build turns through `AtlasVoiceTurnPayload`, never ad hoc dicts.
7. build callbacks through `AtlasVoice*Payload`, never raw TTS/audio dicts.
8. run turns through `AtlasVoiceAgentRuntime` before synthesis/playback.
9. parse leases through `AtlasVoiceSessionLease`; never log `access_token`.
10. route LiveKit worker sessions through `LiveKitVoiceSession`; it may hold an access token in memory for room join code, but all logs and Kernel payloads must remain token-free.
11. translate SDK callbacks through `AtlasLiveKitWorker`; unsupported event kinds must fail closed.
12. when LiveKit Agents SDK is installed, wire SDK callbacks through `LiveKitSdkAdapter`; never call `AtlasKernelClient` directly from SDK code.
13. use `--mock-kernel` only for deterministic local smoke tests; production workers must call the real Laravel Kernel.
14. normalize raw SDK objects through `LiveKitSdkEventBridge` and dispatch callback mappings through `LiveKitCallbackRouter`; do not add a second callback switch.
15. obey Kernel allowlists for runtime, client surface, transport and privacy class; unknown strings fail closed.
16. reject synthesis, playback, interruption and provider-health callbacks until the Kernel accepts the `turn_id` with a Decision Receipt.
17. keep worker start blocked until LiveKit Agents SDK, real Kernel boundary, callback router and production SDK loop are all wired.

Bootstrap:

```bash
php artisan atlas:ai:voice bootstrap --base-url=http://localhost --json \
  > runtimes/python/voice_realtime/bootstrap.local.json
php artisan atlas:ai:voice dependencies --json
export ATLAS_VOICE_PYTHON_BIN="${ATLAS_VOICE_PYTHON_BIN:-python3}"
"$ATLAS_VOICE_PYTHON_BIN" -m unittest discover -s runtimes/python/voice_realtime/tests
"$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --check
cp runtimes/python/voice_realtime/.env.example runtimes/python/voice_realtime/.env.local
"$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --env-file runtimes/python/voice_realtime/.env.local --check
"$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --env-file runtimes/python/voice_realtime/.env.local --preflight
"$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --env-file runtimes/python/voice_realtime/.env.local --activation-contract
"$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --dependency-install-plan
"$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --mock-kernel --kernel-dependency-install-plan
"$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --mock-kernel --kernel-product-loop-check
"$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --sdk-check
"$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --worker-plan
"$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --callback-loop-check
"$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --production-loop-plan
"$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --callback-loop-check --production-sdk-loop-wired
"$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --production-loop-plan --production-sdk-loop-wired
"$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --env-file runtimes/python/voice_realtime/.env.local --product-loop-check
"$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --mock-kernel --sdk-events runtimes/python/voice_realtime/sdk-events.example.json
"$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --start-worker
ATLAS_BASE_URL=http://localhost ATLAS_TOKEN=token LIVEKIT_URL=http://localhost:7880 \
  LIVEKIT_API_KEY=key LIVEKIT_API_SECRET=secret \
  ATLAS_VOICE_BOOTSTRAP=runtimes/python/voice_realtime/bootstrap.local.json \
  "$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --env --check
"$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --env-file runtimes/python/voice_realtime/.env.local \
  --scripted-events runtimes/python/voice_realtime/scripted-events.example.json
"$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json \
  --mock-kernel --callback-event runtimes/python/voice_realtime/callback-event.example.json
"$ATLAS_VOICE_PYTHON_BIN" -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json \
  --mock-kernel --callback-events runtimes/python/voice_realtime/callback-events.example.json
php artisan atlas:ai:voice scripted-smoke --json
php artisan atlas:ai:voice preflight --json
php artisan atlas:ai:voice activation-contract --json
php artisan atlas:ai:voice dependency-install-plan --json
php artisan atlas:ai:voice sdk-check --json
php artisan atlas:ai:voice worker-plan --json
php artisan atlas:ai:voice callback-loop-check --json
php artisan atlas:ai:voice production-loop-plan --json
php artisan atlas:ai:voice callback-loop-check --production-sdk-loop-wired --json
php artisan atlas:ai:voice production-loop-plan --production-sdk-loop-wired --json
php artisan atlas:ai:voice product-loop-check --json
php artisan atlas:ai:voice product-loop-check --callback-loop-wired --production-sdk-loop-wired --json
php artisan atlas:ai:voice production-loop-smoke --json
php artisan atlas:ai:voice worker-start-check --json
php artisan atlas:ai:voice worker-start-check --callback-loop-wired --production-sdk-loop-wired --json
php artisan atlas:ai:voice token-issuer-plan --json
php artisan atlas:ai:voice token-issuer-smoke --json
php artisan atlas:ai:voice token-issuer-smoke --ephemeral-test-config --json
php artisan atlas:ai:voice pre-start-health-checks-smoke --json
php artisan atlas:ai:voice runtime-certify --json
php artisan atlas:ai:voice runtime-certify --require-sdk --callback-loop-wired --production-sdk-loop-wired --json
php artisan atlas:ai:voice promotion-review-packet --json
php artisan atlas:ai:voice promotion-review-packet --callback-loop-wired --production-sdk-loop-wired --json
curl -H "X-Atlas-Token: $ATLAS_TOKEN" "http://localhost/ai/voice/runtime/certification?runtime=livekit_agents_sdk"
```

LiveKit Agents SDK is operator-managed through
`requirements-livekit.txt` and `dependency-install-plan`; scaffold code may
emit install and verify commands, but never runs pip, imports LiveKit SDK,
starts a daemon or mutates Kernel policy by itself. `sdk-check` is a probe, not
an import. It reports `package_checks`, `missing_imports`, installed version
metadata when available and `sdk_imported=false`; no scaffold check may import
LiveKit SDK modules just to decide readiness.
`--kernel-dependency-install-plan` fetches the Kernel-published install contract
through `AtlasKernelClient` for smoke validation; it still never installs,
imports SDK modules or starts a daemon.

`promotion-review-packet` is the operator handoff bundle. It runs the governed
certification path with `--require-sdk`, folds in `product-loop-check` and
Rivals-Voice summaries, emits canonical evidence hashes and preserves
`promotion_allowed=false`, `auto_promotion_allowed=false` and
`daemon_started=false`.

`token-issuer-plan` is the governed path for LiveKit token configuration. It
reports missing local env keys and a redacted template, but does not write
`.env`, expose secrets, issue tokens, start workers or approve production.
`token-issuer-smoke` is the governed redacted issuance proof. It never prints an
`access_token`; it only returns token hash and segment count. The
`--ephemeral-test-config` variant is test-only, restores Laravel config before
exit and always reports that production readiness is not proven by ephemeral
config.

`pre-start-health-checks-smoke` is the governed proof for the last checkpoint
before a future subprocess start. It writes only a temporary placeholder
`atlas-voice-*.env`, returns a redacted `<temporary-managed-env-file>` path,
executes synthetic safe pre-start checks, evaluates the subprocess start
contract, removes the temporary file and returns `passed_no_process_start`. It
is a contract smoke, not a production LiveKit proof.

`--callback-loop-wired` and `--production-sdk-loop-wired` are product-loop
wiring flags for guarded checks only. They make `worker-plan`,
`production-loop-plan`, `activation-contract` and `worker-start-check` show the
next gate state, but they do not start a daemon, promote production, persist
audio or authorize direct provider/tool calls.

`product-loop-check` is the aggregate readiness artifact for the next block. It
combines callback-loop, production-loop-plan and worker-start with product wiring
enabled, but preserves `daemon_started=false` and `auto_promotion_allowed=false`.
For the final governed promotion path, pass both
`--production-promotion-review-file` and `--promotion-review-bundle-file`; the
runtime rejects stale human receipts unless the reviewed bundle hash, schema and
machine-gate status match the current Kernel bundle exactly.
