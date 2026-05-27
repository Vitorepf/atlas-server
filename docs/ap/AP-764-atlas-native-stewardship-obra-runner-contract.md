---
id: AP-764-atlas-native-stewardship-obra-runner
type: ap_contract
title: AP-764 Atlas-Native Stewardship Obra Runner Contract
status: active
owner: programming
summary: Adds the Atlas Server-native bridge from Software Company Stewardship cycles into Obra handoffs for Atlas Dev and Forge. AP-764 explicitly replaces any Codex-app automation assumption: recurring invocation may call Atlas Server, but the work itself is represented as native Atlas Obra handoffs, reusing AP-745/AP-746/AP-747/AP-756/AP-749/AP-758/AP-759/AP-750. It creates no new OS, no new scheduler, no provider shortcut and no parallel Dev/Forge runtime.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-745-continuous-stewardship-loop-scheduler-safe-contract.md
  - docs/ap/AP-746-continuous-stewardship-recurring-scheduler-contract.md
  - docs/ap/AP-747-area-focus-dev-forge-release-contract.md
  - docs/ap/AP-756-area-focus-branch-sandbox-materializer-contract.md
  - docs/ap/AP-759-owner-sandbox-runtime-runner-contract.md
  - docs/ap/AP-763-software-company-stewardship-completion-audit-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipNativeObraRunnerService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - config/atlas.php
  - routes/console.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipNativeObraRunnerServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-764 Atlas-Native Stewardship Obra Runner Contract

## Decision

AP-764 is the correction boundary for the Stewardship always-on runner.

The canonical rule is:

```text
Stewardship work belongs to Atlas Server and must flow as native Atlas Obras.
Codex app automations may invoke a command during development, but they are not
the product runtime, not the owner of the loop and not a dependency of Atlas.
```

The report schema is:

```text
atlas.software_company_stewardship.native_obra_runner.v1
```

The native handoff schema is:

```text
atlas.software_company_stewardship.native_obra_handoff.v1
```

## Duplicate Resolution

AP-764 intentionally overlaps with several existing owners. It resolves the
overlap by reuse only:

- AP-745 remains the scheduler-safe tick.
- AP-746 remains the recurring scheduler-safe boundary.
- AP-744 remains the active Area Stewardship operating slice.
- AP-747 remains the release to Atlas Dev/Forge owner queues.
- AP-756 remains the isolated branch/worktree materializer.
- AP-749/AP-758/AP-759/AP-750 remain the owner-consumption, owner-runtime,
  sandbox-command and result-bridge chain.
- Product Mode remains the operator surface.
- AP-763 remains the completion audit.

AP-764 does not supersede any of them. It only converts AP-744 ready Dev/Forge
handoffs into explicit native Obra handoff packets so a human or future native
scheduler can see the exact Atlas-owned path.

## Provider Choreography

AP-764 may declare the intended provider choreography:

| Role | Provider | Model | Purpose |
|---|---|---|---|
| context_scout | Gemini CLI | `gemini-3.5-flash` | Dissect context and prepare compact architecture input |
| architect | Claude CLI | `claude-opus-4-7` | Architecture/spec decision |
| implementer | Claude CLI | `claude-sonnet-4-6` | Implementation inside sandbox |
| reviewer | Gemini CLI | `gemini-3.5-flash` | Drift, docs and edge-case review |
| quality_certifier | Codex CLI | `gpt-5.3-codex-spark` | Final quality, tests and evidence |

Declaring choreography is not execution. Any provider invocation still requires
the AP-759 owner-command receipt, budget authorization, allowlisted owner
command, sandbox binding and evidence bridge.

## CLI

Projection or native run:

```bash
php artisan atlas:software-company-stewardship native-obra-runner --area=agentic_engineering_os --enable-native-obra-runner --json
```

Append-only record:

```bash
php artisan atlas:software-company-stewardship native-obra-runner --area=agentic_engineering_os --enable-native-obra-runner --record-native-obra-run --json
```

Manual verification may use `--force-scheduler-run` and a low
`--min-interval-seconds`, but unattended operation must respect AP-745/AP-746
locks, rate limits, pause and kill switch.

## Boundary

AP-764 may:

- invoke AP-746 inside Atlas Server;
- register a Laravel Scheduler entry disabled by default through
  `ATLAS_STEWARDSHIP_NATIVE_OBRA_RUNNER_ENABLED`;
- read AP-744 active operation output;
- project native Obra handoff packets for Atlas Dev/Forge;
- persist JSONL records in Atlas Server storage when requested;
- expose provider choreography as a gated plan;
- prove that Codex app automation is not used.

AP-764 must not:

- create a Codex app automation;
- depend on Codex app scheduling;
- run unattended when Product Mode/config leaves the native runner disabled;
- create a new OS, scheduler or Dev/Forge runtime;
- invoke providers directly;
- create branches or worktrees;
- bypass AP-747/AP-756/AP-749/AP-758/AP-759/AP-750;
- merge, deploy, push externally, access secrets or make destructive changes.

## Acceptance

- The service returns `blocked` when no AP-744 active operation is available.
- The service returns native Obra handoffs when AP-744 contains ready AP-726
  Dev/Forge handoffs.
- Each handoff declares `uses_codex_app_automation=false`.
- Each handoff declares the AP-747 -> AP-756 -> AP-749 -> AP-758 -> AP-759 ->
  AP-750 owner chain.
- Provider choreography is present but `provider_call_allowed_now=false`.
- `native-obra-runner --record-native-obra-run --json` writes append-only JSONL.
- Focused tests prove the native boundary, no-external-automation claim and
  provider-execution gate.
