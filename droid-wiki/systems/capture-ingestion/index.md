# Capture and ingestion

The capture and ingestion layer is the original V1 personal-data backend that predates the autonomous-AI platform. It receives every signal the operator's iPhone, Mac, and Watch produce and persists it as the canonical store. All write paths are idempotent by client-supplied UUID (`client_id`), exposed both as individual REST resources and through one bidirectional `POST /sync` envelope. A defining architectural rule sits on top: raw captures are cognitively quarantined and never treated as memory, context, or learning signal until a human ratifies a curation proposal.

## The five ingestion shapes

| Shape | What it is | Primary endpoint |
|-------|-----------|-----------------|
| **Captures** | Raw operator input: text, audio (transcribed locally with whisper.cpp), or photo. Classified into a domain and stamped with cognitive-quarantine metadata on creation. | `POST /captures` |
| **Check-ins** | Momentary self-report: state (focused, disperse, blocked, pause), energy level (1-5), mood level (1-5), and a note. | `POST /checkins` |
| **Passive signals** | Auto-collected metrics from HealthKit or Rize: a signal type plus a numeric or text value, unit, and time window. | `POST /passive-signals` |
| **Sensor 4 (digital activity)** | Two layers: granular `digital_sessions` per app/site/project and daily `digital_activity_snapshots` aggregates, plus `procrastination_events`. | `POST /digital-sessions`, `POST /digital-activity-snapshots` |
| **Daily mission** | The single objective for a date, read by the Apple Watch complication. | `GET /mission/today`, `PUT /mission/today` |

## Idempotency by client_id

Every ingestion endpoint upserts by a client-generated UUID (`client_id`). If a row with the same `client_id` already exists (including soft-deleted rows), the server updates it in place or restores it, rather than creating a duplicate. This makes the device's sync safe to retry: the same upload never produces two rows. For captures, `CaptureService::create` checks `Capture::withTrashed()->where('client_id', ...)` first, and if found, records a `capture_replayed` audit event and returns the existing capture with `created: false`.

## The /sync envelope

Instead of calling each endpoint separately, the device can make one `POST /sync` round-trip that uploads changes across all domains and downloads server-side changes since a cursor. The request body carries `*_to_upload` arrays (captures, checkins, passive_signals, health_snapshots, behaviors, behavior_logs, digital_sessions, digital_activity_snapshots); the response returns `*_downloaded` collections filtered by `updated_at > last_sync_at` (limit 200 per domain, `withTrashed`). Each sync is logged in `sync_log`. One constraint: `/sync` accepts only **text** captures in JSON; audio and photo binaries must go through the multipart `POST /captures` endpoint. See [sync protocol](sync-protocol.md) for the full envelope spec.

## Cognitive quarantine philosophy

A raw capture is not memory, context, a decision, or a learning signal. On creation, `CaptureService` stamps every capture with three metadata blocks:

- **`cognitive_quarantine`** — flags like `memory_eligible: false`, `embedding_allowed: false`, `provider_export_allowed: false`, `open_brain_context_allowed: false`, plus a `promotion_status` that starts at `unclassified` and moves to `proposal_pending` once a curation proposal exists.
- **`content_intelligence`** — a quality score (0-100), a destination enum (semantic_note, task, project, archive, discard, etc.), and privacy flags, all set to `proposal_required_before_promotion`.
- **`immune_audit`** — an explicit master invariant (`raw_capture_not_evidence_not_learning_signal_not_memory_not_context_not_decision`) and a promotion-gate ladder (g0 through g8) that records which gates have fired.

The only way out of quarantine is a human-review promotion gate: the operator ratifies a semantic-curation proposal (or a memory delta), which moves the capture toward canonical memory. See [cognitive quarantine and capture privacy](cognitive-quarantine-and-privacy.md) for the full mechanism.

## Where it hands off to the AI platform

When a capture has text (either directly or after transcription), `CaptureService` runs a semantic pipeline: `CaptureSemanticClarifier::handleReady` produces a clarification, `CurationProposalService::createFromCapture` creates a pending proposal, and `ActivationEngine::createForContext` records an activation. These are the boundary into the AI/knowledge area. The proposal sits behind a human gate (ratify or dismiss) before anything becomes memory. Once ratified, the capture's content can flow into the [AI Gateway](../ai-gateway/index.md) as context and into the [Open Brain](../open-brain/index.md) as canonical memory.

## Directory layout

