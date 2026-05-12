---
id: atlas-ai-obras-shared-workspace-and-forge
type: engineering_knowledge
title: Atlas Obras - Shared Workspace And Forge
status: active
category: architecture
priority: 100
summary: Canonical contract for Obras Shared Workspace, the office where Forge and multiple providers collaborate through shared context, artifacts, evidence and integration.
tags:
  - atlas-ai
  - obras
  - forge
  - multi-provider
  - shared-workspace
capabilities:
  - obras_shared_workspace
  - forge_workspace
  - multi_provider_orchestration
  - programming_operating_system
decisions:
  - The canonical name is Obras Shared Workspace, with Portuguese label Workspace Compartilhado de Obras.
  - Forge Workspace is the Programming/Atlas Forge specialization of Obras Shared Workspace.
  - Providers do not pass context to each other by loose chat; they exchange governed artifacts through the workspace.
  - Context is compiled once into canonical artifacts, then sliced per provider role to control token cost and quality.
  - Obras Shared Workspace complements Kernel, Forge, Self-Construction OS and Provider Drivers; it replaces ad hoc cross-provider copy/paste, not those systems.
maintenance:
  - Read before changing Atlas Forge, multi-provider programming flows, work packets, artifact bus, context compiler, provider routing or Obras runtime.
  - Update when a new provider collaboration pattern or shared programming workspace is promoted.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-flow-visual-map.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
owner: atlas-ai
layer: 2.2-obras-shared-workspace
line_limit: 220
---

# Atlas Obras - Shared Workspace And Forge

## Canonical Name

Canonical component name:

```text
Obras Shared Workspace
```

Portuguese product label:

```text
Workspace Compartilhado de Obras
```

Programming specialization:

```text
Forge Workspace = Obras Shared Workspace for Programming / Atlas Forge.
```

Do not create competing names such as "provider office", "AI room",
"Forge memory" or "multi-agent project space". Those may be metaphors, not
architecture.

## Role In The Atlas Flow

Obras Shared Workspace is the persistent production workspace where long or
multi-agent work keeps common state:

- mother contract;
- spec and plan;
- work packets;
- provider-specific context packs;
- artifact bus;
- scope map and collision matrix;
- status board;
- decisions;
- diffs and outputs;
- quality gates;
- evidence and integration queue.

It is not a domain, runtime, provider, memory system or ledger. It is the
workspace that binds those systems for a concrete Obra.

## Relationship To Kernel, Forge And Providers

```text
Atlas Kernel decides and authorizes.
Programming Domain defines engineering rules.
Atlas Forge executes heavy programming workflows.
Obras Shared Workspace holds shared construction state.
Provider Drivers perform bounded roles.
Evidence Ledger audits what happened.
```

Therefore:

```text
Obras Shared Workspace governs collaboration.
Forge runs programming work.
Providers execute assigned roles.
Kernel remains the authority.
```

## Why The Current Provider Chain Is Not Enough

The old pattern is:

```text
Gemini reads context -> Claude plans -> Codex implements -> another model reviews
```

This is useful, but it is a chain of handoffs. It repeats context, loses nuance
and makes each provider reinterpret the task from its own chat state.

The target pattern is:

```text
Obras Shared Workspace
-> Context Compiler
-> Provider Router
-> Artifact Bus
-> Provider-specific packets
-> Evidence
-> Integration Queue
```

Providers do not need to talk to each other directly. They write artifacts into
the shared workspace, and Atlas validates those artifacts.

## Token And Context Law

Never send the same large context to every provider by default.

The workspace must separate:

- canonical raw context;
- curated project context;
- mother contract;
- task-specific packet;
- provider-specific context pack;
- delta since last run.

Examples:

| Provider role | Context it should receive |
|---|---|
| Gemini scout | broad repo/source map, alternatives, long-context synthesis |
| Claude planner/reviewer | architecture, risks, specs, tradeoffs, acceptance criteria |
| Codex implementer | allowed files, task, tests, gates, local commands, evidence contract |
| Local agent | deterministic command, expected output, parser rule |

## Artifact Bus

Models must exchange artifacts, not vague conversation:

- research brief;
- codebase map;
- mother contract;
- spec;
- plan;
- task packet;
- implementation diff;
- test output;
- review findings;
- repair request;
- evidence report;
- integration note.

Each artifact should have an id, source, timestamp, owning provider/session,
input hash, output hash and status.

## Minimum Workspace Contract For Heavy Programming

