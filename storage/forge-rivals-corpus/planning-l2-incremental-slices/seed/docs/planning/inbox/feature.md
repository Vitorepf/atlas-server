# Feature spec — Inbox Capture Ingestion Pipeline

## Objective
Wire the manual capture intake (`POST /api/inbox/capture`) end to end:
operator submits a captured note → the system normalizes the payload,
persists it, indexes it for search, and notifies the inbox screen via the
websocket channel `inbox.capture.created`.

## Acceptance criteria
1. Payload must be normalized through `CaptureNormalizer` before storage
   (trim, collapse whitespace, casefold tag list).
2. `CaptureRepository::store($capture)` writes a row and emits a domain
   event `CaptureStored`.
3. The search index (`CaptureSearchIndex`) ingests the new capture
   asynchronously, picking it up from the domain event.
4. The websocket gateway publishes the canonical projection on the
   channel `inbox.capture.created` so the desktop UI updates without
   polling.
5. The pipeline is idempotent on `client_request_id`: the same id may
   be submitted twice without producing duplicate rows or duplicate
   broadcasts.
6. No work is done synchronously beyond persistence; indexing and
   broadcast happen via the queued event bus.

## Constraints
- No new third-party dependency.
- The websocket gateway must remain a leaf module (no inbound import
  from `Inbox` or `Captures`).
- The feature must ship behind a single feature flag `inbox_capture_v1`.
