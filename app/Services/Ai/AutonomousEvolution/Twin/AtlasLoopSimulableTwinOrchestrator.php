<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Twin;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\Rollback\AtlasAaelExecutionPreImageSnapshotter;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Rollback\AtlasAaelExecutionRollbackExecutor;
use App\Services\Ai\AutonomousEvolution\BehaviorDelta\AtlasLoopBehaviorDeltaComputer;
use Throwable;

/**
 * Runs a candidate edit inside a caller-provided mirror handle and always rolls the mirror back.
 *
 * The orchestrator itself never writes source paths. It delegates pre-image capture and rollback to the AAEL
 * public APIs, delegates behavior comparison to AtlasLoopBehaviorDeltaComputer, and passes only the mirror
 * handle to the candidate callback.
 */
final class AtlasLoopSimulableTwinOrchestrator
{
    public const SCHEMA_VERSION = 'atlas.loop.simulable_twin_orchestrator.v1';

    public function __construct(
        private readonly mixed $snapshotter = null,
        private readonly mixed $rollbackExecutor = null,
        private readonly ?AtlasLoopBehaviorDeltaComputer $deltaComputer = null,
    ) {
    }

    /**
     * Rank a final-brain candidate spec for queue safety, collision risk, lane impact and proof cost
     * BEFORE seeding — higher rank_score = better to seed first (max 100, penalty-based).
     *
     * Penalties: collision_risk high (>3 files) -30, medium (2-3) -10; risk_level high -20;
     * proxy objective -40; proof_cost >3 evidence items -10.
     *
     * @param  array<string,mixed>  $spec  task-packet shaped payload
     * @return array{rank_score:int, collision_risk:string, queue_safe:bool, lane_impact:string, proof_cost:int, reasons:list<string>}
     */
    public function rankCandidate(array $spec): array
    {
        $files = array_values(array_filter(
            array_map('strval', (array) ($spec['allowed_files'] ?? [])),
            static fn (string $f): bool => $f !== '',
        ));
        $fileCount = count($files);
        $riskLevel = (string) ($spec['risk_level'] ?? 'low');
        $objective = strtolower((string) ($spec['objective'] ?? ''));
        $evidence  = array_values(array_filter(
            array_map('strval', (array) ($spec['required_evidence'] ?? [])),
            static fn (string $e): bool => $e !== '',
        ));

        $reasons = [];
        $penalty = 0;

        $collisionRisk = match (true) {
            $fileCount <= 1 => 'low',
            $fileCount <= 3 => 'medium',
            default         => 'high',
        };
        if ($collisionRisk === 'high') {
            $penalty  += 30;
            $reasons[] = 'collision_risk:high:'.$fileCount.'_files';
        } elseif ($collisionRisk === 'medium') {
            $penalty  += 10;
            $reasons[] = 'collision_risk:medium';
        }

        $queueSafe = $riskLevel !== 'high';
        if (! $queueSafe) {
            $penalty  += 20;
            $reasons[] = 'queue_unsafe:risk_level_high';
        }

        // Proxy = zero behaviour delta; seeds that waste loop cycles.
        foreach (['whitespace', 'trailing space', 'indent', 'cleanup', 'remove unused', 'dead code', 'rename variable', 'typo', 'formatting'] as $term) {
            if (str_contains($objective, $term)) {
                $penalty  += 40;
                $reasons[] = 'proxy:objective_is_cleanup_or_rename';
                break;
            }
        }

        $laneImpact = match (true) {
            $fileCount <= 1 => 'easy',
            $fileCount <= 5 => 'hard',
            default         => 'hardest',
        };

        $proofCost = count($evidence);
        if ($proofCost > 3) {
            $penalty  += 10;
            $reasons[] = 'proof_cost:high:'.$proofCost.'_items';
        }

        return [
            'rank_score'     => max(0, 100 - $penalty),
            'collision_risk' => $collisionRisk,
            'queue_safe'     => $queueSafe,
            'lane_impact'    => $laneImpact,
            'proof_cost'     => $proofCost,
            'reasons'        => $reasons,
        ];
    }

