<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSystemAxisService;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopDeliveryPipeline;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * LOOP-OS · FASE 2 · S2 — the PROJECTION worker: the async drainer that turns a DISPATCHED projection row
 * into a typed-obligation TASK (or a PARK), severed off the refiller hot-loop.
 *
 * The canon's quality phase ("projeção frontier + crítica independente em loop") is run here, ONCE per
 * claimed objective, by the FROZEN {@see AtlasLoopProjectionEngine}: the binding system axis is re-resolved
 * FRESH from the campaign workspace (the frozen {@see AtlasLoopSystemAxisService::vector()}, never the
 * producer's rationale string), a DETERMINISTIC designer seeds the typed obligations the objective's shape
 * implies (mapped so the binding axis is covered), and a DETERMINISTIC critic raises-then-resolves ONE
 * material obligation. The engine's set-theoretic convergence is the gate:
 *   - !converged (no critic engaged / binding axis uncovered / oscillation) ⇒ {@see AtlasLoopDeliveryPipeline::park}
 *     and NO task is minted (a projection that did not converge never becomes work);
 *   - converged ⇒ the engine's typed obligations are attached into the task payload (`_obligations`) and the
 *     acceptance (`obligations`), the original producer envelope is enqueued as a first-class task, and the
 *     pipeline row is COMPLETED (removed) so it stops counting as open work.
 *
 * HONEST RESIDUAL (the engine's documented §9, model-bound): genuine CROSS-MODEL independence (designer on
 * one provider key, critic on a DISTINCT key) is a provider-infra ceiling. This worker enforces the ROLE
 * separation (writer ≠ judge) + the deterministic convergence; the designer/critic are DETERMINISTIC role
 * closures in v1 — this is role-separation, NOT a faked cross-model panel. The PARK branches are reachable
 * by construction (an objective whose shape cannot cover the binding axis parks; a critic that does not
 * engage parks), so the gate is not a rubber-stamp.
 *
 * Frozen organs are CALLED here, never edited: AtlasLoopProjectionEngine + AtlasLoopSystemAxisService are
 * EV-brain/pétreo classes the réu may not touch.
 */
final class AtlasLoopProjectionWorker
{
    public function __construct(
        private readonly AtlasLoopStore $store,
        private readonly ?AtlasLoopDeliveryPipeline $pipeline = null,
        private readonly ?AtlasLoopProjectionEngine $engine = null,
        private readonly ?AtlasLoopSystemAxisService $axisService = null,
        private readonly ?AtlasLoopScopeComprehensionModel $scopeModel = null,
        private readonly ?AtlasLoopProjectionOutcomeLedger $ledger = null,
        // P5 lensed-critique seam — nullable LAST arg (back-compat: legacy callers autowire null ⇒ a default
        // critic, byte-identical). Injectable so a test can count the per-lens passes without a real provider.
        private readonly ?AtlasLoopModelProjectionCritic $critic = null,
        // ACDE memory wiring — nullable, flag-gated, fail-open. The recall is consulted AFTER the pétreo
        // forbidden short-circuit (never invoked when the target is parked), and any throw inside the recall
        // is caught so the deterministic projection path stays the source of truth.
        private readonly ?AtlasLoopProjectionMemoryRecall $memoryRecall = null,
    ) {}

    /**
     * Consult the memory recall when wired AND the target is non-forbidden. Returns the list of matching
     * memory items (kind/scope/text/source_ref) or an empty list. Failure modes (recall throws, empty
     * target, flag off) all collapse to []. NEVER weakens the caller's deterministic obligation set.
     *
     * @return list<array{kind:string, scope:string, text:string, source_ref:string}>
     */
    public function recallMemoryHits(string $relTarget, string $bindingAxis): array
    {
        if ($this->memoryRecall === null) {
            return [];
        }
        try {
            $hits = $this->memoryRecall->forTarget($relTarget, $bindingAxis);
        } catch (Throwable) {
            return [];
        }

        return is_array($hits) ? array_values($hits) : [];
    }

    /**
     * Run the projection for a single CLAIMED pipeline row (its decoded shape from
     * {@see AtlasLoopDeliveryPipeline::claimNextProjection}). Returns the terminal outcome so the supervisor
     * can log it. Fail-CLOSED: any error parks the objective (never mints an unprojected task) — a projection
     * that could not be honestly run is not allowed to become work.
     *
     * @param  array<string,mixed>  $row  the decoded pipeline_state row (objective_id + checkpoint)
     * @return array{outcome:string, objective_id:string, status?:string, reason?:string}
     */
    public function process(array $row): array
    {
        $pipeline = $this->pipeline ?? new AtlasLoopDeliveryPipeline;
        $objectiveId = (string) ($row['objective_id'] ?? '');
        if ($objectiveId === '') {
            return ['outcome' => 'skipped', 'objective_id' => '', 'reason' => 'no_objective_id'];
        }

        try {
            $checkpoint = is_array($row['checkpoint'] ?? null) ? (array) $row['checkpoint'] : [];
            $envelope = is_array($checkpoint['built'] ?? null) ? (array) $checkpoint['built'] : [];
            $repoRoot = rtrim((string) ($checkpoint['repoRoot'] ?? ''), '/');
            $campaignId = (string) ($row['campaign_id'] ?? '');

            // FROZEN call — re-resolve the binding axis FRESH from the campaign workspace. Never parse the
            // producer's rationale string (that would let a rephrase pick the axis); the axis service
            // re-grades ground truth every call, so the projection addresses the REAL bottleneck.
            $axis = $this->axisService ?? new AtlasLoopSystemAxisService;
            $bindingAxis = (string) (($axis->vector($repoRoot))['binding_axis'] ?? 'wired');

            // §3 ARCHITECT PHASE — when armed, run the GROUNDED design↔critique critic (it raises a
            // consumer_intact obligation for every REAL caller of the target, so the contract provably
            // protects them and a high-fan-out target parks). Fail-OPEN to the scripted roles: a missing
            // model / unresolved target never blocks a projection, it just falls back to the v1 floor.
            $target = $this->targetSymbol($envelope);

            // §5 LEARNING — a target this campaign ALREADY parked as a pétreo cert organ is permanently
            // off-limits; skip the ~8s comprehension-model rebuild and park immediately. The only safe
            // self-suppression (forbidden = logically certain), flag-gated, fail-open.
            if ((bool) config('atlas.loop.projection_outcome_learning_enabled', false)
                && ($this->ledger ?? new AtlasLoopProjectionOutcomeLedger)->wasForbidden($campaignId, $target)) {
                $pipeline->park($objectiveId, 'forbidden_target_petreo');

                return ['outcome' => 'parked', 'objective_id' => $objectiveId, 'status' => 'parked', 'reason' => 'forbidden_target_petreo_learned'];
            }

            $payloadKind = is_array($envelope['payload'] ?? null) ? (string) (($envelope['payload']['objective_kind'] ?? '')) : '';
            $objectiveKind = $payloadKind !== '' ? $payloadKind : (string) ($envelope['shape'] ?? '');
            $grounded = $this->groundedRolesFor($repoRoot, $target, $bindingAxis, $objectiveKind);

            // §3 PÉTREO CONSTITUTION GUARD — the architect phase refuses to project a change to a cert organ
            // (frozen judge / projection engine / harness guard). Park it explicitly, mint no task: the loop
            // must never design its way into weakening the gate that judges it.
            if (($grounded['forbidden'] ?? false) === true) {
                $this->recordOutcome($campaignId, $target, AtlasLoopProjectionOutcomeLedger::STATUS_PARKED, 'forbidden_target_petreo');
                $pipeline->park($objectiveId, 'forbidden_target_petreo');

                return ['outcome' => 'parked', 'objective_id' => $objectiveId, 'status' => 'parked', 'reason' => 'forbidden_target_petreo'];
            }

            $designer = $grounded['designer'] ?? $this->designerFor($bindingAxis, $envelope);
            $critic = $grounded['critic'] ?? $this->criticClosure();

            $engine = $this->engine ?? new AtlasLoopProjectionEngine;
            $result = $engine->project($bindingAxis, $designer, $critic, (int) config('atlas.loop.projection_max_rounds', 8));

            if (($result['status'] ?? '') !== 'converged') {
                $this->recordOutcome($campaignId, $target, AtlasLoopProjectionOutcomeLedger::STATUS_PARKED, (string) ($result['reason'] ?? 'not_converged'));
                $pipeline->park($objectiveId, (string) ($result['reason'] ?? 'not_converged'));

                return ['outcome' => 'parked', 'objective_id' => $objectiveId, 'status' => (string) ($result['status'] ?? 'parked'), 'reason' => (string) ($result['reason'] ?? 'not_converged')];
            }

            // CONVERGED — attach the engine's typed obligations into the task spec, then enqueue the original
            // producer envelope as a first-class task (rebuilt from the checkpoint, NOT re-produced).
            $obligations = is_array($result['obligations'] ?? null) ? array_values((array) $result['obligations']) : [];
            $consumerContracts = $this->consumerContractsFor($obligations, (array) ($grounded['consumers'] ?? []), $this->targetSymbol($envelope), $repoRoot);
            $task = $this->enqueueFromEnvelope($campaignId, $envelope, $checkpoint, $obligations, $bindingAxis, $consumerContracts);
            if (! $task instanceof AtlasLoopTask) {
                $pipeline->park($objectiveId, 'projection_enqueue_no_live_task');

                return ['outcome' => 'enqueue_failed', 'objective_id' => $objectiveId, 'status' => 'converged', 'reason' => 'projection_enqueue_no_live_task'];
            }

            $this->recordOutcome($campaignId, $target, AtlasLoopProjectionOutcomeLedger::STATUS_CONVERGED, null);
            $pipeline->complete($objectiveId);

            return ['outcome' => 'enqueued', 'objective_id' => $objectiveId, 'status' => 'converged'];
        } catch (Throwable $e) {
            // Fail-closed: an unprojectable objective is PARKED, never minted as a task.
            try {
                $pipeline->park($objectiveId, 'projection_error:'.mb_substr($e->getMessage(), 0, 80));
            } catch (Throwable) {
                // parking is best-effort; never let a park failure escape the worker
            }

            return ['outcome' => 'parked', 'objective_id' => $objectiveId, 'status' => 'error', 'reason' => mb_substr($e->getMessage(), 0, 120)];
        }
    }

    /**
     * §3 ARCHITECT PHASE — the GROUNDED designer/critic pair, or [] (use the scripted v1 floor) when the
     * grounded phase is disabled, the target is unresolved, or the comprehension model cannot be obtained.
     * Building the model is heavy (~seconds), so it runs ONLY here — once per claimed projection, off the
     * refiller hot-loop — and only when the flag is armed; an injected model (tests) skips the build.
     * Fail-OPEN: any failure returns [] so a projection is never blocked, only downgraded to the v1 floor.
     *
     * @return array{designer?: callable, critic?: callable}
     */
    private function groundedRolesFor(string $repoRoot, string $target, string $bindingAxis = 'wired', string $objectiveKind = ''): array
    {
        if (! (bool) config('atlas.loop.grounded_projection_enabled', false) || trim($target) === '') {
            return [];
        }

        try {
            $model = $this->scopeModel ?? $this->buildScopeModel($repoRoot);
            if (! $model instanceof AtlasLoopScopeComprehensionModel) {
                return [];
            }

            // §3 cross-model critique (flag-gated, fail-closed): a frontier model proposes ADDITIONAL grounded
            // obligations on top of the deterministic floor; the engine's grounding gate rejects any
            // ungrounded one, so a garbage completion is harmless and no-provider ⇒ [] ⇒ the floor alone.
            $extraSeeds = [];
            if ((bool) config('atlas.loop.grounded_projection_model_critic_enabled', false)) {
                $critic = $this->critic ?? new AtlasLoopModelProjectionCritic;
                // P5 (MITIGA, NÃO ELIMINA): when armed, run the critique across MULTIPLE lenses (the depth the
                // critic ALREADY has via lensedObligations, fail-closed per lens) instead of a single pass.
                // Default OFF ⇒ the exact single-pass additionalObligations as before (byte-identical). Depth
                // diversifies the angle but the model stays single-provider — true cross-model is still open.
                $extraSeeds = (bool) config('atlas.loop.grounded_projection_lensed_critique_enabled', false)
                    ? $critic->lensedObligations($target, $bindingAxis, [], [], $this->configuredLenses())
                    : $critic->additionalObligations($target, $bindingAxis);
            }

            $roles = (new AtlasLoopGroundedProjectionRoles($model))->forTarget($target, $extraSeeds, $objectiveKind);

            return ['designer' => $roles['designer'], 'critic' => $roles['critic'], 'consumers' => $roles['consumers'] ?? [], 'forbidden' => (bool) ($roles['forbidden'] ?? false)];
        } catch (Throwable) {
            return []; // grounded phase is best-effort; never let a model failure block a projection
        }
    }

    /**
     * The operator-configured critique lenses (CSV) for the lensed-critique path; empty ⇒ the critic's
     * DEFAULT_LENSES. Each lens is a distinct angle the (single-provider) model reasons from — depth, not
     * independence. Read via config() with a code default so no config/atlas.php edit is required.
     *
     * @return list<string>
     */
    private function configuredLenses(): array
    {
        $lenses = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('atlas.loop.grounded_projection_lenses', 'correctness,security,performance,maintainability')),
        )));

        return $lenses === [] ? AtlasLoopModelProjectionCritic::DEFAULT_LENSES : $lenses;
    }

    /**
     * §3 ARCHITECT PHASE — translate the grounded projection's consumer_intact obligations into the explicit
     * consumer_contracts the cross-file consumer gate ENFORCES (it replays each caller's test in the
     * candidate workspace), so the projected contract is a real veto, not a seal. Empty when the phase is
     * off / no callers / no grounded consumer obligation.
     *
     * @param  list<array<string,mixed>>  $obligations
     * @param  list<string>  $consumers
     * @return list<array<string,mixed>>
     */
    private function consumerContractsFor(array $obligations, array $consumers, string $target, string $repoRoot): array
    {
        if ($consumers === []) {
            return [];
        }
        $changedSymbol = $this->classOf($target);
        $resolver = new \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSiblingTestResolver(rtrim($repoRoot, '/') ?: null);
        $commandFor = static function (string $caller) use ($resolver): ?string {
            $sibling = $resolver->resolve($caller);
            $path = (string) ($sibling['sibling_path'] ?? '');

            return ($sibling['has_sibling'] ?? false) && $path !== '' ? './vendor/bin/phpunit '.escapeshellarg($path) : null;
        };

        return (new AtlasLoopProjectionObligationContracts)->toConsumerContracts($obligations, $consumers, $changedSymbol, $commandFor);
    }

    /**
     * §5 LEARNING — append a projection outcome to the campaign ledger (flag-gated, best-effort). Off ⇒ a
     * pure no-op (byte-identical), so the worker is unchanged until the operator arms the learning.
     */
    private function recordOutcome(string $campaignId, string $target, string $status, ?string $reason): void
    {
        if (! (bool) config('atlas.loop.projection_outcome_learning_enabled', false)) {
            return;
        }
        ($this->ledger ?? new AtlasLoopProjectionOutcomeLedger)->record($campaignId, $target, $status, $reason);
    }

    private function buildScopeModel(string $repoRoot): ?AtlasLoopScopeComprehensionModel
    {
        $repoRoot = rtrim($repoRoot, '/');
        if ($repoRoot === '' || ! is_dir($repoRoot)) {
            return null;
        }
        $scopeRoot = (string) config('atlas.loop.grounded_projection_scope_root', 'app/Services/Ai/AutonomousEvolution');

        return (new AtlasLoopScopeComprehensionModelBuilder)->build($repoRoot, $scopeRoot, ['docs_roots' => []]);
    }

    /**
     * The DETERMINISTIC designer: round 1 seeds the typed obligations the objective's shape implies, mapped
     * so the binding axis is covered; later rounds propose nothing (they let the critic engage). The seed set
     * is chosen so that whichever utility-grade axis binds (wired|real_target|non_trivial|compounding|safety)
     * at least one obligation kind maps to it — otherwise the engine PARKS (binding axis uncovered), which is
     * the honest behaviour, not a rubber-stamp.
     *
     * @param  array<string,mixed>  $envelope  the producer's built envelope (target_path, shape, …)
     * @return callable(int, list<array<string,mixed>>):list<array<string,mixed>>
     */
    private function designerFor(string $bindingAxis, array $envelope): callable
    {
        $target = $this->targetSymbol($envelope);
        $realMutop = (string) array_key_first(AtlasLoopMutationOperators::map());

        // Seed obligations whose KIND_AXES union covers ALL five utility-grade axes, each grounded on a
        // CONCRETE assertion_ref the cert chain can run (a real mutation operator id / a characterization
        // test target / a consumer gate). consumer_intact⇒{wired,compounding}, contract_upheld⇒{wired,
        // real_target}, behavior_preserved⇒{non_trivial,safety}, mutation_killed⇒{non_trivial,safety}.
        $seed = [
            ['kind' => 'consumer_intact', 'target_symbol' => $target, 'assertion_ref' => 'consumer:'.$target],
            ['kind' => 'contract_upheld', 'target_symbol' => $target, 'assertion_ref' => 'chartest:tests/'.$this->classOf($target).'Test.php::test_contract'],
            ['kind' => 'behavior_preserved', 'target_symbol' => $target, 'assertion_ref' => 'mutop:'.$realMutop],
        ];

        return static fn (int $round, array $current): array => $round === 1 ? $seed : [];
    }

    /**
     * The DETERMINISTIC critic (writer ≠ judge): round 1 raises ONE material obligation; round 2 resolves it.
     * Engaging (raised-then-resolved ≥1) is REQUIRED for convergence — a projection the critic never
     * materially engaged parks (a silent critic is suspect, not a fixpoint).
     *
     * @return callable(list<array<string,mixed>>):array{add?:list<array<string,mixed>>, resolved?:list<string>}
     */
    private function criticClosure(): callable
    {
        $engine = $this->engine ?? new AtlasLoopProjectionEngine;
        $realMutop = (string) array_key_first(AtlasLoopMutationOperators::map());
        $round = 0;

        return function (array $current) use (&$round, $engine, $realMutop): array {
            $round++;
            // Raise a material obligation against the SAME target the designer seeded, so it normalizes onto
            // a real key the engine can later mark resolved.
            $target = is_array($current[0] ?? null) ? (string) ($current[0]['target_symbol'] ?? 'app\\loop\\unknown') : 'app\\loop\\unknown';
            $criticOb = ['kind' => 'mutation_killed', 'target_symbol' => $target, 'assertion_ref' => 'mutop:'.$realMutop];
            $key = $engine->obligationKey($criticOb);

            if ($round === 1) {
                return ['add' => [$criticOb], 'resolved' => []];
            }

            return ['add' => [], 'resolved' => $key !== null ? [$key] : []];
        };
    }

    /**
     * Rebuild the original producer enqueue from the checkpoint and enqueue it as a first-class task, with
     * the engine's typed obligations attached into BOTH the payload (`_obligations`) and the acceptance
     * (`obligations`) so the downstream cert chain sees the projected contract. Mirrors the refiller's
     * objective-producer enqueue (band priority + acceptance hash) exactly.
     *
     * @param  array<string,mixed>  $envelope
     * @param  array<string,mixed>  $checkpoint
     * @param  list<array<string,mixed>>  $obligations
     * @param  list<array<string,mixed>>  $consumerContracts
     */
    private function enqueueFromEnvelope(string $campaignId, array $envelope, array $checkpoint, array $obligations, string $bindingAxis, array $consumerContracts = []): mixed
    {
        $payload = is_array($envelope['payload'] ?? null) ? (array) $envelope['payload'] : [];
        $payload['_obligations'] = $obligations;
        $payload['_projection'] = ['binding_axis' => $bindingAxis, 'schema_version' => AtlasLoopProjectionEngine::SCHEMA_VERSION];
        if (! isset($payload['_target_id']) && isset($checkpoint['real_target_id'])) {
            $payload['_target_id'] = (string) $checkpoint['real_target_id'];
        }

        $acceptance = is_array($payload['acceptance'] ?? null) ? (array) $payload['acceptance'] : [];
        $acceptance['obligations'] = $obligations;
        // §3 close "selo sem veto": the grounded consumer_intact obligations become explicit
        // consumer_contracts the certifier's cross-file consumer gate replays — so a refactor that breaks a
        // real caller is REFUSED certification. Merge (never clobber) any contracts the producer attached.
        if ($consumerContracts !== []) {
            $existing = is_array($acceptance['consumer_contracts'] ?? null) ? (array) $acceptance['consumer_contracts'] : [];
            $acceptance['consumer_contracts'] = array_merge($existing, $consumerContracts);
        }
        $payload['acceptance'] = $acceptance;

        $targetPath = (string) ($envelope['target_path'] ?? ($payload['target_repo_path'] ?? ''));
        $payload = $this->markProjectedLoopSelfImprovement($payload, $targetPath);

        // Stamp objective_kind from the producer's SHAPE so the honest scorecard can classify projected
        // work: a missing kind lands as 'unknown' and blocks the real-work claim (the rédea's
        // projection path was minting feature/refactor tasks with no kind). feature → feature; any
        // refactor shape → a refactor kind (the self-improvement markers above then classify a loop
        // self-edit as self_improvement; the driver already vetoed proxy upstream of the projection).
        if (! isset($payload['objective_kind']) || trim((string) $payload['objective_kind']) === '') {
            $shape = (string) ($envelope['shape'] ?? 'refactor');
            $payload['objective_kind'] = str_contains($shape, 'feature') ? 'feature' : 'refactor_reduce_complexity';
        }

        $leverage = (float) ($envelope['leverage'] ?? 0.0);
        $priority = (int) ($checkpoint['priority'] ?? (4000 + min(999, (int) round($leverage * 200))));
        $hash = (string) ($envelope['acceptance_hash'] ?? '');

        $task = $this->store->enqueueTask(
            $campaignId,
            (string) ($envelope['objective'] ?? ''),
            $payload,
            'producer:objective',
            (string) ($envelope['target_path'] ?? ''),
            $priority,
            (bool) ($envelope['self_contained'] ?? true),
            $hash !== '' ? $hash : null,
        );

        if ($task instanceof AtlasLoopTask && $this->isTerminalDuplicate($task)) {
            if (! $this->reopenRetryableTerminalDuplicate($task)) {
                return null;
            }
        }

        return $task instanceof AtlasLoopTask ? $task->fresh() : $task;
    }

    private function isTerminalDuplicate(AtlasLoopTask $task): bool
    {
        return in_array((string) $task->status, [AtlasLoopTask::STATUS_DONE, AtlasLoopTask::STATUS_FAILED, AtlasLoopTask::STATUS_DEFERRED], true);
    }

    /**
     * enqueueTask is intentionally idempotent, but a projection drain must not treat an old terminal
     * no-winner duplicate as fresh supply. If the same projected task still has attempt budget, reopen
     * it as pending so the live loop can retry after newly-learned gates/prompts without queue starvation.
     */
    private function reopenRetryableTerminalDuplicate(AtlasLoopTask $task): bool
    {
        if (! in_array((string) $task->status, [AtlasLoopTask::STATUS_DONE, AtlasLoopTask::STATUS_FAILED, AtlasLoopTask::STATUS_DEFERRED], true)) {
            return false;
        }
        $result = is_array($task->result) ? $task->result : [];
        if (($result['has_winner'] ?? null) === true) {
            return false;
        }
        if ((int) $task->attempts >= max(1, (int) $task->max_attempts)) {
            return false;
        }

        return $task->forceFill([
            'status' => AtlasLoopTask::STATUS_PENDING,
            'claimed_by' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'heartbeat_at' => null,
            'result' => null,
            'updated_at' => Carbon::now(),
        ])->save();
    }

    /**
     * Projection-produced complexity refactors against the loop's own harness are governed
     * self-improvement, not ordinary proxy refactors. Keep the marker narrow so generic
     * behavior-preserving refactors cannot launder themselves as real work.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function markProjectedLoopSelfImprovement(array $payload, string $targetPath): array
    {
        $targetPath = ltrim(str_replace('\\', '/', $targetPath), '/');
        $kind = (string) ($payload['objective_kind'] ?? '');
        $acceptance = is_array($payload['acceptance'] ?? null) ? (array) $payload['acceptance'] : [];

        $hasCommand = array_values(array_filter(
            (array) ($acceptance['commands'] ?? []),
            static fn (mixed $command): bool => is_string($command) && trim($command) !== '',
        )) !== [];
        $complexityProof = $this->truthy($acceptance['complexity_proof'] ?? null)
            || $this->truthy($payload['complexity_proof'] ?? null);
        $guard = new AtlasLoopHarnessGuard;

        if ($targetPath === ''
            || ! str_starts_with($kind, 'refactor')
            || ! $complexityProof
            || ! $hasCommand
            || ! $guard->isHarnessTarget($targetPath)) {
            return $payload;
        }

        $bar = (float) ($acceptance['quality_bar'] ?? $payload['quality_bar'] ?? config('atlas.loop.quality_bar', AtlasLoopQualityGrader::DEFAULT_BAR));
        $bar = max($bar, AtlasLoopQualityGrader::DEFAULT_BAR);

        $acceptance['quality_bar_gate'] = true;
        $acceptance['quality_bar'] = $bar;
        $payload['acceptance'] = $acceptance;
        $payload['is_self_improvement'] = true;
        $payload['quality_bar'] = $bar;

        return $payload;
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * The target symbol for the obligations, drawn from the envelope's REAL target_path (a real file path
     * the cert chain can resolve). Returns '' for a degenerate envelope with NO target — deliberately NOT a
     * fabricated fallback: an ungrounded target makes the engine reject the obligations and PARK (the honest
     * non-converged path), rather than minting a task with phantom obligations. A real producer envelope
     * always carries a target_path, so the converged path is unaffected.
     *
     * @param  array<string,mixed>  $envelope
     */
    private function targetSymbol(array $envelope): string
    {
        $path = (string) ($envelope['target_path'] ?? ($envelope['payload']['target_relative_path'] ?? ''));

        return ltrim(trim($path), '/');
    }

    private function classOf(string $target): string
    {
        $base = basename($target);

        return str_ends_with($base, '.php') ? substr($base, 0, -4) : $base;
    }
}
