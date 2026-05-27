---
id: AP-763-software-company-stewardship-completion-audit
type: ap_contract
title: AP-763 Software Company Stewardship Completion Audit Contract
status: active
owner: programming
summary: Adds the requirement-by-requirement completion audit for the Atlas Software Company Stewardship Stack. AP-763 reuses AP-762 live-cycle certification and the Self-Construction completion-audit pattern to answer the operator's practical numbered list with proof per item. It is a certifier only: it creates no runtime, no scheduler, no provider path, no merge/deploy path and no new OS.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-715-software-company-stewardship-stack-contract.md
  - docs/ap/AP-762-end-to-end-stewardship-live-cycle-certification-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipCompletionAuditService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipLiveCycleCertificationService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
requires_evidence: true
risk_level: critical
---
# AP-763 Software Company Stewardship Completion Audit Contract

## Decision

AP-763 is the canonical completion audit for the Atlas Software Company
Stewardship Stack.

It exists because "the stack is done" is not an acceptable claim unless Atlas can
answer, item by item, the operator's practical ordered requirement list:

1. Area Focus read-only for `agentic_engineering_os`.
2. Finding engine.
3. Morning Inbox.
4. Automatic spec drafts.
5. Safety gates by area.
6. Evidence pack per cycle.
7. Dev/Forge routing.
8. Branch sandbox handoff/preflight.
9. Isolated branch/worktree materializer with operator receipt.
10. Continuous Stewardship scheduler-safe tick.
11. 24h recurring runner with locks, budgets, rate limit, pause and kill switch.
12. Product Mode cockpit with operational controls.
13. Persistent Product Mode control receipts.
14. Area Stewardship readiness.
15. Area Stewardship active handoff.
16. Area Stewardship active operating slice.
17. Release handoffs to real Atlas Dev/Forge queues.
18. Owner-specific consumption gate.
19. Real Dev/Forge execution inside the isolated sandbox.
20. Owner runtime result bridge to Evidence/Morning Inbox/Portfolio.
21. Portfolio health/risk/rebalance intake.
22. Portfolio Steward Inbox.
23. Autonomous Executive recommendation pack.
24. Executive Decision Inbox.
25. Executive allocation handoff to correct owners.
26. Product Mode visibility for those handoffs.
27. New Area Proposal Gate.
28. Domain Runtime Creation handoff.
29. Self-Expanding Software Company v0 proposal-only.

The report schema is:

```text
atlas.software_company_stewardship.completion_audit.v1
```

## Duplicate Resolution

The placement gate overlaps with AP-762, Product Mode, Self-Expanding owners and
Self-Construction completion audit services.

AP-763 resolves that overlap by being an audit boundary only:

- it reuses AP-762 for live-cycle proof;
- it reuses the Self-Construction pattern of explicit failed criteria and
  blocked completion claims;
- it never supersedes AP-762;
- it never creates a second runtime, OS, scheduler, cockpit or execution path;
- it never marks an owner complete unless the owner AP, service, tests and
  runtime proof are present.

## Completion Semantics

Default projection mode:

```text
php artisan atlas:software-company-stewardship completion-audit --json
```

This may prove most of the list, but it must not claim item 19 as complete
because real owner execution requires AP-762 owner command certification.

Full execution-certification mode:

```text
php artisan atlas:software-company-stewardship completion-audit --include-execution-certification --json
```

This may claim `current_practical_number=29` only when:

- AP-762 projection certification is `certified`;
- AP-762 owner-command execution certification is `certified`;
- every numbered requirement has AP docs, owner service class, test file and
  required AP-762 stage proof;
- item 19 has AP-759 command execution result `completed` inside the sandbox;
- no owner or audit claim policy reports merge, deploy, secret access,
  destructive action, parallel runtime creation or operator bypass.

## Report Contract

The AP-763 report must include:

- `status`: `complete`, `incomplete` or `blocked`;
- `current_practical_number`;
- `target_practical_number`;
- `proven_count`, `weak_count`, `missing_count`, `contradicted_count`;
- one `requirements[]` row for each of the 29 operator requirements;
- checks for AP contract file, service class, test file and runtime stage proof;
- blockers grouped by requirement;
- AP-762 projection summary;
- optional AP-762 owner-command execution summary;
- duplicate-overlap resolution policy;
- non-blocking maintainability warnings for oversized canonical docs.

## Boundary

AP-763 may:

- audit existing docs, services, tests and AP-762 runtime reports;
- run AP-762 projection certification;
- run AP-762 owner-command certification only when explicitly requested;
- expose the answer to "which number are we at?";
- block external completion claims.

AP-763 must not:

- create a new OS or runtime;
- schedule recurring work;
- call providers directly;
- bypass Product Mode controls;
- bypass AP-749 owner consumption;
- bypass AP-750 owner result bridge;
- approve or execute merge, deploy, external push, secret access or destructive
  changes;
- treat projection-only AP-759 planning as real owner execution.

## Acceptance

- `completion-audit --json` returns `current_practical_number=18` with item 19
  weak because owner execution certification was not requested.
- `completion-audit --include-execution-certification --json` returns
  `status=complete`, `current_practical_number=29`, `proven_count=29`,
  `weak_count=0`, `missing_count=0` and `completion_claim_allowed=true`.
- Test coverage proves the projection-only weak boundary, full 29/29 execution
  proof, AP-762 blocking behavior and non-runtime claim policy.