    /**
     * @param  array<string,mixed>  $candidateEdit
     * @param  callable(array<string,mixed>, array<string,mixed>):array<string,mixed>|null  $applyInMirror
     * @return array{schema:string, before_snapshot:array<string,mixed>, after_snapshot:array<string,mixed>, behavior_delta:array<string,mixed>, reverted:bool}
     */
    public function simulate(array $candidateEdit, callable $applyInMirror): array
    {
        $executionId = $this->executionId($candidateEdit);
        $mirror = $this->mirrorHandle($candidateEdit, $executionId);
        $plan = $this->mirrorExecutionPlan($candidateEdit, $mirror);

        $before = $this->snapshot($executionId, $plan);
        $after = $before;
        $postExecutionShas = [];

        try {
            $applied = $applyInMirror($mirror, $candidateEdit);
            if (is_array($applied)) {
                $after = $this->afterSnapshot($applied, $before);
                $postExecutionShas = $this->stringMap($applied['post_execution_shas'] ?? $applied['recorded_post_execution_shas'] ?? []);
            }
        } catch (Throwable) {
            $after = $before;
        } finally {
            $this->rollback($executionId, $postExecutionShas);
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'behavior_delta' => ($this->deltaComputer ?? new AtlasLoopBehaviorDeltaComputer)->compute(
                $this->behaviorSnapshot($before),
                $this->behaviorSnapshot($after),
            ),
            'reverted' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function snapshot(string $executionId, array $plan): array
    {
        $snapshotter = $this->snapshotter ?? new AtlasAaelExecutionPreImageSnapshotter;

        return $snapshotter->snapshot($executionId, $plan);
    }

    /** @param array<string,string> $postExecutionShas */
    private function rollback(string $executionId, array $postExecutionShas): void
    {
        $rollbackExecutor = $this->rollbackExecutor ?? new AtlasAaelExecutionRollbackExecutor;
        $rollbackExecutor->execute($executionId, $postExecutionShas);
    }

    /** @param array<string,mixed> $candidateEdit */
    private function executionId(array $candidateEdit): string
    {
        $explicit = trim((string) ($candidateEdit['execution_id'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return 'simulable-twin-'.substr(hash('sha256', json_encode($candidateEdit, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * @param  array<string,mixed>  $candidateEdit
     * @return array<string,mixed>
     */
    private function mirrorHandle(array $candidateEdit, string $executionId): array
    {
        foreach (['mirror_handle', 'mirror'] as $key) {
            if (is_array($candidateEdit[$key] ?? null)) {
                return array_replace(['execution_id' => $executionId], (array) $candidateEdit[$key]);
            }
        }

        return ['execution_id' => $executionId, 'execution_plan' => $this->mirrorExecutionPlan($candidateEdit, [])];
    }

    /**
     * @param  array<string,mixed>  $candidateEdit
     * @param  array<string,mixed>  $mirror
     * @return array<string,mixed>
     */
    private function mirrorExecutionPlan(array $candidateEdit, array $mirror): array
    {
        foreach ([$mirror['execution_plan'] ?? null, $candidateEdit['mirror_execution_plan'] ?? null] as $plan) {
            if (is_array($plan)) {
                return $plan;
            }
        }

        return ['allowed_files' => []];
    }

    /**
     * @param  array<string,mixed>  $applied
     * @param  array<string,mixed>  $fallback
     * @return array<string,mixed>
     */
    private function afterSnapshot(array $applied, array $fallback): array
    {
        foreach (['after_snapshot', 'behavior_snapshot', 'snapshot'] as $key) {
            if (is_array($applied[$key] ?? null)) {
                return (array) $applied[$key];
            }
        }

        return $applied !== [] ? $applied : $fallback;
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function behaviorSnapshot(array $snapshot): array
    {
        foreach (['behavior_snapshot', 'snapshot'] as $key) {
            if (is_array($snapshot[$key] ?? null)) {
                return (array) $snapshot[$key];
            }
        }

        return $snapshot;
    }

    /** @return array<string,string> */
    private function stringMap(mixed $value): array
    {
        $map = [];
        foreach ((array) $value as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $map[$key] = $item;
            }
        }

        return $map;
    }
}
