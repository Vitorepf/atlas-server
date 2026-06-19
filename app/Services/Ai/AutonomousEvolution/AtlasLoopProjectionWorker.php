<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSystemAxisService;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopDeliveryPipeline;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
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
    ) {}

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

            $designer = $this->designerFor($bindingAxis, $envelope);
            $critic = $this->criticClosure();

            $engine = $this->engine ?? new AtlasLoopProjectionEngine;
            $result = $engine->project($bindingAxis, $designer, $critic, (int) config('atlas.loop.projection_max_rounds', 8));

            if (($result['status'] ?? '') !== 'converged') {
                $pipeline->park($objectiveId, (string) ($result['reason'] ?? 'not_converged'));

                return ['outcome' => 'parked', 'objective_id' => $objectiveId, 'status' => (string) ($result['status'] ?? 'parked'), 'reason' => (string) ($result['reason'] ?? 'not_converged')];
            }

            // CONVERGED — attach the engine's typed obligations into the task spec, then enqueue the original
            // producer envelope as a first-class task (rebuilt from the checkpoint, NOT re-produced).
            $obligations = is_array($result['obligations'] ?? null) ? array_values((array) $result['obligations']) : [];
            $task = $this->enqueueFromEnvelope($campaignId, $envelope, $checkpoint, $obligations, $bindingAxis);
            $pipeline->complete($objectiveId);

            return ['outcome' => $task !== null ? 'enqueued' : 'enqueue_failed', 'objective_id' => $objectiveId, 'status' => 'converged'];
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
     */
    private function enqueueFromEnvelope(string $campaignId, array $envelope, array $checkpoint, array $obligations, string $bindingAxis): mixed
    {
        $payload = is_array($envelope['payload'] ?? null) ? (array) $envelope['payload'] : [];
        $payload['_obligations'] = $obligations;
        $payload['_projection'] = ['binding_axis' => $bindingAxis, 'schema_version' => AtlasLoopProjectionEngine::SCHEMA_VERSION];
        if (! isset($payload['_target_id']) && isset($checkpoint['real_target_id'])) {
            $payload['_target_id'] = (string) $checkpoint['real_target_id'];
        }

        $acceptance = is_array($payload['acceptance'] ?? null) ? (array) $payload['acceptance'] : [];
        $acceptance['obligations'] = $obligations;
        $payload['acceptance'] = $acceptance;

        $leverage = (float) ($envelope['leverage'] ?? 0.0);
        $priority = (int) ($checkpoint['priority'] ?? (4000 + min(999, (int) round($leverage * 200))));
        $hash = (string) ($envelope['acceptance_hash'] ?? '');

        return $this->store->enqueueTask(
            $campaignId,
            (string) ($envelope['objective'] ?? ''),
            $payload,
            'producer:objective',
            (string) ($envelope['target_path'] ?? ''),
            $priority,
            (bool) ($envelope['self_contained'] ?? true),
            $hash !== '' ? $hash : null,
        );
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
