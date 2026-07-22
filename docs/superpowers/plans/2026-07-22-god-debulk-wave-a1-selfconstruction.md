# GOD-DEBULK A1 — SelfConstruction readiness

Status: active — Tasks 1 and 2 are complete; A1-SC-0020..0021 remain queued.

Source: `docs/evidence/2026-07-22-atlas-server-god-debulk/META-FINDINGS/A1--SelfConstruction.md`, finding `A1-SC-0019`; implementation order in `SelfConstructionReadiness.md` Phase 0.

## Task 1: A1-SC-0019 Schema preflight reachability

Status: complete in `0a630e750`.

1. Add an executable regression test to the existing focused section test.
   It must instantiate `ReadinessProjectionAgentCodexSection` and invoke a
   public `agentCodex*Preflight` method.  The RED run must reach the actual
   unresolved `App\\Services\\Ai\\SelfConstruction\\Readiness\\Schema` error;
   reflection, mocks, aliases, and a test-only class are not evidence.
2. Confirm the selected preflight's contract-template method is independently
   reachable. Prefer `agentCodexRealInvokerPostStartLivenessMonitorPreflight`
   if it produces the required RED error.
3. Make the minimal production change: import
   `Illuminate\\Support\\Facades\\Schema`. Do not change a method body or add
   a wrapper, fallback, or schema alias.
4. Make the same invocation pass and assert its typed, read-only preflight
   payload contains the expected storage readiness keys. A `blocked` status is
   legitimate when the test database lacks the future tables.
5. Update the EXEC ledger only with this task's actual evidence. Do not begin
   `A1-SC-0020`, `A1-SC-0021`, a hash change, a bridge, a split, or an owner
   extraction in this cycle.

## Constraints

- Local `main` only; preserve unrelated WIP and concurrent META/ARCH work.
- This explicit Phase-0 bugfix is the narrow exception to the density finding
  for the pre-existing 13k-line section; do not claim a density improvement.
- Use test-first evidence, no reflection-only or import-only test.
- Touch only the target production file, its focused test, and the claimed
  EXEC plan/cursor documents.
- Commit only claimed task files with a permitted `test(core): GOD-DEBULK A1 ...`
  subject.

## Acceptance

```bash
/opt/homebrew/bin/php artisan test --parallel tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentCodexSectionTest.php
/opt/homebrew/bin/php -l app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentCodexSection.php
/opt/homebrew/bin/php -l tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentCodexSectionTest.php
bash scripts/god-debulk-guard.sh
/opt/homebrew/bin/php scripts/god-debulk-codemap-verify.php
git diff --check
```

## Completion evidence

Complete. The executable regression recorded the RED unresolved
`Readiness\\Schema` output before the facade import and the GREEN focused test
output after it. A1-SC-0020..0021 remain queued and are not part of this task.

## Task 2: A1-SC-0076 typed ProviderAdapter boundary

Status: complete.

The direct A1-SC-0020 regression cannot reach its upstream real-invoker
preflight yet: `ReadinessProjectionAgentCodexSection` calls two ProviderAdapter
methods that are owned by `ReadinessProjectionAgentDispatchProviderSection`.
This task restores only that concrete sibling edge.

1. Add a direct, executable facade-consumer regression that initially fails
   with the undefined `agentProviderAdapterRegistryPreflight` call. It must
   invoke `AtlasSelfConstructionReadinessService::agentCodexProviderExecutionContractTemplate`,
   not use reflection, a mock, a test-only alias, or a fake payload.
2. Inject `ReadinessProjectionAgentDispatchProviderSection` into
   `ReadinessProjectionAgentCodexSection` through the service's existing lazy
   resolver. Replace only the two undefined self-calls with direct calls on
   that typed collaborator. Do not add `__call`, Reflection, optional/null
   fallback, a new interface, or a broad extraction.
3. Assert the real facade consumer returns its v1 read-only template and both
   source ProviderAdapter preflight hashes. Keep the existing command consumer
   test as compatibility proof.
4. Do not change the A1-SC-0020 doubled hash key, A1-SC-0021 bridge cycle,
   status semantics, provider runtime behavior, or any unrelated ownership.

## Task 2 acceptance

```bash
/opt/homebrew/bin/php artisan test --parallel tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentCodexSectionTest.php
/opt/homebrew/bin/php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_provider_execution_contract_template
/opt/homebrew/bin/php -l app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentCodexSection.php
/opt/homebrew/bin/php -l app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
bash scripts/god-debulk-guard.sh
/opt/homebrew/bin/php scripts/god-debulk-codemap-verify.php
git diff --check
```

Task 2 completion evidence is complete: the direct facade regression recorded
the undefined `agentProviderAdapterRegistryPreflight` RED at line 86, then
returned the v1 read-only template with both source ProviderAdapter hashes.
The compatibility command consumer is GREEN. A1-SC-0020..0021 remain queued
and were not changed.
