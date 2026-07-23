# GOD-DEBULK A1 — SelfConstruction readiness

Status: active — Tasks 1 through 5 and A1-SC TEST characterization are complete; Task 6 records the status-authority implementation route.

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

## Task 5: A1-SC-0096 durable-reservation contract consistency

Status: complete.

`ReadinessProjectionDurableReservationSection` is under the 2,000-LOC limit
and emits three related but contradictory read-only work orders. The ledger
plan requires the immutable `atlas_self_construction_packet_snapshots` table
and the states `available` and `renewed`; the storage schema and migration
blueprint omit that table, while the storage schema instead exposes `preview`
and omits `renewed`.

1. Add a direct public-facade regression invoking the ledger plan, storage
   schema, migration blueprint, and lease lifecycle. Before the correction it
   must expose the missing snapshot table and state-vocabulary mismatch. Do
   not use reflection, mocks, aliases, fake payloads, or database mutation.
2. Make the smallest projection-only correction: all three work orders must
   declare the same three tables, migration files/rollback/tests must include
   snapshots, and all lifecycle/schema state lists must use the same canonical
   `available`, `claimed`, `renewed`, `released`, `expired`, `completed`,
   `blocked` vocabulary.
3. Keep every envelope read-only and preserve all false authority flags,
   schema versions, statuses, commands, and actual migration/storage behavior.
   Keep the file below 2,000 LOC.
4. Make the same real facade regression GREEN; assert ordered table equality,
   state equality, snapshot migration/rollback coverage, and false execution,
   migration, storage-write and dispatch authority flags.
5. Use `refactor(core): GOD-DEBULK A1 ...` for the application diff, then a
   scoped `docs(core):` receipt with the actual primary hash and concise
   command stdout. Do not amend/rebase/rewrite local `main` history.

Task 5 completion evidence is complete. The direct public-facade RED observed
storage tables `[reservation_events, reservations]` against the canonical
`[reservations, reservation_events, packet_snapshots]` vector. The GREEN
facade regression confirms all three table vectors, the ordered states
`available, claimed, renewed, released, expired, completed, blocked`, snapshot
migration/rollback/test metadata, and false authority flags. The focused
ParaTest is green (5 tests, 36 assertions); syntax, 2k-LOC check (1,972 LOC),
GOD-DEBULK guard, CODEMAP verifier and diff check pass. The section's existing
dynamic `__call` pattern remains incompatible with standalone PHPStan analysis
(27 pre-existing errors); no PHPStan suppression or facade change was added.

## Task 6: A1-SC-0003/0004 status authority and fail-closed route

Status: planned from characterization commit `01d67824c`.

**Goal:** existing status entrypoints become genuinely side-effect free, named
runtime commands report their durable work truthfully, and an absent
merge-review authority value denies promotion/completion instead of allowing it.

**Architecture:** Use the blueprint's bounded `ControlPlaneStatusProjector`
and `AgentControlPlaneRuntime` owners, each below 800 LOC. The 29k readiness
facade and 1.6k mother command are not eligible for direct edits under the
hard density rule; an architect-supplied bounded compatibility seam must route
the legacy flags before this plan changes their behavior.

**Contracts:**

- Existing `atlas.self_construction_agent_control_plane_*_status.v1` schemas
  remain versioned status envelopes.
- A status projection reports `runtime_write_performed=false` and leaves queue,
  lease, and publisher storage snapshots unchanged.
- A named runtime command reports `runtime_write_performed=true` only with the
  persisted packet/lease/artifact IDs and an idempotency key from that command.
- Missing `promotion_allowed` or `completion_claim_allowed` maps to `false`
  plus a `missing_authority_field` violation.

### Task 6.1: Freeze the remaining writer-backed status contracts

**Files:**

- Modify: `tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTaskLeaseRecoveryTest.php`
- Modify: `tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTaskAutoReplenishmentTest.php`
- Modify: `tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTerminalWorkerBootstrapTest.php`
- Modify: `tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherTest.php`

- [x] **Step 1: Write the writer-side-effect characterization for every listed family.**

```php
$queueBefore = (int) $queue->registry()['total_count'];
$leasesBefore = count($leases->activeLeases());
$payload = app(AtlasSelfConstructionReadinessService::class)->agentControlPlaneTerminalWorkerBootstrapStatus($options);

$this->assertFalse((bool) $payload['runtime_write_allowed']);
$this->assertTrue((bool) data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.runtime_claim_persisted'));
$this->assertGreaterThan($queueBefore, (int) $queue->registry()['total_count']);
$this->assertGreaterThan($leasesBefore, count($leases->activeLeases()));
```