Before using multiple providers for heavy programming, the Obra must have:

1. objective and definition of done;
2. canonical mother contract;
3. work split with disjoint scopes;
4. allowed and forbidden files per packet;
5. dependency and collision map;
6. provider role per packet;
7. required gates and commands;
8. integration queue;
9. evidence normalization contract;
10. rollback/repair policy.

Without this, multi-provider programming is only ad hoc chaining.

## Implementation Direction

Initial runtime may be simple:

```text
Postgres state + Markdown projection + command surfaces
```

But the source of truth must be structured state. Markdown is an export, not
the whole workspace.

The first strategic pilot should be:

```text
Obra: Atlas Self-Construction OS
Workspace type: Forge Workspace
Goal: coordinate multiple AI sessions/providers building Atlas safely.
```

Operational projection: `--forge-workspace-status` shows the office; `--agent-launch-plan` plans sessions; `--agent-start-packet` claims work; status/report/readiness/final-review/decision/receipt/signature/post-signature/merge-action/preflight/draft/receipt/merge-signature/merge-post-signature/execution-checklist/authorization-template/authorization-receipt/authorization-signature/authorization-post-signature/final-authorization-preflight/authorizing-action-template/final-receipt-draft/final-signature-request/final-post-signature-runbook/signed-final-receipt-template/signed-final-receipt-preflight/signed-final-receipt-persistence-template/executor-release-preflight/executor-contract-template/execution-receipt-template/post-execution-preflight/post-execution-action-template/post-execution-action-receipt-draft/post-execution-action-signature-request/post-execution-action-post-signature-runbook/post-execution-action-signed-receipt-template/post-execution-action-signed-receipt-preflight/post-execution-action-signed-receipt-persistence-template/post-execution-action-signed-receipt-persistence-receipt-draft/post-execution-action-signed-receipt-persistence-preflight/post-execution-action-signed-receipt-persistence-post-preflight-runbook/post-execution-action-signed-receipt-persistence-append-only-event-payload-template/post-execution-action-signed-receipt-persistence-writer-preflight/post-execution-action-signed-receipt-persistence-writer-contract-template/post-execution-action-signed-receipt-persistence-writer-implementation-preflight/post-execution-action-signed-receipt-persistence-writer-release-authorization-template/post-execution-action-signed-receipt-persistence-writer-release-authorization-preflight/post-execution-action-signed-receipt-persistence-writer-release-authorization-receipt-draft/post-execution-action-signed-receipt-persistence-writer-release-authorization-signature-request/post-execution-action-signed-receipt-persistence-writer-release-authorization-post-signature-runbook/post-execution-action-signed-receipt-persistence-writer-release-authorization-signed-receipt-template/post-execution-action-signed-receipt-persistence-writer-release-preflight/post-execution-action-signed-receipt-persistence-writer-release-receipt-draft/post-execution-action-signed-receipt-persistence-writer-release-signature-request/post-execution-action-signed-receipt-persistence-writer-release-post-signature-runbook/post-execution-action-signed-receipt-persistence-writer-release-signed-receipt-template/post-execution-action-signed-receipt-persistence-writer-release-execution-contract-preflight/post-execution-action-signed-receipt-persistence-writer-release-execution-contract-template/post-execution-action-signed-receipt-persistence-writer-release-disable-contract-template/post-execution-action-signed-receipt-persistence-writer-release-observability-contract-template/post-execution-action-signed-receipt-persistence-writer-release-post-monitoring-review-template/post-execution-action-signed-receipt-persistence-writer-release-reenable-review-packet-template/post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-request-template/post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-receipt-draft-template/post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-signature-request-template/post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-post-signature-runbook-template/post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-signed-receipt-template/post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-execution-contract-preflight-template/post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-execution-contract-template/post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-disable-contract-template/post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-observability-contract-template govern it.
The fresh-authorization post-monitoring review projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-post-monitoring-review-template`; it belongs to the Forge Workspace health-review chain and remains provider-neutral, read-only and non-authorizing.
The follow-up health decision projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-health-decision-template`; it enumerates allowed health states for the shared office but still cannot record a decision, approve, merge, dispatch or create writer files.
The disable-request projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-disable-request-template`; it prepares a provider-neutral shutdown request for identity drift, provider substitution, cross-workspace/Obra attempts or forbidden writer activity, while still refusing to execute disable, mutate writer state or dispatch work.
The new-cycle projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-request-template`; it keeps the office from reusing old authority by requiring a full restart of request, receipt, signature, execution, disable, observability and review artifacts.
The new-cycle authorization request projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-authorization-request-template`; it is the first restarted authorization checkpoint for the Forge Workspace and requires fresh Workspace, Obra and provider identity evidence before the next receipt draft, without granting approval, accepting signatures, creating writer files, writing ledger events, merging or dispatching work.
The new-cycle receipt draft projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-receipt-draft-template`; it turns the authorization request into an unsigned, non-persisted receipt draft that names the fresh Workspace, Obra and provider identity statements required before any future signature request.
The new-cycle signature request projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-signature-request-template`; it gives the shared office the future signer manifest and fresh identity payload fields while forbidding signature acceptance, signature validation, receipt persistence, approval, writer creation, ledger writes, merge and dispatch.
The new-cycle post-signature runbook projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-post-signature-runbook-template`; it gives the shared office the future sequence for signature bundle collection, fresh Workspace/Obra/provider identity checks and next receipt/contract templates while still forbidding signature acceptance, signature validation, receipt persistence, approval, writer creation, ledger writes, merge and dispatch.
The new-cycle signed receipt template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-signed-receipt-template`; it gives the shared office the future signed receipt shape for a fresh Forge Workspace/Obra/provider identity cycle while still forbidding signature acceptance, signature validation, receipt persistence, approval, writer creation, ledger writes, merge and dispatch.
The new-cycle execution contract preflight projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-execution-contract-preflight-template`; it gives the shared office the future blocker/input map before any execution contract and requires fresh Workspace/Obra/provider identity rechecks while still forbidding signature acceptance, signature validation, receipt persistence, approval, writer creation, ledger writes, merge and dispatch.
The new-cycle execution contract template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-execution-contract-template`; it defines the future provider-neutral writer re-enable contract for the Forge Workspace, including actor evidence, provider identity evidence, hot-scope checks, rollback, disable and monitoring evidence, while still granting no execution, approval, writer creation, ledger write, merge or dispatch authority.
The new-cycle disable contract template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-contract-template`; it gives the shared office the rollback and revocation map for provider drift, hot-scope drift, writer capability failures or old-authority reuse, while still forbidding runtime stop, capability mutation, writer creation, ledger writes, receipt persistence, approval, merge and dispatch.
The new-cycle observability contract template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-observability-contract-template`; it gives the shared office the monitoring map for provider drift, old-authority reuse, hot-scope drift, writer capability failures, forbidden merge/dispatch attempts and post-reenable evidence, while still forbidding writer creation, ledger writes, receipt persistence, approval, merge and dispatch.
The new-cycle post-monitoring review template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-post-monitoring-review-template`; it gives the shared office the health-review map after the monitoring window, including allowed outcomes, provider-drift handling, old-authority reuse handling, required evidence and future health decision outputs, while still forbidding decision recording, writer creation, ledger writes, receipt persistence, approval, merge and dispatch.
The new-cycle health decision template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-health-decision-template`; it gives the shared office the non-recording decision envelope after post-monitoring outcomes, including provider-drift policy, old-authority reuse policy, required decision evidence and future disable/later-cycle outputs, while still forbidding decision recording, writer creation, ledger writes, receipt persistence, approval, merge and dispatch.
The new-cycle disable request template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-request-template`; it gives the shared office the non-executing disable request envelope after a health decision, including provider-drift triggers, old-authority reuse triggers, required disable evidence and future disable execution outputs, while still forbidding disable execution, writer-state mutation, decision recording, writer creation, ledger writes, receipt persistence, approval, merge and dispatch.
The new-cycle disable execution preflight template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-preflight-template`; it gives the shared office the preflight checks before any future disable execution, including provider-drift review, old-authority reuse review, disable path verification, writer-state snapshot evidence and future receipt/evidence outputs, while still forbidding disable execution, writer-state mutation, decision recording, writer creation, ledger writes, receipt persistence, approval, merge and dispatch.
The new-cycle disable execution receipt draft template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-receipt-draft-template`; it gives the shared office the unsigned, non-persisted receipt draft after the preflight, including actor identity, actor provider, provider-drift review, old-authority reuse review, writer-state before/after hashes and human reviewer evidence, while still forbidding disable execution, writer-state mutation, decision recording, writer creation, ledger writes, receipt persistence, approval, merge and dispatch.
The new-cycle disable execution signed receipt template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-signed-receipt-template`; it gives the shared office the non-validating signed receipt shape after the draft, including signer roles, signer identity/provider evidence, exact draft-hash matching, provider-drift review and future persistence preflight outputs, while still forbidding signature acceptance, signature validation, disable execution, writer-state mutation, decision recording, writer creation, ledger writes, receipt persistence, approval, merge and dispatch.
The new-cycle disable execution persistence preflight template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-persistence-preflight-template`; it gives the shared office the non-writing persistence preflight after the signed receipt, including signer identity/provider evidence, provider-drift review, idempotency key, append-only ledger target and writer-state snapshot requirements, while still forbidding ledger writes, receipt persistence, disable execution, writer-state mutation, decision recording, writer creation, approval, merge and dispatch.
The new-cycle disable execution persistence receipt template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-persistence-receipt-template`; it gives the shared office the future persistence receipt shape after the preflight, including the preflight hash, signed receipt hash, provider identity snapshot, provider drift review, idempotency key, append-only ledger target/event hashes, writer-state snapshot and actor provider/role requirements, while still forbidding ledger writes, receipt persistence, disable execution, writer-state mutation, decision recording, writer creation, approval, merge and dispatch.
The new-cycle disable execution post-persistence review template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-post-persistence-review-template`; it gives the shared office the future post-persistence review shape after the receipt, including allowed review decisions, provider identity/drift evidence, idempotency matching, writer-disabled checks and follow-up outputs, while still forbidding ledger writes, receipt persistence, decision recording, disable execution, writer-state mutation, writer creation, approval, merge and dispatch.
The new-cycle disable execution follow-up observability template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-follow-up-observability-template`; it gives the shared office the future observation shape after the post-persistence review, including writer-disabled signals, provider identity snapshot matching, provider drift absence, no-dispatch/no-merge/no-writer/no-ledger evidence and later repair/authorization outputs, while still forbidding ledger writes, receipt persistence, decision recording, disable execution, writer-state mutation, writer creation, approval, merge and dispatch.
The new-cycle disable execution evidence repair request template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-evidence-repair-request-template`; it gives the shared office the future repair request shape after follow-up observability, including failed observation signals, missing evidence keys, replacement hashes, provider identity snapshot/drift repair evidence and future repaired-evidence outputs, while still forbidding ledger writes, receipt persistence, decision recording, disable execution, writer-state mutation, writer creation, approval, merge and dispatch.
The new-cycle disable execution repaired evidence packet template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repaired-evidence-packet-template`; it gives the shared office the future repaired evidence packet after the repair request, including failed signal identity, missing evidence key, replacement evidence/source hashes, replacement provider identity snapshot/drift review hashes, repair actor provider and integrity outputs, while still forbidding ledger writes, receipt persistence, decision recording, disable execution, writer-state mutation, writer creation, approval, merge and dispatch.
The new-cycle disable execution repair review template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repair-review-template`; it gives the shared office the future review contract for repaired evidence packets, including repaired packet hash, repair integrity hash, provider identity repair integrity hash, replacement source checks, reviewer provider identity and accept/reject/escalate/observe outcomes, while still forbidding ledger writes, receipt persistence, decision recording, disable execution, writer-state mutation, writer creation, approval, merge and dispatch.
The new-cycle disable execution repair outcome packet template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repair-outcome-packet-template`; it gives the shared office the future non-authorizing outcome packet after repair review, including selected outcome, rationale, provider identity repair review hash, outcome actor provider and later-cycle readiness signal, while still forbidding later-cycle authorization, ledger writes, receipt persistence, decision recording, disable execution, writer-state mutation, writer creation, approval, merge and dispatch.
The new-cycle disable execution later-cycle request template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-request-template`; it gives the shared office the future request envelope after repair outcome, including provider identity repair outcome hash, Workspace/Obra/scope hashes, request actor provider and prior-authorization reuse forbidden, while still forbidding later-cycle authorization, ledger writes, receipt persistence, decision recording, disable execution, writer-state mutation, writer creation, approval, merge and dispatch.
The new-cycle disable execution later-cycle preflight template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-preflight-template`; it gives the shared office the future non-authorizing preflight after the later-cycle request, including request hash, repair outcome integrity hash, provider identity repair outcome hash, Workspace/Obra/scope/risk hashes, request actor provider, human reviewer identity and prior-authorization reuse forbidden, while still forbidding later-cycle authorization, authorization reuse, ledger writes, receipt persistence, decision recording, disable execution, writer-state mutation, writer creation, approval, merge and dispatch.
The new-cycle disable execution later-cycle authorization request template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-request-template`; it gives the shared office the future non-approving authorization request after the later-cycle preflight, including preflight integrity, provider identity preflight, Workspace/Obra preflight, scope/risk hashes, request actor provider, human approver identity and explicit bounds, while still forbidding approval grant, later-cycle authorization, authorization reuse, ledger writes, receipt persistence, decision recording, disable execution, writer-state mutation, writer creation, merge and dispatch.
The new-cycle disable execution later-cycle authorization receipt draft template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-receipt-draft-template`; it gives the shared office the future unsigned, non-persisted authorization receipt draft after the authorization request, including authorization request integrity, provider identity authorization request, Workspace/Obra authorization request, human approver identity, request actor provider, explicit bounds and non-signature/non-persistence/non-authorization statements, while still forbidding receipt signature, approval grant, later-cycle authorization, authorization reuse, ledger writes, receipt persistence, decision recording, disable execution, writer-state mutation, writer creation, merge and dispatch.
The new-cycle disable execution later-cycle authorization signature request template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signature-request-template`; it gives the shared office the future non-accepting signature request after the receipt draft, including receipt draft integrity, provider identity receipt draft, Workspace/Obra receipt draft, required signer identity/provider scope, signature scope and signature request actor provider, while still forbidding signature acceptance, signature validation, receipt signature, approval grant, later-cycle authorization, authorization reuse, ledger writes, receipt persistence, decision recording, disable execution, writer-state mutation, writer creation, merge and dispatch.
The new-cycle disable execution later-cycle authorization post-signature runbook template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-post-signature-runbook-template`; it gives the shared office the future non-validating runbook after a detached signature artifact appears, including provider identity receipt draft binding, Workspace/Obra receipt draft binding, signer provider scope, signature scope, receipt draft hash and explicit mismatch blockers, while still forbidding signature acceptance, signature validation, receipt signature, approval grant, later-cycle authorization, authorization reuse, ledger writes, receipt persistence, decision recording, disable execution, writer-state mutation, writer creation, merge and dispatch.
The new-cycle disable execution later-cycle authorization signature validation report template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signature-validation-report-template`; it gives the shared office the future descriptive validation report after the post-signature runbook, including provider identity receipt draft checks, Workspace/Obra receipt draft checks, signer provider scope, detached signature artifact, non-acceptance statement and validation authority deferral, while still forbidding signature acceptance, signature authority, receipt signature, approval grant, later-cycle authorization, authorization reuse, ledger writes, receipt persistence, decision recording, disable execution, writer-state mutation, writer creation, merge and dispatch.
The new-cycle disable execution later-cycle authorization signed receipt template projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signed-receipt-template`; it gives the shared office the future provider-neutral signed receipt shape after the descriptive validation report, including provider identity receipt draft binding, Workspace/Obra receipt draft binding, validated signer provider scope, validation report hash, post-signature runbook hash, signature authority deferral, non-persistence statement and non-authorization statement, while still forbidding signature acceptance, signature authority, actual receipt signature, approval grant, later-cycle authorization, authorization reuse, ledger writes, receipt persistence, decision recording, disable execution, writer-state mutation, writer creation, merge and dispatch.
The new-cycle disable execution later-cycle authorization signed receipt preflight projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signed-receipt-preflight-template`; it gives the shared office the future provider-neutral preflight before any receipt persistence, including signed receipt template hash, validation report hash, provider identity receipt draft hash, Workspace/Obra receipt draft hash, validated signer provider scope, signature authority deferral and non-persistence/non-authorization checks, while still forbidding signature acceptance, signature authority, actual receipt signature, approval grant, later-cycle authorization, authorization reuse, ledger writes, receipt persistence, decision recording, disable execution, writer-state mutation, writer creation, merge and dispatch.

