---
id: AP-762-end-to-end-stewardship-live-cycle-certification
type: ap_contract
title: AP-762 End-to-End Stewardship Live Cycle Certification Contract
status: active
owner: programming
summary: Certifies the whole Atlas Software Company Stewardship Stack as one bounded live cycle by reusing AP-722, AP-743, AP-744, AP-745, AP-746, AP-747, AP-748, AP-749, AP-758, AP-759, AP-750, AP-751, AP-733, AP-734, AP-735, AP-752 and AP-739/AP-761. AP-762 is a certifier only: it creates no new OS, runtime, cockpit, provider path or executor, and proves that projection mode and optional AP-759 sandbox execution can reach Product Mode visibility without merge, deploy, external push, secrets or destructive action.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-715-software-company-stewardship-stack-contract.md
  - docs/ap/AP-739-product-mode-cockpit-stewardship-review-contract.md
  - docs/ap/AP-761-product-mode-desktop-end-to-end-stewardship-console-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipLiveCycleCertificationService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
requires_evidence: true
risk_level: critical
---
# AP-762 End-to-End Stewardship Live Cycle Certification Contract

## Decision

AP-762 adds the first server-side certification that proves the Atlas Software
Company Stewardship Stack operates as one connected machine.

It conducts a bounded proof path through existing owners only:

```text
AP-722 Area Focus operational cycle
-> AP-743 Area Stewardship active handoff
-> AP-744 active operating slice
-> AP-745 Continuous Stewardship tick
-> AP-746 recurring scheduler-safe runner
-> AP-747 Dev/Forge release
-> AP-748 outcome bridge
-> AP-749 owner queue consumption gate
-> AP-758 owner runtime execution adapter
-> AP-759 owner sandbox runtime runner
-> AP-750 owner runtime result bridge
-> AP-751/AP-733 Portfolio intake
-> AP-734 Portfolio Steward Inbox
-> AP-735 Autonomous Executive recommendation
-> AP-752 executive allocation handoff
-> AP-739/AP-761 Product Mode visibility
```

AP-762 is not a new OS, new runtime, new cockpit, new scheduler, new Dev/Forge
dispatcher, new provider path or new decision ledger. It is a certifier and a
diagnostic boundary: when the chain is incomplete, it must block on the first
real owner that is not ready.

## Duplicate Resolution

The placement gate can overlap with:

- `ProductModeCockpitSurfaceService`;
- `AtlasSoftwareCompanyStewardshipCommand`;
- `atlas-software-company-stewardship-stack.md`;
- `atlas-stewardship-evolution-ladder.md`;
- AP-739/AP-761 Product Mode visibility;
- AP-747/AP-749/AP-758/AP-759/AP-750 owner runtime flow.

AP-762 resolves that overlap by reusing those owners. It adds only one
certification service and one command action:

```text
php artisan atlas:software-company-stewardship live-cycle-certification --json
php artisan atlas:software-company-stewardship live-cycle-certification --execute-owner-command --json
```

## Certification Modes

`projection_certification` is the default mode. It proves every handoff,
visibility bridge, operator receipt shape and Product Mode aggregate without
executing the owner command. AP-759 may pass as `owner_runtime_command_planned`
in this mode.

`owner_command_execution_certification` is opt-in through
`--execute-owner-command`. It creates or uses a certification-only worktree,
executes an allowlisted owner CLI command under AP-759 receipt, and then requires
AP-750 to accept the resulting owner runtime evidence before Portfolio and
Executive stages are considered certified.

## Required Matrix

The report schema is:

```text
atlas.software_company_stewardship.live_cycle_certification.v1
```

The certification matrix must include:

- AP-722 Area Focus cycle;
- AP-743 active handoff;
- AP-744 active operation;
- AP-745 Continuous Stewardship tick;
- AP-746 recurring scheduler-safe runner;
- AP-747 Dev/Forge release;
- AP-748 outcome bridge;
- AP-749 owner consumption;
- AP-758 owner execution adapter;
- AP-759 owner sandbox runner;
- AP-750 owner result bridge;
- AP-751 Portfolio result intake;
- AP-734 Portfolio Steward Inbox;
- AP-735 Autonomous Executive recommendations;
- AP-752 executive allocation handoff;
- AP-739/AP-761 Product Mode visibility;
- safety check proving no irreversible action.

## Boundary

AP-762 may:

- project one full stewardship cycle using existing owner services;
- create a certification-only sandbox directory;
- execute the AP-759 owner command only when explicit command receipt is present;
- emit a certification matrix, blockers and hashes;
- expose certification through the existing stewardship command;
- diagnose cross-owner contract drift.

AP-762 must not:

- create another software company OS, runtime, cockpit or scheduler;
- call provider APIs directly;
- bypass AP-749 before owner runtime input;
- bypass AP-750 before Portfolio/Executive follow-up;
- merge, deploy, push externally, touch secrets or perform destructive actions;
- turn Product Mode into an executor;
- claim full implementation when any matrix stage is blocked.

## Acceptance

- Default `live-cycle-certification --json` returns `status=certified` with no
  blockers in projection mode.
- `live-cycle-certification --execute-owner-command --json` returns
  `status=certified` with AP-759 command result and AP-750 result bridge passing.
- AP-749 requires AP-748 visibility, AP-756 sandbox binding and operator receipt.
- AP-758/AP-759/AP-750 all reuse existing owner services.
- AP-752 consumes an accepted AP-735 recommendation using AP-731-compatible
  decision receipt shape.
- Product Mode visibility includes the certified chain.
- Safety check blocks if any owner claims new OS/runtime, merge, deploy, secret
  access, destructive change or cockpit-side execution.