- [x] **Step 2: Record the pre-reroute baseline without treating it as green.**

Run:

```bash
/opt/homebrew/bin/php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTaskLeaseRecoveryTest.php tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTaskAutoReplenishmentTest.php tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTerminalWorkerBootstrapTest.php tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherTest.php --no-coverage --compact
```

Expected: each existing unrelated failure remains named separately; every new
characterization assertion establishes the current writer status before reroute.

Observed: the four-file baseline remains red with 11 existing failures and 67
passing tests (685 assertions); the lease-recovery, replenishment,
non-preview bootstrap, and publisher mutation characterizations each pass in
isolation.

### Task 6.2: Implement bounded read and write owners

**Files:**

- Create: `app/Services/Ai/SelfConstruction/ControlPlane/ControlPlaneStatusProjector.php`
- Create: `app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneRuntime.php`
- Create: `tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneStatusTruthfulnessTest.php`

**Interfaces:**

```php
final class ControlPlaneStatusProjector
{
    /** @return array<string, mixed> */
    public function projectTaskLeaseRecovery(array $options = []): array;
    /** @return array<string, mixed> */
    public function projectTaskQueueOrchestrator(array $options = []): array;
    /** @return array<string, mixed> */
    public function projectTaskQueueClaimNext(array $options = []): array;
    /** @return array<string, mixed> */
    public function projectTaskAutoReplenishment(array $options = []): array;
    /** @return array<string, mixed> */
    public function projectTerminalWorkerBootstrap(array $options = []): array;
    /** @return array<string, mixed> */
    public function projectOperatorEvidenceDraftWorkspacePublisher(array $options = []): array;
}

final class AgentControlPlaneRuntime
{
    /** @return array<string, mixed> */
    public function runTaskLeaseRecovery(array $options = []): array;
    /** @return array<string, mixed> */
    public function runTaskQueueOrchestrator(array $options = []): array;
    /** @return array<string, mixed> */
    public function runTaskQueueClaimNext(array $options = []): array;
    /** @return array<string, mixed> */
    public function runTaskAutoReplenishment(array $context = [], array $options = []): array;
    /** @return array<string, mixed> */
    public function runTerminalWorkerBootstrap(array $context = [], array $options = []): array;
    /** @return array<string, mixed> */
    public function runOperatorEvidenceDraftWorkspacePublisher(array $options = []): array;
}
```

- [ ] **Step 1: Write the failing read-only projection test.**

```php
$before = $this->controlPlaneSnapshot();
$payload = $projector->projectTerminalWorkerBootstrap(['queue_tags' => ['truthfulness']]);

$this->assertFalse((bool) $payload['runtime_write_performed']);
$this->assertSame($before, $this->controlPlaneSnapshot());
```

- [ ] **Step 2: Write the failing named-runtime test.**

```php
$queue = new AgentControlPlaneTaskPacketQueueRepository;
$leases = new AgentControlPlaneClaimLeaseRepository;
$payload = $runtime->runTerminalWorkerBootstrap($this->context(), [
    'actor' => 'truthfulness-runtime',
    'target_min_claimable_tasks' => 1,
    'max_new_tasks' => 1,
    'queue_tags' => ['truthfulness'],
]);

$this->assertTrue((bool) $payload['runtime_write_performed']);
$this->assertNotEmpty($payload['persisted_artifact_ids']['task_packet_id']);
$this->assertNotEmpty($payload['persisted_artifact_ids']['lease_id']);
$this->assertNotEmpty($payload['idempotency_key']);

$this->assertSame('claimed', data_get($queue->get($payload['persisted_artifact_ids']['task_packet_id']), 'status'));
$this->assertSame($payload['persisted_artifact_ids']['task_packet_id'], data_get($leases->get($payload['persisted_artifact_ids']['lease_id']), 'task_packet_id'));
$stateAfterFirstRun = [
    'queue_registry' => $queue->registry(),
    'active_leases' => $leases->activeLeases(),
];
$replay = $runtime->runTerminalWorkerBootstrap($this->context(), $payload['replay_options']);
$this->assertFalse((bool) $replay['runtime_write_performed']);
$this->assertSame($payload['idempotency_key'], $replay['idempotency_key']);
$this->assertSame($payload['persisted_artifact_ids']['task_packet_id'], $replay['persisted_artifact_ids']['task_packet_id']);
$this->assertSame($payload['persisted_artifact_ids']['lease_id'], $replay['persisted_artifact_ids']['lease_id']);
$this->assertSame($stateAfterFirstRun, [
    'queue_registry' => $queue->registry(),
    'active_leases' => $leases->activeLeases(),
]);
```