The new-cycle disable execution later-cycle authorization persistence preflight projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-preflight-template`; it gives the shared office the future provider-neutral non-writing persistence preflight after the signed receipt preflight, including signed receipt preflight/template hashes, signature validation report hash, post-signature runbook hash, signature request hash, receipt draft hash, provider identity receipt draft hash, Workspace/Obra receipt draft hash, validated signer provider scope, signature authority deferral, non-persistence/non-authorization statements, future persistence target, future ledger target and idempotency key, while still forbidding signature acceptance, signature authority, receipt signature, receipt persistence, approval grant, later-cycle authorization, authorization reuse, ledger writes, decision recording, disable execution, writer-state mutation, writer creation, merge and dispatch.

The new-cycle disable execution later-cycle authorization persistence receipt projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-receipt-template`; it gives the shared office the future provider-neutral non-persisting receipt shape after the persistence preflight, including persistence preflight hash, signed receipt preflight/template hashes, signature validation report hash, post-signature runbook hash, signature request hash, receipt draft hash, provider identity receipt draft hash, Workspace/Obra receipt draft hash, validated signer provider scope, future persistence target, future ledger target, idempotency key and future operation hashes, while still forbidding signature acceptance, signature authority, receipt signature, receipt persistence, approval grant, later-cycle authorization, authorization reuse, ledger writes, decision recording, disable execution, writer-state mutation, writer creation, merge and dispatch.

