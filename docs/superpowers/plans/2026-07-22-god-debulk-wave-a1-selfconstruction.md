# GOD-DEBULK A1 — SelfConstruction readiness

Status: active — Tasks 1 through 4 are complete; A1-SC-0001..0008 are queued.

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

## Task 3: A1-SC-0020 real upstream hash binding

Status: complete.

Before this task,
`agentCodexRealInvokerPostStartRealInvokerReleasePreflightGateContractTemplate`
passed the nonexistent `codex_real_invoker_release_preflight_preflight_hash`
key to `ReadinessHash`. The real upstream result exposes
`codex_real_invoker_release_preflight_hash`, so the prior contract identity
silently bound `null` instead of that evidence.

1. Add an executable regression using the real public facade upstream
   preflight and the real public post-start contract template. It must first
   fail against the current doubled key. No mock, subclass, alias, reflection,
   fake upstream payload, schema mutation, or production API test seam.
2. Derive the expected contract ID using the actual dry-run hash and actual
   real-invoker release-preflight hash, then assert the public contract ID
   equals it. Assert a second deterministic input vector with a changed
   release-preflight hash produces a different ID; this demonstrates that this
   real upstream evidence participates in identity without perturbing the
   shared schema state that also affects the dry-run branch.
3. Make exactly the key correction in the production hash input. Do not rename
   the existing `*_preflight_preflight` payload/schema fields, change the
   bridge, alter a status, or add a generic hash helper.
4. Keep A1-SC-0021 entirely out of scope.

## Task 3 acceptance

```bash
/opt/homebrew/bin/php artisan test --parallel tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentCodexSectionTest.php
/opt/homebrew/bin/php -l app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentCodexSection.php
bash scripts/god-debulk-guard.sh
/opt/homebrew/bin/php scripts/god-debulk-codemap-verify.php
git diff --check
```

Task 3 completion evidence is complete. The public regression recorded the
real release-preflight hash, a null doubled lookup, and the RED expected-ID
mismatch; after the one-key correction, the public contract identity matches
the real upstream and dry-run hash vector. A1-SC-0021 remains queued and was
not changed.

## Task 4: A1-SC-0021 post-start evidence producer-consumer direction

Status: complete.

The receipt builder and evidence receipt writer must be upstream producers.
The acceptance bridge may consume their preflights and is the sole producer of
`post_start_evidence_acceptance_bridge_id`. A producer cannot require that
downstream result as an input, output, must-rule, preflight change, task
acceptance, or packet criterion.

1. Add a direct executable regression through the real public readiness
   facade/templates. Before the production correction it must show the receipt
   input contains the bridge ID twice and the evidence writer requires it.
   No reflection, mocks, aliases, fake payloads, or schema-state
   mutation.
2. Reorient only the post-start receipt-builder, evidence-receipt-writer, and
   evidence-acceptance-bridge contract/preflight/packet metadata necessary to
   form this acyclic direction: handoff -> receipt -> evidence receipt ->
   acceptance bridge. Remove the bridge ID from upstream producer inputs,
   results, requirements, and implementation acceptance wording. The final
   bridge alone may retain its correlation/idempotency input and must retain
   exactly one result occurrence; it must no longer forward that input to an
   upstream producer.
3. Keep every envelope read-only and fail-closed. Do not change statuses,
   schema versions, flags, commands, provider invocation, the A1-SC-0020 hash
   binding, or later consumers of the bridge ID.
4. Make the same public runtime regression pass, including the real bridge
   preflight. The proof must assert the two upstream input counts are zero,
   the bridge output count is one, the source preflights are observed, and
   `execution_allowed` / `dispatch_allowed` remain false.
5. Correct the equivalent three concrete Support services and their focused
   tests, plus the narrow bridge-contract test. Do not edit the 31k-line
   command test: its stale route assertions are a separate test-monster split
   action under the hard no-edited-PHP-over-2000-LOC rule.
6. Update only the claimed execution cursor/ledger and record actual commands
   and short stdout. Commit an app-containing diff as
   `refactor(core): GOD-DEBULK A1 ...`; never rewrite the prior historical
   label hold.

## Task 4 acceptance

```bash
/opt/homebrew/bin/php artisan test --parallel tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentCodexSectionTest.php
/opt/homebrew/bin/php -l app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentCodexSection.php
/opt/homebrew/bin/php -l tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentCodexSectionTest.php
bash scripts/god-debulk-guard.sh
/opt/homebrew/bin/php scripts/god-debulk-codemap-verify.php
git diff --check
```

Task 4 completion evidence is complete. The public readiness-facade regression
first observed the duplicate receipt input bridge ID (`2`), one writer input,
and the stale writer requirement. It now proves both upstream input counts are
zero, the final bridge result occurs once, both producer preflights are
observed, and each envelope remains non-executing/non-dispatching. The focused
ParaTest files are green (9/42, 12/38, 9/37, 13/49, and 1/6); PHPStan, syntax,
the debulk guard, CODEMAP verification, and diff check are green. Strict Pint
is clean for the focused files and Support services; the 13k-line Codex
projection retains its four pre-existing style findings.