- [ ] **Step 3: Implement the two owners using the existing ControlPlane services.**

`ControlPlaneStatusProjector` owns the six declared `project*` families, reads
repository registries only, and sets every write/dispatch/provider/token/ledger
authority field to `false`. `AgentControlPlaneRuntime` owns the matching six
`run*` families, invokes the existing writer, derives persisted IDs from that
writer's actual result, and returns replay options keyed by its idempotency key.
It never synthesizes an ID or treats an idempotent replay as a new write.

- [ ] **Step 4: Run the new focused tests.**

Run:

```bash
/opt/homebrew/bin/php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneStatusTruthfulnessTest.php --no-coverage --compact
```

Expected: PASS with a zero-side-effect projection and a truthful named writer.

### Task 6.3: Apply fail-closed merge-review authority

**Files:**

- Create: `app/Services/Ai/SelfConstruction/Readiness/ReadinessFailClosedPolicy.php`
- Modify: architect-supplied bounded compatibility seam for merge-review status
- Modify: `tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneStatusTruthfulnessTest.php`

**Interface:**

```php
final class ReadinessFailClosedPolicy
{
    /** @return array{promotion_allowed: bool, completion_claim_allowed: bool, authority_violations: list<array{code: string, field: string}>} */
    public function mergeReviewAuthority(array $result): array;
}
```

- [ ] **Step 1: Write the failing absent-authority regression.**

```php
$status = (new ReadinessFailClosedPolicy)->mergeReviewAuthority([]);

$this->assertFalse((bool) $status['promotion_allowed']);
$this->assertFalse((bool) $status['completion_claim_allowed']);
$this->assertSame([
    ['code' => 'missing_authority_field', 'field' => 'promotion_allowed'],
    ['code' => 'missing_authority_field', 'field' => 'completion_claim_allowed'],
], $status['authority_violations']);
```

- [ ] **Step 2: Implement false defaults and violation evidence.**

The compatibility seam must use
`data_get($result, 'promotion_allowed', false)` and
`data_get($result, 'completion_claim_allowed', false)`, then append one
`missing_authority_field` record for every absent source key.

- [ ] **Step 3: Run the fail-closed regression.**

Run:

```bash
/opt/homebrew/bin/php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneStatusTruthfulnessTest.php --filter=missing_authority --no-coverage --compact
```

Expected: PASS; a ready-looking status string cannot grant an absent authority.

### Task 6.4: Reroute legacy flags only through the bounded compatibility seam

**Files:**

- Modify: architect-supplied compatibility seam below 800 LOC
- Modify: `app/Console/Commands/Support/AtlasSelfConstructionMotherCommandSurface.php`
- Modify: `tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneStatusTruthfulnessTest.php`

- [ ] **Step 1: Route each legacy `*-status` flag to its projector and add a distinct `*-run` flag for the runtime owner.**

- [ ] **Step 2: Assert a legacy status call leaves the stored snapshot unchanged and a `*-run` call returns persisted IDs.**

- [ ] **Step 3: Run the finding acceptance and density gates.**

```bash
rg -n 'function .*Status' app/Services/Ai/SelfConstruction/Readiness | wc -l
rg -n "promotion_allowed.*true|completion_claim_allowed.*true" app/Services/Ai/SelfConstruction/Readiness
/opt/homebrew/bin/php artisan test --parallel tests/Feature/Ai/SelfConstruction tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTaskLeaseRecoveryTest.php
find app/Services/Ai/SelfConstruction/ControlPlane -name '*.php' -print0 | xargs -0 wc -l | awk '$1 > 2000 {print}'
wc -l app/Services/Ai/SelfConstruction/ControlPlane/ControlPlaneStatusProjector.php app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneRuntime.php | awk '$1 > 800 {print}'
bash scripts/god-debulk-guard.sh
git diff --check
```

Expected: no targeted permissive true default remains; each new owner is below
800 LOC (and therefore below 2,000 LOC); any unrelated baseline failure is
recorded as red evidence.