The new-cycle disable execution later-cycle authorization post-persistence review projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-post-persistence-review-template`; it gives the shared office the future provider-neutral non-writing review shape after the persistence receipt template, including allowed review decisions, persistence receipt/preflight hashes, signed receipt preflight/template hashes, signature validation report hash, provider identity receipt draft hash, Workspace/Obra receipt draft hash, validated signer provider scope, future operation hashes, observation window evidence and human reviewer identity, while still forbidding signature acceptance, signature authority, receipt signature, receipt persistence, approval grant, later-cycle authorization, authorization reuse, ledger writes, decision recording, disable execution, writer-state mutation, writer creation, merge and dispatch.

The new-cycle disable execution later-cycle authorization follow-up observability projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-follow-up-observability-template`; it gives the shared office the future provider-neutral observation shape after post-persistence review, including later-cycle authorization disabled signals, prior authorization reuse disabled signals, signature authority disabled signals, provider identity receipt draft hash, Workspace/Obra receipt draft hash, validated signer provider scope, no-ledger/no-decision/no-dispatch evidence and human reviewer identity, while still forbidding signature acceptance, signature authority, receipt signature, receipt persistence, approval grant, later-cycle authorization, authorization reuse, ledger writes, decision recording, disable execution, writer-state mutation, writer creation, merge and dispatch.

