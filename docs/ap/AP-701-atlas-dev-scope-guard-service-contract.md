# AP-701 Atlas Dev Scope Guard Service Contract

Status: proposed
Owner: atlas-ai
Area: atlas-dev-scope-guard
Risk: medium

## Problem

Atlas Dev Patamar A3 requires every provider write to be VERIFIED
against the declared scope (`tool_permissions.allowed_files` /
`forbidden_files`). Today the controller has a coarse
`rejectUnsafeAssistedExecution` check that aborts the request when
the Dev runtime declares the context unsafe; there is no per-write
guard that rejects out-of-scope file mutations after the provider
returns a patch. Without this guard, a provider can silently touch
files the operator did not authorize and the system would accept it.

## Goal

Deliver a `AtlasDevScopeGuardService` that evaluates a proposed write
set against the declared scope and returns a canonical decision +
`atlas.dev.scope_guard.v1` envelope. Pure read-only service (no
mutation): callers (the AiWorker post-provider step, or any future
write-applier) consult the service before persisting any file.

- Schema `atlas.dev.scope_guard.v1` with `decision` in
  `{allow, deny}`, `allowed_writes[]`, `denied_writes[]` and
  `reason_per_denied{path -> code}`.
- Decision is `deny` if ANY proposed write is outside the allowed
  set or inside the forbidden set.
- Empty `allowed_files` → conservative default: deny all writes
  (forces the operator to declare scope explicitly).
- Empty proposed writes → `allow` (no-op).
- The service does NOT call the OS to inspect or apply files; it
  evaluates path strings against the declared sets.
- Decision envelope is canonical and includes a deterministic hash
  of the evaluation input (used by Evidence Ledger).

## Non Goals

- Not applying or rejecting actual file writes (that is the AiWorker
  / writer service responsibility).
- Not auto-expanding scope (operator must edit the declared list).
- Not changing HTTP controller behavior in this AP (integration is
  a separate AP-701b).

## Overlap Decision

| Candidate | Decision |
|---|---|
| `payload.tool_permissions.allowed_files` / `forbidden_files` | Reuse: service reads them as input. |
| `AiInteractionController::rejectUnsafeAssistedExecution` | Reuse: continues to guard pre-provider; scope guard is post-provider. |
| `AtlasDevRuntimeService` | Reuse: scope guard is a sibling service. |

## Required Docs

- `docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md`
- `docs/ap/AP-701-atlas-dev-scope-guard-service-contract.md` (this AP)

## Acceptance Criteria

- `AtlasDevScopeGuardService` exists at
  `app/Services/Ai/Programming/AtlasDev/AtlasDevScopeGuardService.php`.
- `evaluate(array $proposedWrites, array $allowedFiles, array $forbiddenFiles): array`
  returns canonical decision envelope.
- Empty allowed_files → deny all writes.
- Glob-style prefix matching: `allowed_files = ['app/**']` allows
  `app/Foo.php` but not `database/migrations/2026.php`.
- Forbidden takes precedence over allowed.
- Unit tests cover allow / deny / mixed / empty-input scenarios.

## Rollback

Service is read-only; rollback = unregister the service binding. No
data mutation to revert.

## Risks

- **Low**: glob matching can have edge cases. Mitigation: use a
  conservative `fnmatch` style with explicit prefix expansion; tests
  cover the boundary cases.

## What This AP Is NOT

- Not a HTTP integration.
- Not a file writer.
