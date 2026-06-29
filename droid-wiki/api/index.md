# API

Atlas Server exposes two API surfaces. Both are thin HTTP layers on top of the Laravel application, and both are protected by the static `X-Atlas-Token` header. The only public endpoint is `GET /health`.

## The two surfaces

### Capture and ingestion REST API

The original V1 product surface. Receives captures (text, audio, photo), check-ins, passive health signals, Sensor 4 digital activity, and the daily mission from the operator's iPhone/Mac app. All writes are idempotent by `client_id`. The `/sync` envelope handles bidirectional sync. Raw captures are cognitively quarantined until human ratification.

See [REST endpoints](rest-endpoints.md) for the full endpoint reference.

### AI gateway API

The surface through which the app and CLI create AI interactions, poll job status, manage threads, check provider health, and access the Open Brain (memory recall, context packs, MCP). An interaction creates a trace and a job; a local worker runs the provider CLI and streams the result back.

See [AI Gateway](../systems/ai-gateway/index.md) for the interaction lifecycle and [Open Brain](../systems/open-brain/index.md) for the memory and context pack endpoints.

## Authentication

All protected endpoints require the `X-Atlas-Token` header, set to the `ATLAS_TOKEN` environment variable:

```bash
curl -H "X-Atlas-Token: $ATLAS_TOKEN" http://localhost:3737/captures
```

The mobile API (`/api/v1/mobile/*`) uses a separate bearer token middleware (`atlas.mobile.bearer`) for paired devices.

## Base URL

The server runs on port 3737:

```
http://localhost:3737
```

In production, the server runs behind Tailscale on a private network. There is no public internet exposure by design. See [security](../security.md).

## Related pages

- [REST endpoints](rest-endpoints.md) — full capture/ingestion endpoint reference
- [AI Gateway](../systems/ai-gateway/index.md) — the AI interaction lifecycle
- [Open Brain](../systems/open-brain/index.md) — memory and context pack endpoints
- [Capture and ingestion](../systems/capture-ingestion/index.md) — the V1 data backend
- [Security](../security.md) — auth, trust boundaries, and the full security model
