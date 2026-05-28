---
id: AP-795-agent-execution-provider-port-session-store
type: ap_contract
title: AP-795 Agent Execution Provider Port + Session Store Contract
status: active
implementation_state: implemented
owner: software_company_stewardship
summary: First real runtime of two AP-793 ports - the agent execution provider port (atlas.agent_execution.provider_port.v1) and the durable session store (atlas.agent_execution.session_store.v1). AP-795 normalizes provider facts (provider_id, model_family, command_argv, working_directory, session_id, resume_token, stream_events, usage, auth_mode, permission_mode, exit_status, timeout_state, rate_limit_state) coming from AP-759/AP-786 without executing any provider, and persists a sanitized, idempotent, append-only session record that never stores raw secrets. It is an adapter over existing primitives, not a new runtime, provider router, Sandcastle clone, slice planner, lane orchestrator, judge, repair loop or Product Mode read model.
related_paths:
  - docs/ap/AP-793-atlas-isolated-agent-execution-substrate-contract.md
  - docs/ap/AP-786-autonomous-evolution-session-contract.md
  - docs/ap/AP-790-reliable-24h-autonomous-loop-runner-contract.md
  - docs/ap/AP-792-24h-loop-certification-harness-contract.md
  - docs/ap/AP-759-owner-sandbox-runtime-runner-contract.md
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/AgentExecutionProviderPortService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/AgentExecutionSessionStoreService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Loop24hCertificationHarnessService.php
  - app/Support/AtlasSecurity.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AgentExecution/AgentExecutionProviderPortServiceTest.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AgentExecution/AgentExecutionSessionStoreServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-795 Agent Execution Provider Port + Session Store Contract

## Authority

AP-795 is the **first real runtime** of two of the six AP-793 ports:

```text
atlas.agent_execution.provider_port.v1   (Required Port #1)
atlas.agent_execution.session_store.v1   (Required Port #5)
```

It is not a new OS, scheduler, provider router, Dev/Forge runtime, Sandcastle
clone or parallel evidence ledger. It does not touch the AP-794 finding slice
planner, the multi-agent lane orchestrator, the judge, the repair loop or the
Product Mode read models — those are owned by other contracts and other agents.

AP-795 is deliberately scoped so the operator can reach the "multi-agent per
task" milestone (context_scout / architect / implementer / reviewer /
repair_agent / judge lanes from AP-793 §Multi-Agent Execution) on top of a
substrate that is honest about what really ran.

## Scope Honesty (mandatory)

AP-793 is a substrate contract with six ports. AP-795 implements two of them and
must never be reported as "AP-793 complete":

| AP-793 port | AP-795 state |
|---|---|
| `agent_execution.provider_port.v1` | **Implemented** — normalized facts, no provider execution. |
| `agent_execution.session_store.v1` | **Implemented** — durable, idempotent, secret-safe JSONL. |
| `agent_execution.sandbox_provider.v1` | Out of scope — still AP-756 worktree + AP-793 target. |
| `agent_execution.worktree_lifecycle.v1` | Out of scope — AP-756 owns it. |
| `agent_execution.branch_strategy.v1` | Out of scope — AP-769/AP-770/AP-774 own it. |
| `agent_execution.result_parser.v1` | Out of scope — AP-786 owner flow / AP-791 receipts own it. |

Any production claim must say "AP-795 implements the AP-793 provider port and
session store" — never "AP-793 is done".

## Anti-Duplication

AP-795 is an adapter over existing primitives:

```text
reuse_or_extend:
  AP-759 owner sandbox runtime command facts (provider/model/exit/working dir)
  AP-786 autonomous evolution session cycle receipt (provider_result, owner_flow)
  AP-790 reliable 24h loop runner ledger semantics (append-only, idempotent)
  AP-792 certification harness (capability detection)
  App\Support\AtlasSecurity (secret redaction)
  App\Services\Ai\Mission\MissionCanonicalHash (deterministic hashing)
do_not_create:
  parallel provider router or driver
  parallel evidence ledger
  parallel branch/worktree manager
  direct provider loop with an Atlas-shaped prompt
  Sandcastle clone
```

## Port 1 — Agent Execution Provider Port

`AgentExecutionProviderPortService` is a **pure normalizer**. It accepts a
payload that comes from AP-759 (owner sandbox runtime command result) or AP-786
(autonomous evolution session cycle) — or a flat provider-facts dictionary — and
returns one normalized record. It never spawns a process, never calls a provider
router and never mutates any file.

Schema: `atlas.agent_execution.provider_port.v1`

Required normalized fields:

