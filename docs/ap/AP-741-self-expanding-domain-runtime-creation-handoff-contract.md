---
id: AP-741-self-expanding-domain-runtime-creation-handoff-contract
type: architecture_proposal
title: AP-741 Self-Expanding Domain Runtime Creation Handoff Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Turns AP-738 Self-Expanding Software Company v0 accepted proposals into governed Domain Runtime Creation Gate handoff packets after AP-731 accept, AP-737 blockers clear and AP-740 Evidence Ledger outcomes are recorded. It reuses NewAreaProposalGateService, StewardshipOutcomeEvidenceBridgeService and DomainManifestRegistryService; AP-742 shows projected AP-741 packets inside Product Mode/Cockpit. It creates no domain, manifest, department, branch, provider run or executor.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/engineering-knowledge-base/atlas-domain-runtime-creation-gate.md
  - docs/ap/AP-737-new-area-proposal-gate-contract.md
  - docs/ap/AP-738-self-expanding-software-company-v0-contract.md
  - docs/ap/AP-740-stewardship-outcome-evidence-and-morning-inbox-contract.md
  - docs/ap/AP-742-product-mode-cockpit-stewardship-history-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/SelfExpandingDomainRuntimeCreationHandoffService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/SelfExpanding/SelfExpandingDomainRuntimeCreationHandoffServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-741 Self-Expanding Domain Runtime Creation Handoff Contract

## Decision

AP-741 is the first concrete bridge from **Self-Expanding Software Company** to
the existing **Atlas Domain Runtime Creation Gate**.

It does not create the new domain. It produces a deterministic handoff packet
only after the proposal has passed the upstream gates:

```text
AP-737 accepted_for_domain_runtime_creation_gate
+ AP-731 operator accept receipt
+ AP-740 recorded evidence
-> AP-741 handoff packet
-> Domain Runtime Creation Gate review
```

## Boundary

AP-741 may:

- read AP-737 gate items;
- read AP-738 Self-Expanding reports;
- read AP-740 outcome/evidence bridge projections;
- require recorded AP-740 Evidence Ledger events before declaring a handoff ready;
- check `DomainManifestRegistryService` for existing-domain collisions;
- append an idempotent local JSONL handoff packet when explicitly requested.

AP-741 must not:

- register a domain manifest;
- create a domain runtime or department;
- invoke providers, Atlas Dev or Forge;
- open branches or worktrees;
- promote L0/L1;
- bypass Domain Runtime Creation Gate, dual signature, sandbox, shadow mode,
  replay or operator approval.

## Schemas

```text
atlas.software_company.self_expanding.domain_runtime_creation_handoff.v1
atlas.domain.creation_handoff_packet.v1
atlas.software_company.domain_handoff_evidence_gate.v1
```

## CLI

Projection only:

```text
php artisan atlas:software-company-stewardship domain-runtime-creation-handoff --json
```

Append an idempotent packet after evidence is recorded:

```text
php artisan atlas:software-company-stewardship domain-runtime-creation-handoff --record-handoff --proposal-id=<proposal> --json
```

Dry-run without recorded ledger evidence:

```text
php artisan atlas:software-company-stewardship domain-runtime-creation-handoff --allow-projected-evidence --json
```

## Acceptance

- `SelfExpandingDomainRuntimeCreationHandoffService` emits
  `atlas.software_company.self_expanding.domain_runtime_creation_handoff.v1`.
- No handoff is ready without AP-737 accepted status and AP-740 recorded
  operator-decision + self-expanding evidence.
- Accepted proposals with existing domain manifests are blocked.
- Ready handoffs carry the original `atlas.domain.creation_proposal.v1` envelope
  plus required sandbox, shadow, dual-signature, replay and receipt gates.
- `--record-handoff` writes append-only JSONL and is idempotent by packet id.
- AP-742 exposes AP-741 handoff packets in the existing Product Mode/Cockpit
  without moving AP-741 write authority into the cockpit.
- Claim policy proves no provider, no Dev/Forge, no branch, no manifest/domain
  creation, no merge/deploy/secrets and no auto-promotion.
