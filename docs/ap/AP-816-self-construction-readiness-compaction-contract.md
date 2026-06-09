---
id: AP-816-self-construction-readiness-compaction-contract
type: architecture_proposal
title: AP-816 Self-Construction Readiness Compaction Contract
status: accepted
owner: atlas-ai
created_at: 2026-06-09
summary: Governa a compactacao incremental de AtlasSelfConstructionReadinessService em helpers/read-models pequenos, read-only e compat-preserving, sem novo runtime, surface, provider flow ou schema paralelo.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os-compaction-plan.md
  - docs/engineering-knowledge-base/atlas-self-construction-catalog.md
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - app/Console/Commands/AtlasAiSelfConstructionMotherCommand.php
  - app/Console/Commands/Support/AtlasSelfConstructionMotherCommandSurface.php
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionOsCompletionAuditService.php
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php
  - app/Services/Ai/SelfConstruction/ReadinessCatalog.php
  - app/Services/Ai/SelfConstruction/ReadinessCommandSurface.php
  - app/Services/Ai/SelfConstruction/ReadinessCompletionClaimAuthority.php
  - app/Services/Ai/SelfConstruction/ReadinessDocumentProbe.php
  - app/Services/Ai/SelfConstruction/ReadinessHash.php
  - app/Services/Ai/SelfConstruction/ReadinessJsonInput.php
  - app/Services/Ai/SelfConstruction/ReadinessPathPolicy.php
  - tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php
requires_evidence: true
risk_level: high
---
# AP-816 Self-Construction Readiness Compaction Contract

## Decision

`AtlasSelfConstructionReadinessService` may be compacted only by extracting
pure read-only helpers/read-models inside `App\Services\Ai\SelfConstruction`.
The public readiness schema, CLI behavior, safety defaults and evidence claims
must stay compatible at every step.

This AP satisfies the dedicated compaction AP requested by
`atlas-self-construction-catalog.md` and
`atlas-ai-self-construction-os-compaction-plan.md`.

## Scope

Allowed:

- Move constant catalogs and path/doc probes into small local helpers.
- Move published mother-command surface maps into a small command support helper
  when payloads advertise CLI flags/options.
- Move one readiness family at a time into projection services.
- Keep old method names as wrappers until focused tests prove compatibility.
- Update owner docs with exact line counts and extracted families.

Forbidden:

- Creating a new runtime, provider flow, surface, command family or memory store.
- Deleting/quarantining SelfConstruction files without per-file ACRUI dead-code
  proof and operator Decision Receipt v2.
- Renaming public CLI options, schemas, packet keys or receipt hashes without a
  compatibility test and deprecated alias period.
- Moving write authority, ledger writes, dispatch, approval or provider start
  into extracted helpers.

## Extraction Order

1. `ReadinessStatus` support primitives: required docs, document status/content,
   changed-file/path classification, integrity summaries.
2. `PacketProjection`: meta-SDD, implementation packet, queue, runbook and
   evidence report projections.
3. `ReservationProjection`: durable reservation, collision, lease and readiness
   projections.
4. `AgentProjection`: ACP, heartbeat, liveness, cost and work-product views.
5. `DispatchProjection`: preflight, release/start packets and receipt templates.
6. `ReviewMergeProjection`: review, decision, signature and merge projections.
7. `PersistenceProjection`: persistence templates and authorization chains.

## Invariants

- `execution_allowed=false` remains the default for read-only status surfaces.
- Decision Receipt stays mandatory before write-capable work.
- Hot scope remains forbidden without exception receipt.
- Allowed/forbidden files remain explicit in generated packets.
- Operator dual signature remains required for hot runtime paths.
- Evidence ledger remains append-only.
- Replay/hash-chain fields remain deterministic.

## Validation

Minimum validation per slice:

```bash
php -l app/Services/Ai/SelfConstruction/<new-helper>.php
php -l app/Console/Commands/Support/<new-command-helper>.php
php -l app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php
php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter='test_command_returns_(self_construction_readiness|ownership_boundary|scope_validator)_as_json'
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:code-reality global-duplication-audit --summary --json
git diff --check
```

Run broader SelfConstruction tests when a slice moves packet hashes, reservation
logic, dispatch/readiness packets, approval fields or persistence templates.

## Acceptance

- Every extracted helper is local to SelfConstruction and has no DB, provider or
  ledger side effect unless a later AP explicitly permits it.
- The old service continues to expose the same public methods and payload keys.
- Published read-only CLI commands remain accepted by the mother command when
  readiness payloads advertise them.
- Published CLI flag/option maps are centralized in a command support helper
  instead of being appended directly to the mother command.
- Owner docs list the extracted family and measured line count.
- Code Intelligence and KB are refreshed after code/doc changes.
