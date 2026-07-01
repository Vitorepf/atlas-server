<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\AutonomousRuntime;

/**
 * Pure composer: snapshots of every Self-Construction ORGAN → ONE ordered cycle plan. NEVER calls
 * providers, shells, workers, git, or live runtimes.
 *
 * INPUT: facts array keyed by organ name:
 *   { control_plane:{...}, strategy_council:{...}, architecture_council:{...}, task_fabric:{...},
 *     maestro:{...}, worker_swarm:{...}, verification_court:{...}, merge_governor:{...},
 *     knowledge_sync:{...}, learning_transfer:{...} }
 *
 * OUTPUT:
 *   { schema, plan_status ∈ {ready,blocked}, ordered_stages:list<{organ, facts}>,
 *     missing_organs:list<string>, blockers:list<string> }
 *
 * INVARIANTS:
 *   - DETERMINISTIC: stages always emitted in ORGAN_ORDER.
 *   - Missing organ facts ⇒ recorded as 'missing_organ:<name>' blocker (never defaulted).
 *   - PURE: no I/O.
 */
final class AtlasAutonomousRuntimeOrganPipelineComposer
{
    public const SCHEMA = 'atlas.autonomousruntime.organ_pipeline.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    /** Canonical execution order — Control Plane decides first; Learning Transfer closes the loop. */
    public const ORGAN_ORDER = [
        'control_plane',
        'strategy_council',
        'architecture_council',
        'task_fabric',
        'maestro',
        'worker_swarm',
        'verification_court',
        'merge_governor',
        'knowledge_sync',
        'learning_transfer',
    ];

    // AC3: learning_transfer enriches the next cycle but isn't required for THIS cycle's minimal
    // circuit to execute — its absence is recorded, not treated as a hard blocker.
    public const OPTIONAL_ORGANS = ['learning_transfer'];

    // AC4: evidence capture points the composed circuit must surface — queue (maestro dispatch),
    // task_fabric, worker (worker_swarm), proof_system (verification_court), knowledge_sync.
    private const EVIDENCE_CAPTURE_POINTS = [
        'queue' => 'maestro',
        'task_fabric' => 'task_fabric',
        'worker' => 'worker_swarm',
        'proof_system' => 'verification_court',
        'knowledge_sync' => 'knowledge_sync',
    ];

    // AC3: named safety stop conditions the composed circuit must honor, and which organ is
    // responsible for detecting each one — 'active' in the output reflects whether that organ's
    // facts are actually present in THIS composed circuit.
    private const STOP_CONDITIONS = [
        'queue_starvation' => ['organ' => 'maestro', 'description' => 'queue drains to zero claimable tasks with no origination supply'],
        'poison_signal_detected' => ['organ' => 'verification_court', 'description' => 'a poisoned or adversarial task packet is detected mid-cycle'],
        'budget_exceeded' => ['organ' => 'control_plane', 'description' => 'token or time budget for the cycle is exhausted'],
        'proof_regression' => ['organ' => 'verification_court', 'description' => 'verification_court reports a regression against the held-out baseline'],
    ];

    /**
     * @param  array<string,array<string,mixed>>  $organFacts
     * @return array{schema:string, plan_status:string, ordered_stages:list<array{organ:string, facts:array<string,mixed>, ready:bool, readiness_reason:string}>, readiness_rows:list<array{organ:string, ready:bool, reason:string, required:bool}>, first_blocked_stage:?string, missing_organs:list<string>, missing_required_organs:list<string>, missing_optional_organs:list<string>, blockers:list<string>, evidence_capture_points:list<array{capture_point:string, organ:string, present:bool}>, stop_conditions:list<array{condition:string, organ:string, description:string, active:bool}>}
     */
    public function compose(array $organFacts): array
    {
        $stages = [];
        $readinessRows = [];
        $missing = [];
        $missingRequired = [];
        $missingOptional = [];
        $firstBlockedStage = null;
        $anyPriorBlocked = false;

        foreach (self::ORGAN_ORDER as $organ) {
            $facts = $organFacts[$organ] ?? null;
            $isPresent = is_array($facts);
            $isOptional = in_array($organ, self::OPTIONAL_ORGANS, true);

            if (! $isPresent) {
                $missing[] = $organ;
                if ($isOptional) {
                    $ready = false;
                    $reason = 'missing_facts_optional';
                    $missingOptional[] = $organ;
                } else {
                    $ready = false;
                    $reason = 'missing_facts';
                    $missingRequired[] = $organ;
                }
            } elseif ($anyPriorBlocked) {
                $ready = false;
                $reason = 'upstream_blocked';
            } elseif (($facts['healthy'] ?? true) === false) {
                $ready = false;
                $reason = 'unhealthy';
            } else {
                $ready = true;
                $reason = 'ready';
            }

            // A missing OPTIONAL organ never blocks the plan or cascades downstream.
            if (! $ready && ! ($isOptional && ! $isPresent)) {
                $anyPriorBlocked = true;
                if ($firstBlockedStage === null) {
                    $firstBlockedStage = $organ;
                }
            }

            $readinessRows[] = ['organ' => $organ, 'ready' => $ready, 'reason' => $reason, 'required' => ! $isOptional];

            if ($isPresent) {
                $stages[] = ['organ' => $organ, 'facts' => $facts, 'ready' => $ready, 'readiness_reason' => $reason];
            }
        }

        $blockers = array_map(static fn (string $o): string => 'missing_organ:'.$o, $missingRequired);
        sort($blockers, SORT_STRING);

        $evidenceCapturePoints = [];
        foreach (self::EVIDENCE_CAPTURE_POINTS as $capturePoint => $organ) {
            $evidenceCapturePoints[] = [
                'capture_point' => $capturePoint,
                'organ' => $organ,
                'present' => is_array($organFacts[$organ] ?? null),
            ];
        }

        $stopConditions = [];
        foreach (self::STOP_CONDITIONS as $condition => $meta) {
            $stopConditions[] = [
                'condition' => $condition,
                'organ' => $meta['organ'],
                'description' => $meta['description'],
                'active' => is_array($organFacts[$meta['organ']] ?? null),
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'plan_status' => $firstBlockedStage === null ? self::STATUS_READY : self::STATUS_BLOCKED,
            'ordered_stages' => $stages,
            'readiness_rows' => $readinessRows,
            'first_blocked_stage' => $firstBlockedStage,
            'missing_organs' => $missing,
            'missing_required_organs' => $missingRequired,
            'missing_optional_organs' => $missingOptional,
            'blockers' => $blockers,
            'evidence_capture_points' => $evidenceCapturePoints,
            'stop_conditions' => $stopConditions,
        ];
    }
}