```
app/
  Http/
    Controllers/
      CaptureController.php          # captures CRUD, file, transcription, triage, clarify
      CheckinController.php          # check-in upsert + cursor list
      PassiveSignalController.php    # HealthKit/Rize passive signal upsert
      DigitalSessionController.php   # Sensor 4 granular session upsert/list
      DigitalActivitySnapshotController.php  # daily aggregate CRUD + rebuild
      DigitalCategoryMappingController.php   # app/site -> category classification
      ProcrastinationEventController.php     # Sensor 4 procrastination ingest
      DailyMissionController.php     # mission/today get + upsert
      SyncController.php             # bidirectional /sync envelope
      HealthController.php           # public /health probe
      RizeWebhookController.php      # inbound Rize webhook
    Requests/
      StoreCaptureRequest.php        # ...and per-domain Store/Update/Index requests
      SyncRequest.php                # validates the /sync envelope
    Resources/
      CaptureResource.php            # capture projection + capture_safety + review_workflow
      ...Resource.php                # one per domain
  Services/
    CaptureService.php               # capture lifecycle orchestrator
    CaptureDestinationService.php    # triage routing -> task/project/note/proposal
    CaptureFileStorage.php           # content-addressed (sha256) binary storage
    CapturePrivacyService.php        # sensitivity + external-AI gating
    CaptureDeletionService.php       # hard delete + purge derived projections
    WhisperTranscriber.php           # whisper.cpp wrapper with quality gate
    AtlasDomainRegistry.php          # domain slugs + per-domain privacy policy
    AuditLogService.php              # append audit/evidence events
    Digital/
      DigitalActivitySnapshotBuilder.php  # recompute daily snapshot from sessions
      RizeApiClient.php              # Rize GraphQL client
      RizeApiIngestor.php            # Rize pull-sync pipeline
      RizeSessionNormalizer.php      # canonicalize Rize payloads + apply category mapping
    Semantic/                        # downstream: clarifier, curation proposals, activation
  Jobs/
    ProcessAudioTranscription.php    # async whisper transcription + post-transcription pipeline
  Models/
    Capture.php  CaptureLink.php  Checkin.php  PassiveSignal.php
    DigitalSession.php  DigitalActivitySnapshot.php  DigitalCategoryMapping.php
    ProcrastinationEvent.php  DigitalImportEvent.php  DailyMission.php
    TranscriptionJob.php  SyncLog.php  HealthSnapshot.php
    Behavior.php  BehaviorLog.php
```

## Key abstractions

| Path | Role |
|------|------|
| `app/Services/CaptureService.php` | Capture lifecycle orchestrator: idempotency, quarantine metadata, transcription dispatch, triage, clarify. |
| `app/Services/CaptureDestinationService.php` | Triage routing to task/project/note/proposal + supersede logic; writes `capture_links`. |
| `app/Services/CaptureFileStorage.php` | Content-addressed (sha256) binary storage with dedupe on the `atlas` disk. |
| `app/Services/CapturePrivacyService.php` | Sensitivity normalization + external-AI gating per domain. |
| `app/Services/CaptureDeletionService.php` | Hard delete + purge of all derived projections; nulls source pointers on surviving objects. |
| `app/Jobs/ProcessAudioTranscription.php` | Async whisper transcription + post-transcription semantic pipeline. |
| `app/Services/WhisperTranscriber.php` | whisper.cpp engine wrapper with fail-closed quality gate. |
| `app/Http/Controllers/SyncController.php` | Bidirectional multi-domain sync envelope. |
| `app/Services/Digital/DigitalActivitySnapshotBuilder.php` | Recomputes daily digital aggregates from raw sessions. |
| `app/Services/Digital/RizeApiIngestor.php` | Rize pull-sync pipeline (sessions to snapshots). |
| `app/Services/AtlasDomainRegistry.php` | Domain vocabulary + per-domain privacy policy. |
| `app/Http/Resources/CaptureResource.php` | Capture projection with `capture_safety` and `review_workflow` blocks. |
| `config/atlas.php` | Storage path, upload cap, transcription engine, privacy gating config. |

## Integration points

- **AI Gateway** ([../ai-gateway/index.md](../ai-gateway/index.md)) — ratified captures can become context injected into AI interactions.
- **Open Brain** ([../open-brain/index.md](../open-brain/index.md)) — ratified captures can become canonical memory entries.
- **Anti-Goodhart** ([../../concepts/anti-goodhart.md](../../concepts/anti-goodhart.md)) — the no-proxy doctrine that shapes why captures are quarantined, not auto-promoted.
- **Provider-safety** ([../../concepts/provider-safety.md](../../concepts/provider-safety.md)) — why raw captures never cross to external AI.
- **REST API reference** ([../../api/rest-endpoints.md](../../api/rest-endpoints.md)) — the full HTTP endpoint catalog.

## Pages in this section

- [Captures API and lifecycle](captures-and-lifecycle.md) — endpoints, CaptureService, file storage, resource safety, deletion
- [Audio transcription pipeline](transcription-pipeline.md) — ProcessAudioTranscription, WhisperTranscriber, quality gate, anti-hallucination
- [Sensor 4: digital activity](sensor4-digital-activity.md) — sessions, snapshots, category taxonomy, Rize integration
- [Sync protocol](sync-protocol.md) — the /sync envelope, cursors, sync_log, text-only rule
- [Cognitive quarantine and capture privacy](cognitive-quarantine-and-privacy.md) — privacy service, quarantine metadata, human-review gate

## Key source files

| Path | Purpose |
|------|---------|
| `routes/api.php` | All HTTP routes; capture/ingestion group around lines 390-415 and 629 (`/sync`). |
| `config/atlas.php` | `storage_path`, `max_upload_bytes`, `transcription.*`, `privacy.block_external_ai_for_sensitivity`, `domains.defaults`. |
| `app/Services/CaptureService.php` | Capture create/update/triage/clarify/retryTranscription. |
| `app/Http/Controllers/SyncController.php` | The single `__invoke` bidirectional sync. |
| `app/Services/CapturePrivacyService.php` | Sensitivity and external-AI policy normalization. |
| `app/Services/AtlasDomainRegistry.php` | Domain slugs, default sensitivity, external-AI policy. |
