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
python3 -m unittest discover -s runtimes/python/voice_realtime/tests
python3 -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --check
cp runtimes/python/voice_realtime/.env.example runtimes/python/voice_realtime/.env.local
python3 -m atlas_voice_agent.main --env-file runtimes/python/voice_realtime/.env.local --check
python3 -m atlas_voice_agent.main --env-file runtimes/python/voice_realtime/.env.local --preflight
python3 -m atlas_voice_agent.main --env-file runtimes/python/voice_realtime/.env.local --activation-contract
python3 -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --sdk-check
python3 -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --worker-plan
python3 -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --callback-loop-check
python3 -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --production-loop-plan
python3 -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --mock-kernel --sdk-events runtimes/python/voice_realtime/sdk-events.example.json
python3 -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --start-worker
ATLAS_BASE_URL=http://localhost ATLAS_TOKEN=token LIVEKIT_URL=http://localhost:7880 \
  LIVEKIT_API_KEY=key LIVEKIT_API_SECRET=secret \
  ATLAS_VOICE_BOOTSTRAP=runtimes/python/voice_realtime/bootstrap.local.json \
  python3 -m atlas_voice_agent.main --env --check
python3 -m atlas_voice_agent.main --env-file runtimes/python/voice_realtime/.env.local \
  --scripted-events runtimes/python/voice_realtime/scripted-events.example.json
python3 -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json \
  --mock-kernel --callback-event runtimes/python/voice_realtime/callback-event.example.json
python3 -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json \
  --mock-kernel --callback-events runtimes/python/voice_realtime/callback-events.example.json
php artisan atlas:ai:voice scripted-smoke --json
php artisan atlas:ai:voice preflight --json
php artisan atlas:ai:voice activation-contract --json
php artisan atlas:ai:voice sdk-check --json
php artisan atlas:ai:voice worker-plan --json
php artisan atlas:ai:voice callback-loop-check --json
php artisan atlas:ai:voice production-loop-plan --json
php artisan atlas:ai:voice production-loop-smoke --json
php artisan atlas:ai:voice worker-start-check --json
php artisan atlas:ai:voice runtime-certify --json
curl -H "X-Atlas-Token: $ATLAS_TOKEN" "http://localhost/ai/voice/runtime/certification?runtime=livekit_agents_sdk"
```

LiveKit Agents SDK should be added here only after this scaffold remains green.