The new-cycle disable execution later-cycle authorization evidence repair request projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-evidence-repair-request-template`; it gives the shared office the future provider-neutral repair request shape after follow-up observability, including missing no-authorization/no-prior-reuse/no-signature-authority/no-ledger/no-decision/no-dispatch evidence, provider identity or Workspace/Obra binding repair, replacement provider identity receipt draft hash, replacement Workspace/Obra receipt draft hash, replacement validated signer provider scope, repair actor provider and post-repair integrity check evidence, while still forbidding signature acceptance, signature authority, receipt signature, receipt persistence, approval grant, later-cycle authorization, authorization reuse, ledger writes, decision recording, disable execution, writer-state mutation, writer creation, merge and dispatch.

The new-cycle disable execution later-cycle authorization repaired evidence packet projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-repaired-evidence-packet-template`; it gives the shared office the future provider-neutral repaired evidence packet shape after a repair request, including failed observation signal, missing evidence key, original expected evidence hash, replacement evidence/source hashes, replacement provider identity receipt draft hash, replacement Workspace/Obra receipt draft hash, replacement validated signer provider scope, repair actor provider, non-authorization statement, non-signature-authority statement and integrity evidence, while still forbidding signature acceptance, signature authority, receipt signature, receipt persistence, approval grant, later-cycle authorization, authorization reuse, ledger writes, decision recording, disable execution, writer-state mutation, writer creation, merge and dispatch.

The new-cycle disable execution later-cycle authorization repair review projection is `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-repair-review-template`; it gives the shared office the future provider-neutral review shape after a repaired evidence packet, including repaired packet hash, repair request hash, follow-up observability hash, replacement provider identity receipt draft hash, replacement Workspace/Obra receipt draft hash, replacement validated signer provider scope, non-signature-authority statement, reviewer identity and accept/reject/escalate/observe outcomes, while still forbidding signature acceptance, signature authority, receipt signature, receipt persistence, approval grant, later-cycle authorization, authorization reuse, ledger writes, decision recording, disable execution, writer-state mutation, writer creation, merge and dispatch.

## Non-Negotiable Rule

Obras Shared Workspace is the canonical name for the shared office in the Atlas
flow. Any future diagram, doc or runtime that describes multi-provider
collaboration for production work must use this term or explicitly state that it
is a specialization of it.
