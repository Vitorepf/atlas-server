<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Closed-loop safety gate runner. Orchestrates all four closed-loop safety
 * sub-gates into a single safety verdict:
 *
 *   - AtlasExternalBrainClosedLoopReadinessGate::check()        — dossier integrity
 *   - AtlasExternalBrainAmplifierCanaryKillSwitch::evaluate()   — live canary health
 *   - AtlasExternalBrainBehaviorLockPlanner::plan()             — behavior lock safety
 *   - AtlasExternalBrainAdversarialSpecReviewBoard::review()    — spec adversarial review
 *
 * Each dependency is nullable — when a gate is not provided its check is
 * skipped (treated as passing) so the runner degrades gracefully.
 *
 * INPUT shape (passed to ::run()):
 *   {
 *     readiness_dossier?: array{dossier?: array<string, array<string,mixed>>},
 *     canary_metrics?:    array{...},
 *     behavior_targets?:  list<array<string,mixed>>,
 *     task_spec?:         array{...},
 *   }
 *
 * OUTPUT shape:
 *   {
 *     schema:           string,
 *     safe:             bool,
 *     readiness:        array,
 *     canary:           array,
 *     behavior_locks:   array,
 *     review:           array,
 *     reasons:          list<string>,
 *   }
 *
 * Pure: no I/O, no provider calls — delegates to pure deterministic gates.
 */
final class AtlasExternalBrainClosedLoopSafetyGateRunner
{
    public const SCHEMA = 'atlas.external_brain.closed_loop_safety_gate_runner.v1';

    public const CANARY_ACTION_CONTINUE = 'continue';

    public function __construct(
        private readonly ?AtlasExternalBrainClosedLoopReadinessGate $readinessGate = null,
        private readonly ?AtlasExternalBrainAmplifierCanaryKillSwitch $canaryKillSwitch = null,
        private readonly ?AtlasExternalBrainBehaviorLockPlanner $behaviorLockPlanner = null,
        private readonly ?AtlasExternalBrainAdversarialSpecReviewBoard $adversarialSpecReviewBoard = null,
    ) {}

    /**
     * Run all four safety gates and produce a single verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array{schema:string, safe:bool, readiness:array, canary:array, behavior_locks:array, review:array, reasons:list<string>}
     */
    public function run(array $input): array
    {
        $reasons = [];

        // ── 1. Readiness gate (dossier integrity) ────────────────────────────
        $readinessDossier = is_array($input['readiness_dossier'] ?? null)
            ? $input['readiness_dossier']
            : [];
        $readiness = $this->readinessGate?->check($readinessDossier) ?? [
            'ready' => true,
            'missing_links' => [],
        ];
        $readinessReady = (bool) ($readiness['ready'] ?? false);
        if (! $readinessReady) {
            $missing = (array) ($readiness['missing_links'] ?? []);
            $reasons[] = $missing !== []
                ? 'closed_loop_readiness_blocked:'.implode(',', $missing)
                : 'closed_loop_readiness_blocked';
        }

        // ── 2. Canary kill switch ────────────────────────────────────────────
        $canaryMetrics = is_array($input['canary_metrics'] ?? null)
            ? $input['canary_metrics']
            : [];
        $canary = $this->canaryKillSwitch?->evaluate($canaryMetrics) ?? [
            'action' => self::CANARY_ACTION_CONTINUE,
            'kill_switch_active' => false,
            'kill_reason' => null,
        ];
        $canaryAction = (string) ($canary['action'] ?? self::CANARY_ACTION_CONTINUE);
        $canarySafe = $canaryAction === self::CANARY_ACTION_CONTINUE;
        if (! $canarySafe) {
            $killReason = (string) ($canary['kill_reason'] ?? $canaryAction);
            $reasons[] = "canary_kill_switch_active:{$killReason}";
        }

        // ── 3. Behavior lock planner ─────────────────────────────────────────
        $behaviorTargets = is_array($input['behavior_targets'] ?? null)
            ? $input['behavior_targets']
            : [];
        $behaviorLocks = $this->behaviorLockPlanner?->plan($behaviorTargets) ?? [
            'lock_first_count' => 0,
            'decisions' => [],
        ];
        $lockFirstCount = (int) ($behaviorLocks['lock_first_count'] ?? 0);
        $behaviorSafe = $lockFirstCount === 0;
        if (! $behaviorSafe) {
            $reasons[] = "behavior_locks_pending:{$lockFirstCount}_targets_downgraded_to_lock_first";
        }

        // ── 4. Adversarial spec review board ─────────────────────────────────
        $taskSpec = is_array($input['task_spec'] ?? null)
            ? $input['task_spec']
            : [];
        $review = $this->adversarialSpecReviewBoard?->review($taskSpec) ?? [
            'approved' => true,
            'lens_results' => [],
        ];
        $reviewApproved = (bool) ($review['approved'] ?? true);
        if (! $reviewApproved) {
            $hardBlockers = (array) ($review['hard_blockers'] ?? []);
            $reasons[] = $hardBlockers !== []
                ? 'adversarial_review_rejected:hard_blockers='.implode(',', $hardBlockers)
                : 'adversarial_review_rejected';
        }

        // ── Aggregate verdict ────────────────────────────────────────────────
        $safe = $readinessReady && $canarySafe && $behaviorSafe && $reviewApproved;

        return [
            'schema' => self::SCHEMA,
            'safe' => $safe,
            'readiness' => $readiness,
            'canary' => $canary,
            'behavior_locks' => $behaviorLocks,
            'review' => $review,
            'reasons' => $reasons,
        ];
    }
}
