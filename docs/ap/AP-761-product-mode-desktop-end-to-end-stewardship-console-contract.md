---
id: AP-761-product-mode-desktop-end-to-end-stewardship-console
type: ap_contract
title: AP-761 Product Mode Desktop End-to-End Stewardship Console Contract
status: active
owner: programming
summary: Extends the existing Atlas Desktop `stewardship` surface so Product Mode shows the full Software Company Stewardship operating chain in one read-only console: outcome evidence, Domain Runtime Creation handoff, Area Stewardship active operation, Continuous Stewardship Loop, recurring runner, Dev/Forge release, AP-759 owner sandbox run, AP-750 owner result bridge, AP-752 allocation handoff and AP-754 operational controls. AP-761 is a desktop visibility contract only; execution remains owned by AP-747/AP-749/AP-759/AP-750/AP-752/AP-754.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-739-product-mode-cockpit-stewardship-review-contract.md
  - docs/ap/AP-760-product-mode-owner-sandbox-runtime-visibility-contract.md
  - ../atlas-desktop/apps/desktop/src/surfaces/stewardship/StewardshipSurface.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/stewardship/types.ts
  - ../atlas-desktop/apps/desktop/src/surfaces/stewardship/model.ts
  - ../atlas-desktop/apps/desktop/src/surfaces/stewardship/stewardship.css
  - ../atlas-desktop/apps/desktop/src/surfaces/stewardship/__tests__/stewardshipCockpitContract.test.ts
requires_evidence: true
risk_level: high
---
# AP-761 Product Mode Desktop End-to-End Stewardship Console Contract

## Decision

AP-761 promotes Atlas Desktop `stewardship` from an upper-review cockpit into
the end-to-end Product Mode console for the whole Atlas Software Company
Stewardship Stack.

It reuses the existing AP-739 HTTP cockpit schema and the existing desktop
surface. It does not add another Product Mode backend, another desktop surface,
another command runner, another owner runtime, another Dev/Forge dispatcher or
another decision ledger.

## Duplicate Resolution

The placement gate reports overlap with:

- `ProductModeCockpitSurfaceService`;
- `AtlasSoftwareCompanyStewardshipCommand`;
- `atlas-software-company-stewardship-stack.md`;
- `atlas-stewardship-evolution-ladder.md`.

AP-761 resolves that overlap by extending those owners only. The desktop reads
the AP-739 aggregate and renders fields already emitted by AP-760/AP-750/AP-752
and AP-754. It must not invent policy or data locally.

## Desktop Console

The `stewardship` surface must show:

- global cockpit health and no-new-OS boundary;
- Area Focus health and WIP;
- Executive Inbox, New Area Gate and Self-Expanding review;
- outcome evidence and Morning Inbox counters;
- Domain Runtime Creation handoff state;
- Area Stewardship active handoff/operation state;
- Continuous Stewardship Loop tick state;
- recurring scheduler state;
- Dev/Forge release state;
- AP-759 owner sandbox runtime command plan/result;
- AP-750 owner runtime result bridge;
- AP-752 executive allocation handoff;
- AP-754 Product Mode controls;
- unified review queue and AP-owned command anchors.

## Boundary

AP-761 may:

- render AP-739 data in Atlas Desktop;
- add TypeScript types for fields emitted by the AP-739 aggregate;
- summarize AP-759/AP-750/AP-752/AP-754 review items;
- expose operator command anchors as read-only text;
- preserve slate-dark token-only surface canon.

AP-761 must not:

- call provider APIs;
- execute Dev/Forge;
- execute AP-759 commands;
- record AP-731/AP-755 receipts;
- create branches or worktrees;
- create domains, departments or runtime owners;
- merge, deploy, push externally or access secrets;
- bypass AP-750 before Portfolio/Executive follow-up.

## Acceptance

- Desktop `stewardship` renders AP-759, AP-750, AP-752 and AP-754 sections.
- Review queue summary counts owner sandbox runs, owner results, allocation
  handoffs and Product Mode controls.
- Safety model fails closed if the cockpit claims it executed owner sandbox
  runtime, result bridge, release, scheduler, allocation or Product Mode
  controls.
- CSS remains slate-dark and token-only through `--cc-*`.
- Desktop stewardship contract test passes.
- Desktop build/typecheck passes.
