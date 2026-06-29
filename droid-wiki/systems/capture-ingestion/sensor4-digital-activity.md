# Sensor 4: digital activity

Sensor 4 is the digital-activity tracking layer. It records how the operator spends time on devices and correlates that with health, check-ins, captures, and missions. It has two granularities: granular per-app sessions and daily aggregate snapshots, plus procrastination events. A third-party time tracker (Rize) can feed sessions via a server-side pull sync or inbound webhooks.

## Purpose

Sensor 4 turns raw screen-time and time-tracking data into a structured daily picture: how many minutes of deep work, curated input, algorithmic feed, entertainment, communication, and market activity. This is correlatable with check-ins (state/energy/mood), health snapshots (sleep/HRV), captures (what the operator was thinking about), and the daily mission. The category taxonomy and temporal classification make it possible to ask "was this hour of YouTube research (category 3) or doomscrolling (category 6)?"

## Key abstractions

| Path | Role |
|------|------|
| `app/Models/DigitalSession.php` | One app/website/project usage interval with `category_class_at_time` (1-10), intentionality, duration, focus mode. |
| `app/Models/DigitalActivitySnapshot.php` | Daily rollup: total screen time, deep-work count/min, category breakdowns, quality score. |
| `app/Models/DigitalCategoryMapping.php` | Temporal classification of a source (app/domain/url/project) into a category class; history via `valid_from`/`valid_until`. |
| `app/Models/ProcrastinationEvent.php` | Sensor 4 avoidance detection with mission/physiological/subjective/digital context. |
| `app/Models/DigitalImportEvent.php` | Receipt for a Rize import run (received/processed/failed). |
| `app/Services/Digital/DigitalActivitySnapshotBuilder.php` | Recomputes a day's snapshot from sessions. |
| `app/Services/Digital/RizeApiClient.php` | Rize GraphQL client (timeEntries query, paging). |
| `app/Services/Digital/RizeApiIngestor.php` | Rize pull-sync pipeline: fetch, normalize, upsert, rebuild snapshots. |
| `app/Services/Digital/RizeSessionNormalizer.php` | Canonicalizes Rize payloads and applies category mapping. |
| `app/Http/Controllers/DigitalSessionController.php` | Session upsert/list with filters. |
| `app/Http/Controllers/DigitalActivitySnapshotController.php` | Snapshot CRUD + rebuild endpoint. |
| `app/Http/Controllers/DigitalCategoryMappingController.php` | Category mapping CRUD with temporal validity. |

## How it works

### Sessions vs snapshots

**Digital sessions** (`digital_sessions` table) are the raw events: one row per app/site/project usage interval. Each session has:

- `source` — `rize`, `screentime`, `manual`, or `import`
- `source_identifier` / `source_name` / `source_kind` — what was used (app bundle id, domain, URL, project name)
- `category_class_at_time` — integer 1-10 (nullable if unclassified)
- `intentionality` — `intentional`, `default`, `mixed`, or `unknown`
- `started_at` / `ended_at` / `duration_seconds`
- `focus_mode_active`, `project_name`, `task_name`, `url_domain`, `productivity_score`
- `linked_capture_id` / `linked_decision_id` — optional links to a capture or decision
- `raw_payload` / `metadata` — JSONB

Sessions arrive via `POST /digital-sessions` (app sync), via `/sync` (`digital_sessions_to_upload`), or via the Rize pull sync.

**Digital activity snapshots** (`digital_activity_snapshots` table) are daily aggregates computed from sessions. Each snapshot has:

- `snapshot_date` / `snapshot_timezone`
- `total_screen_time_min`
- `deep_work_sessions_count` / `deep_work_total_min` (sessions in category 1 lasting 25+ minutes)
- `curated_input_min` (categories 1+3), `algorithmic_input_min` (4), `intentional_entertainment_min` (5), `default_entertainment_min` (6), `communication_primary_min` (7), `communication_shallow_min` (8), `market_min` (9)
- `focus_mode_active_min`, `category_breakdown`, `source_breakdown` — JSONB breakdowns
- `metadata.quality` — a 0-100 quality score with warnings

Snapshots arrive via `POST /digital-activity-snapshots` (app sync), via `/sync`, or are recomputed server-side via `POST /digital-activity-snapshots/rebuild`.

### Category class 1-10 taxonomy

The `category_class_at_time` field uses an intentionality taxonomy:

| Class | Meaning |
|-------|---------|
| 1 | Deep work |
| 2 | (reserved) |
| 3 | Curated input |
| 4 | Algorithmic input |
| 5 | Intentional entertainment |
| 6 | Default entertainment |
| 7 | Communication primary |
| 8 | Communication shallow |
| 9 | Market |
| 10 | (reserved) |

The distinction between curated input (3, e.g. a chosen educational video) and algorithmic input (4, e.g. a YouTube feed) is what makes the taxonomy useful for correlating attention quality with outcomes.

### DigitalCategoryMapping temporal classification

`DigitalCategoryMapping` in `app/Models/DigitalCategoryMapping.php` classifies a source (by `source_identifier` and `source_kind`) into a category class. The classification is **temporal**: each mapping has `valid_from` and `valid_until` timestamps. When the operator reclassifies a source (e.g. "YouTube is now category 6 instead of 3"), the old mapping is closed (`valid_until` set) and a new one is created (`valid_from` set). This preserves history: a session from last month keeps its original classification, while a session today gets the new one.

`RizeSessionNormalizer::mappingFor` resolves the mapping at a point in time by querying `WHERE valid_from <= at AND (valid_until IS NULL OR valid_until > at)`, ordered by specificity (exact identifier+kind match first, then identifier-only, then kind-only, then fallback). The resolved mapping's `confidence` is recorded in the session's `metadata.classification`.