| Field | Meaning |
|---|---|
| `provider_id` | `cursor_cli`, `claude_cli`, `codex_cli`, `gemini_cli`, etc. |
| `model_family` | Model chosen by Atlas Decide / owner runtime. |
| `command_argv` | **Argv array only.** A shell string is rejected. |
| `working_directory` | AP-756 worktree or approved sandbox path. |
| `session_id` | Provider session id when available. |
| `resume_token` | Provider continuation handle when supported. |
| `stream_events` | Normalized `text` / `tool` / `error` / `usage` events (redacted excerpts). |
| `usage` | Tokens, cost proxy, duration and limit state when available. |
| `auth_mode` | `local_account` / `api_key` / `local_model` / `blocked` / `unknown`. |
| `permission_mode` | Exact tool/sandbox permission mode. |
| `exit_status` | Process state, code and signal. |
| `timeout_state` | Whether the run hit a timeout. |
| `rate_limit_state` | Provider rate-limit / backoff state. |
| `invocation_state` | `real` / `planned` / `deferred` / `simulated`. |

### Rules

1. **No shell strings.** `command_argv` must be an array. A `command` or
   `command_argv` supplied as a string yields `port_status=rejected` with the
   violation `shell_string_command_rejected` and `command_argv=[]`.
2. **Explicit auth mode.** `auth_mode` is always one of `local_account`,
   `api_key`, `local_model`, `blocked`, `unknown`. It is never left blank.
3. **Honest invocation state.** `invocation_state=real` (and
   `provider_invoked=true`) requires an explicit real-invocation signal
   (`provider_invoked`/`provider_called=true`). Plans, deferrals, fixtures and
   dry runs are `planned` / `deferred` / `simulated` — never `real`.
4. **No raw secrets in the port output.** `command_argv` is run through
   `AtlasSecurity::redactCommand`; stream event excerpts are redacted and
   truncated.

## Port 5 — Agent Execution Session Store

`AgentExecutionSessionStoreService` is a durable append-only JSONL store.

Path: `storage/atlas/software-company-stewardship/agent-execution/sessions.jsonl`

Schema: `atlas.agent_execution.session_store.v1`

### Rules

1. **Append-only JSONL**, one record per agent execution.
2. **Idempotent by `session_hash`.** `session_hash` is a deterministic content
   hash (excludes wall-clock). Recording the same facts twice returns the first
   record and does not append a duplicate.
3. **Never stores raw secrets.** API keys, bearer tokens, raw resume tokens, env
   secrets and raw prompts/contexts are never persisted. The record keeps:
   - `prompt_hash`, `context_hash`, `command_hash`, `worktree_hash`;
   - `resume_token_present` (bool) + `resume_token_hash` — never the raw token;
   - a redacted `command_argv` and redacted, truncated stream-event excerpts.
   Every string value is passed through `AtlasSecurity::redactString` as a final
   net (value-level, so the deliberately-safe `*_hash` / `*_present` fields
   survive while any embedded secret is scrubbed).
4. **Replay.** `replay(session_id)` returns all records for a provider session id
   (or agent session id); `latestByCycleId(cycle_id)` returns the most recent
   record for a loop cycle when `cycle_id` is present.

## Integration (minimal, non-invasive)

- `AutonomousEvolutionSessionService` (AP-786) preserves the provider facts it
  already has in each cycle receipt by projecting them through the provider port
  and, when recording, appending them to the session store. It does **not**
  change selection, owner-flow execution or merge, and it never invokes a
  provider through this path.
- `Loop24hCertificationHarnessService` (AP-792) detects an optional capability
  `provider_port_session_store` (AP-795). When absent it reports `partial`
  (never a false pass); when present it certifies the substrate floor is wired.

## Safety Rules

- No provider is ever invoked by AP-795. The port is a normalizer; the store is
  a recorder.
- No shell-string execution and no shell-string command is accepted as argv.
- No raw secret is ever written to durable storage.
- `invocation_state=real` is never inferred — it requires an explicit signal.
- AP-795 never claims AP-793 is complete; it implements two of six ports.
- No test double, mock or fixture is wired at runtime; they exist only in unit
  tests, never in canonical authority and never in `claim_policy`.

## Acceptance

- The provider port normalizes AP-759/AP-786 payloads to
  `atlas.agent_execution.provider_port.v1` without executing a provider.
- A shell-string command is rejected with `shell_string_command_rejected`.
- `auth_mode` is always one of the five explicit modes.
- `real`, `planned`, `deferred` and `simulated` are distinguished, and `real`
  requires an explicit invocation signal.
- The session store appends idempotently by `session_hash`.
- No api key, bearer token, raw resume token, env secret or raw prompt is ever
  persisted; only hashes and redacted excerpts.
- `replay(session_id)` and `latestByCycleId(cycle_id)` return recorded facts.
- New unit tests prove real/planned/simulated, shell-string rejection, secret
  redaction, JSONL idempotency and replay.
