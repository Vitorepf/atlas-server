# REST endpoints

The capture and ingestion REST API is the original V1 product surface. All endpoints are protected by `X-Atlas-Token` except `GET /health`. Writes are idempotent by `client_id`. The `/sync` envelope handles bidirectional sync. Raw captures are cognitively quarantined until human ratification.

## Public endpoint

| Method | Path | Description |
|--------|------|-------------|
| GET | `/health` | Basic health check (no auth) |

## Captures

| Method | Path | Description |
|--------|------|-------------|
| POST | `/captures` | Create a capture (text, audio, photo). Audio/photo use `multipart/form-data` with file in the `file` field. |
| GET | `/captures` | List captures |
| GET | `/captures/{id}` | Get a single capture |
| PATCH | `/captures/{id}` | Update a capture |
| DELETE | `/captures/{id}` | Soft-delete a capture |
| GET | `/captures/{id}/file` | Download the capture file |
| GET | `/captures/{id}/transcription` | Get the transcription (audio captures) |
| POST | `/captures/{id}/transcription/retry` | Retry transcription |
| POST | `/captures/{id}/semantic/clarify` | Run the semantic clarifier |
| POST | `/captures/{id}/triage` | Triage a capture (archive/snooze/note/task/project/proposal/hypothesis) |

Captures have a domain (`blackink`, `saude`, `financas`, `outro`) that determines privacy policy. The `client_id` UUID provides idempotent upsert: replaying the same `client_id` updates rather than duplicates.

## Check-ins

| Method | Path | Description |
|--------|------|-------------|
| POST | `/checkins` | Create a check-in (state + energy_level/mood_level 1-5 + note) |
| GET | `/checkins` | List check-ins |
| PATCH | `/checkins/{id}` | Update a check-in |
| DELETE | `/checkins/{id}` | Soft-delete a check-in |

## Passive signals

| Method | Path | Description |
|--------|------|-------------|
| POST | `/passive-signals` | Create a passive signal (source=healthkit or rize, signal_type, value, unit, time window) |
| GET | `/passive-signals` | List passive signals |
| PATCH | `/passive-signals/{id}` | Update a passive signal |
| DELETE | `/passive-signals/{id}` | Soft-delete a passive signal |

## Health snapshots

| Method | Path | Description |
|--------|------|-------------|
| POST | `/health-snapshots` | Create a health snapshot |
| GET | `/health-snapshots` | List health snapshots |
| PATCH | `/health-snapshots/{id}` | Update |
| DELETE | `/health-snapshots/{id}` | Soft-delete |

## Sensor 4: digital activity

| Method | Path | Description |
|--------|------|-------------|
| GET | `/digital-category-mappings` | List category mappings |
| POST | `/digital-category-mappings` | Create a category mapping |
| PATCH | `/digital-category-mappings/{id}` | Update |
| DELETE | `/digital-category-mappings/{id}` | Delete |
| GET | `/digital-sessions` | List digital sessions (granular per-app events) |
| POST | `/digital-sessions` | Create a digital session |
| PATCH | `/digital-sessions/{id}` | Update |
| DELETE | `/digital-sessions/{id}` | Delete |
| GET | `/digital-activity-snapshots` | List daily activity aggregates |
| POST | `/digital-activity-snapshots` | Create a daily snapshot |
| POST | `/digital-activity-snapshots/rebuild` | Rebuild snapshots from sessions |
| PATCH | `/digital-activity-snapshots/{id}` | Update |
| DELETE | `/digital-activity-snapshots/{id}` | Delete |
| GET | `/procrastination-events` | List procrastination events |
| POST | `/procrastination-events` | Create a procrastination event |

Sensor 4 uses two layers: `digital_sessions` for granular per-app/site/project events and `digital_activity_snapshots` for daily aggregates correlatable with health, check-ins, captures, and missions. Category classes (1-10) classify intentionality: 1=deep work, 3=curated input, 4=algorithmic input, 5=intentional entertainment, 6=default entertainment, 7=communication primary, 8=communication shallow, 9=market.

## Mission

| Method | Path | Description |
|--------|------|-------------|
| GET | `/mission/today` | Get today's mission |
| PUT | `/mission/today` | Create or update today's mission (timezone, title, detail, status, metadata) |

## Sync

| Method | Path | Description |
|--------|------|-------------|
| POST | `/sync` | Bidirectional sync envelope. Device uploads `*_to_upload` arrays; server returns rows changed since `last_sync_at`. Text captures only (JSON); audio/photo use `POST /captures` multipart. Uses `withTrashed` to sync deletions. |

The `/sync` envelope is the primary sync mechanism. The device sends arrays of captures, checkins, passive-signals, digital-sessions, digital-activity-snapshots, and procrastination-events to upload, plus a `last_sync_at` cursor. The server upserts by `client_id`, then returns all rows changed since `last_sync_at` (including soft-deleted ones for deletion sync).

## AI interactions

| Method | Path | Description |
|--------|------|-------------|
| POST | `/ai/interactions` | Create an AI interaction (returns 202 with trace) |
| GET | `/ai/interactions` | List interactions |
| GET | `/ai/interactions/{id}` | Get a single interaction |
| GET | `/ai/interactions/{id}/stream` | SSE stream of interaction result |
| POST | `/ai/interactions/{id}/feedback` | Submit feedback on an interaction |
| GET | `/ai/jobs` | List AI jobs |
| GET | `/ai/jobs/{id}` | Get a single job |
| POST | `/ai/jobs/{id}/retry` | Retry a failed job |
| POST | `/ai/jobs/{id}/cancel` | Cancel a queued job |
| GET | `/ai/providers/status` | Provider health status |
| POST | `/ai/providers/check` | Run a live provider check |

See [AI Gateway](../systems/ai-gateway/index.md) for the full interaction lifecycle.

## Rize integration

| Method | Path | Description |
|--------|------|-------------|
| POST | `/integrations/rize/webhook` | Rize webhook (optional). Accepts `X-Rize-Webhook-Secret` header or `?secret=` param. In local environments, accepts `X-Atlas-Token` when `RIZE_WEBHOOK_SECRET` is not set. |

The primary Rize integration is pull-based (API), not webhook. The `RIZE_API_KEY` stays on the server only. The iPhone app never receives it.

```bash
php artisan atlas:rize:sync --days=30    # pull sync
php artisan atlas:rize:inspect           # inspect the GraphQL schema
```

## Idempotency by client_id

All ingestion endpoints use `client_id` (a client-generated UUID) for idempotent upsert. The database enforces a UNIQUE constraint on `client_id`. Replaying the same request with the same `client_id` updates the existing row rather than creating a duplicate. This makes sync safe to retry.

## Cognitive quarantine

A raw capture is not memory, context, decision, or learning signal. It is blocked from embedding, provider-export, and the Open Brain until a human ratifies a curation proposal. The capture is stored with quarantine metadata. Only after ratification can the content enter the semantic pipeline. See [cognitive quarantine and privacy](../systems/capture-ingestion/cognitive-quarantine-and-privacy.md).

## Related pages

- [API](index.md) — the two API surfaces
- [Capture and ingestion](../systems/capture-ingestion/index.md) — the V1 data backend
- [Sync protocol](../systems/capture-ingestion/sync-protocol.md) — the sync envelope in detail
- [Cognitive quarantine and privacy](../systems/capture-ingestion/cognitive-quarantine-and-privacy.md) — quarantine lifecycle
- [AI Gateway](../systems/ai-gateway/index.md) — the AI interaction endpoints
- [Security](../security.md) — auth and trust boundaries