### DigitalActivitySnapshotBuilder

`DigitalActivitySnapshotBuilder` in `app/Services/Digital/DigitalActivitySnapshotBuilder.php` recomputes a day's snapshot from sessions. `rebuild(date, timezone, source)`:

1. Loads all sessions overlapping the day (in the given timezone), attaching a `_snapshot_window` to each.
2. Sums seconds-within-window per category (`sumByCategory`), per source (`sumBySource`), and per focus mode (`sumByFocusMode`). The window clipping matters: a session spanning midnight is split across two days.
3. Counts deep-work sessions (category 1, 25+ minutes within the window).
4. Computes category minutes: curated (1+3), algorithmic (4), intentional entertainment (5), default entertainment (6), communication primary (7), shallow (8), market (9).
5. Computes a quality score (0-100) from four factors: total seconds (35 pts), classification ratio (35 pts), weighted classification confidence (20 pts), and native iPhone source presence (10 pts). The score is capped at 72 when the native iPhone Screen Time source is absent, because Rize-only data has known blind spots. Warnings include `no_digital_sessions`, `no_category_classification`, `partial_category_classification`, `low_mapping_confidence`, and `native_iphone_source_absent`.
6. `updateOrCreate` by a deterministic `client_id` (hash of `digital-snapshot:<source>:<timezone>:<date>`).

### Procrastination events

`POST /procrastination-events` upserts by `client_id`. Each event carries JSONB context blocks: `mission_context`, `physiological_state`, `subjective_state`, `digital_context`. The operator can confront or dismiss the event (`operator_response`: accepted, dismissed, snoozed, false_positive). The `rule_version` is `sensor4-v1`.

## Rize integration

Rize is a third-party time-tracking service. Atlas can pull sessions from it or receive them via webhook.

### Pull sync (server-side)

`RizeApiIngestor::sync(from, to, pageSize)` in `app/Services/Digital/RizeApiIngestor.php`:

1. Creates a `DigitalImportEvent` row (source: `rize`, event_type: `api.sync`) with the raw payload.
2. Pages through `RizeApiClient::fetchSessions` (GraphQL `timeEntries` query with cursor pagination).
3. For each record, `RizeSessionNormalizer::normalize` canonicalizes the heterogeneous Rize payload into a session array: resolves the source identifier/name/kind from many possible field paths, resolves the category mapping at the session's start time, computes a deterministic `client_id` from a hash of the source event id (or identifier+timestamps), and records the classification in metadata.
4. `DigitalSession::updateOrCreate` by `client_id` (idempotent).
5. Rebuilds snapshots for each touched date+timezone (unique pairs).
6. Updates the `DigitalImportEvent` to `processed` or `failed` with stats.

The scheduled command `atlas:rize:sync` drives this when `RIZE_SYNC_ENABLED` is on and `RIZE_API_KEY` is set. The API key is server-side only; the device never sees it.

### Inbound webhook

`POST /integrations/rize/webhook` (authenticated by a webhook secret, or locally by `X-Atlas-Token`) calls `RizeWebhookIngestor`, which ingests one event and records a `DigitalImportEvent`.

### Rize config

Config lives in `config/services.php` under `services.rize`:

| Key | Purpose |
|-----|---------|
| `api_key` | Server-side Rize API key (`RIZE_API_KEY`). |
| `graphql_endpoint` | Rize GraphQL endpoint. |
| `timezone` | Timezone for normalized sessions. |
| `webhook_secret` | Secret for validating inbound webhooks. |
| `sync_enabled` | Master switch for the scheduled pull (`RIZE_SYNC_ENABLED`). |
| `sync_lookback_days` | How far back to pull on each sync. |
| `sync_page_size` | Page size for GraphQL paging. |
| `sessions_query_path` / `root_path` / `page_info_path` | Path overrides for the GraphQL response shape. |

## Integration points

- [Sync protocol](sync-protocol.md) — `/sync` accepts `digital_sessions_to_upload` and `digital_activity_snapshots_to_upload`.
- [Captures API and lifecycle](captures-and-lifecycle.md) — a session can link to a capture via `linked_capture_id`; deletion nulls this pointer.
- [Cognitive quarantine and capture privacy](cognitive-quarantine-and-privacy.md) — digital context can be attached to a capture via `pre_capture_digital_context`.

## Key source files

| Path | Purpose |
|------|---------|
| `app/Models/DigitalSession.php` | Granular session model. |
| `app/Models/DigitalActivitySnapshot.php` | Daily aggregate model. |
| `app/Models/DigitalCategoryMapping.php` | Temporal category classification model. |
| `app/Models/ProcrastinationEvent.php` | Procrastination event model. |
| `app/Models/DigitalImportEvent.php` | Rize import receipt model. |
| `app/Services/Digital/DigitalActivitySnapshotBuilder.php` | Snapshot recompute logic. |
| `app/Services/Digital/RizeApiClient.php` | Rize GraphQL client. |
| `app/Services/Digital/RizeApiIngestor.php` | Rize pull-sync pipeline. |
| `app/Services/Digital/RizeSessionNormalizer.php` | Rize payload canonicalizer + category resolver. |
| `app/Http/Controllers/DigitalSessionController.php` | Session REST controller. |
| `app/Http/Controllers/DigitalActivitySnapshotController.php` | Snapshot REST controller with rebuild. |
| `app/Http/Controllers/DigitalCategoryMappingController.php` | Category mapping REST controller. |
| `app/Http/Controllers/ProcrastinationEventController.php` | Procrastination event controller. |
| `config/services.php` | `services.rize.*` config keys. |
