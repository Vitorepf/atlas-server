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

Bootstrap:

```bash
php artisan atlas:ai:voice bootstrap --base-url=http://localhost --json \
  > runtimes/python/voice_realtime/bootstrap.local.json
python3 -m unittest discover -s runtimes/python/voice_realtime/tests
python3 -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --check
cp runtimes/python/voice_realtime/.env.example runtimes/python/voice_realtime/.env.local
python3 -m atlas_voice_agent.main --env-file runtimes/python/voice_realtime/.env.local --check
ATLAS_BASE_URL=http://localhost ATLAS_TOKEN=token LIVEKIT_URL=http://localhost:7880 \
  LIVEKIT_API_KEY=key LIVEKIT_API_SECRET=secret \
  ATLAS_VOICE_BOOTSTRAP=runtimes/python/voice_realtime/bootstrap.local.json \
  python3 -m atlas_voice_agent.main --env --check
```

LiveKit Agents SDK should be added here only after this scaffold remains green.
