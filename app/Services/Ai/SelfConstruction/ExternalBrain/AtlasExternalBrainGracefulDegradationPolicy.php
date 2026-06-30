<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure degradation policy. Determines the operating mode and safety constraints
 * to apply when frontier models are unavailable.
 *
 * Modes (first match wins):
 *   full      — frontier_model tier is available
 *   degraded  — scaffolded_small_model available, frontier is not
 *   minimal   — only small_model available (no frontier, no scaffolded)
 *
 * AC2: ambition_cap and batch size are reduced as tiers shrink.
 *
 * AC3 (safety for degraded / minimal):
 *   degraded → require grep_proof, duplicate_check, critique_quorum ≥ 2,
 *              runnable_acceptance for every task
 *   minimal  → all of degraded requirements + critique_quorum ≥ 3,
 *              mandatory canonical_doc_read
 *
 * AC4: output always includes mode, ambition_cap, required_scaffold_strictness,
 *      forbidden_task_classes, and fallback_batch_constraints.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainGracefulDegradationPolicy
{
    public const SCHEMA = 'atlas.external_brain.graceful_degradation_policy.v1';

    public const MODE_FULL      = 'full';
    public const MODE_DEGRADED  = 'degraded';
    public const MODE_MINIMAL   = 'minimal';
    public const MODE_SAFE_HOLD = 'safe_hold';

    public const STRICTNESS_STANDARD = 'standard';
    public const STRICTNESS_STRICT   = 'strict';
    public const STRICTNESS_MAXIMUM  = 'maximum';

    public const TIER_FRONTIER   = 'frontier_model';
    public const TIER_SCAFFOLDED = 'scaffolded_small_model';
    public const TIER_SMALL      = 'small_model';

    // Task classes that require frontier reasoning
    private const FRONTIER_ONLY_CLASSES = ['architecture_tradeoff', 'novel_research'];

    // Task classes that require at minimum scaffolded small model
    private const SCAFFOLDED_REQUIRED_CLASSES = ['multi_domain_refactor', 'novel_capability_origination'];

    // Ambition caps per mode
    private const AMBITION_CAPS = [
        self::MODE_FULL      => 1.0,
        self::MODE_DEGRADED  => 0.60,
        self::MODE_MINIMAL   => 0.30,
        self::MODE_SAFE_HOLD => 0.0,
    ];

    // Max batch sizes per mode
    private const BATCH_SIZE_CAPS = [
        self::MODE_FULL      => 10,
        self::MODE_DEGRADED  => 5,
        self::MODE_MINIMAL   => 2,
        self::MODE_SAFE_HOLD => 0,
    ];

    /** @var array<string, list<string>> mode => normalized required_extra_checks identifiers. */
    private const REQUIRED_EXTRA_CHECKS = [
        self::MODE_FULL      => [],
        self::MODE_DEGRADED  => ['grep_proof', 'duplicate_check', 'runnable_acceptance_criterion', 'critique_quorum_2'],
        self::MODE_MINIMAL   => ['grep_proof', 'duplicate_check', 'runnable_acceptance_criterion', 'critique_quorum_3', 'canonical_doc_read'],
        self::MODE_SAFE_HOLD => ['operator_approval_required'],
    ];

    private const MODE_ORDER = [self::MODE_FULL, self::MODE_DEGRADED, self::MODE_MINIMAL, self::MODE_SAFE_HOLD];

    /**
     * @param  array{
     *   available_tiers?: list<string>,
     *   frontier_available?: bool,
     *   current_batch_size?: int,
     *   low_confidence?: bool,
     *   high_ambiguity?: bool,
     *   low_budget?: bool,
     * }  $input
     * @return array{schema:string, mode:string, ambition_cap:float, required_scaffold_strictness:string, forbidden_task_classes:list<string>, fallback_batch_constraints:list<string>}
     */
    public function apply(array $input): array
    {
        $availableTiers      = (array) ($input['available_tiers']   ?? []);
        $frontierExplicit    = $input['frontier_available']          ?? null;
        $currentBatchSize    = max(1, (int) ($input['current_batch_size'] ?? 5));
        $lowConfidence       = (bool) ($input['low_confidence'] ?? false);
        $highAmbiguity       = (bool) ($input['high_ambiguity'] ?? false);
        $lowBudget           = (bool) ($input['low_budget'] ?? false);

        $hasFrontier   = $frontierExplicit !== null
            ? (bool) $frontierExplicit
            : in_array(self::TIER_FRONTIER, $availableTiers, true);
        $hasScaffolded = in_array(self::TIER_SCAFFOLDED, $availableTiers, true);
        $hasSmall      = in_array(self::TIER_SMALL, $availableTiers, true);

        $mode = $this->resolveMode($hasFrontier, $hasScaffolded, $hasSmall);

        // AC2: low confidence, high ambiguity, or low budget never pretend model quality is
        // unchanged — they step the mode DOWN by one rung (never up), same as a tier outage would.
        if ($lowConfidence || $highAmbiguity || $lowBudget) {
            $mode = $this->stepDown($mode);
        }

        return [
            'schema'                      => self::SCHEMA,
            'mode'                        => $mode,
            'fallback_mode'               => $mode,
            'ambition_cap'                => self::AMBITION_CAPS[$mode],
            'strictness'                  => $this->strictness($mode),
            'required_scaffold_strictness' => $this->strictness($mode), // backward-compat alias
            'forbidden_task_classes'      => $this->forbiddenClasses($mode),
            'blocked_capabilities'        => $this->forbiddenClasses($mode),
            'required_extra_checks'       => self::REQUIRED_EXTRA_CHECKS[$mode],
            'fallback_batch_constraints'  => $this->batchConstraints($mode, $currentBatchSize),
            'recovery_conditions'         => $this->recoveryConditions($mode),
        ];
    }

    private function stepDown(string $mode): string
    {
        $index = array_search($mode, self::MODE_ORDER, true);
        if ($index === false || $index === count(self::MODE_ORDER) - 1) {
            return $mode;
        }

        return self::MODE_ORDER[$index + 1];
    }

    private function resolveMode(bool $hasFrontier, bool $hasScaffolded, bool $hasSmall): string
    {
        if ($hasFrontier) {
            return self::MODE_FULL;
        }
        if ($hasScaffolded) {
            return self::MODE_DEGRADED;
        }
        if ($hasSmall) {
            return self::MODE_MINIMAL;
        }

        return self::MODE_SAFE_HOLD;
    }

    private function strictness(string $mode): string
    {
        return match ($mode) {
            self::MODE_FULL      => self::STRICTNESS_STANDARD,
            self::MODE_DEGRADED  => self::STRICTNESS_STRICT,
            default              => self::STRICTNESS_MAXIMUM,
        };
    }

    /** @return list<string> */
    private function forbiddenClasses(string $mode): array
    {
        $all = array_values(array_unique([...self::FRONTIER_ONLY_CLASSES, ...self::SCAFFOLDED_REQUIRED_CLASSES]));

        return match ($mode) {
            self::MODE_FULL      => [],
            self::MODE_DEGRADED  => self::FRONTIER_ONLY_CLASSES,
            self::MODE_MINIMAL   => $all,
            self::MODE_SAFE_HOLD => $all,
        };
    }

    /** @return list<string> */
    private function batchConstraints(string $mode, int $currentBatchSize): array
    {
        $cap = self::BATCH_SIZE_CAPS[$mode];

        if ($mode === self::MODE_SAFE_HOLD) {
            return ['max_batch_size: 0', 'all origination halted until safe tier is restored'];
        }

        $constraints = [
            sprintf('max_batch_size: %d', min($currentBatchSize, $cap)),
        ];

        if ($mode === self::MODE_DEGRADED || $mode === self::MODE_MINIMAL) {
            $constraints[] = 'require grep_proof for every task in batch';
            $constraints[] = 'require duplicate_check before enqueue';
            $constraints[] = 'require runnable_acceptance_criterion for every task';
            $constraints[] = 'critique_quorum: ≥ 2 critique passes required per task';
        }

        if ($mode === self::MODE_MINIMAL) {
            $constraints[] = 'critique_quorum: upgraded to ≥ 3 critique passes per task';
            $constraints[] = 'require canonical_doc_read before origination';
        }

        return $constraints;
    }

    /** @return list<string> */
    private function recoveryConditions(string $mode): array
    {
        return match ($mode) {
            self::MODE_FULL      => [],
            self::MODE_DEGRADED  => ['restore frontier_model tier availability', 'run preflight health check after restoration'],
            self::MODE_MINIMAL   => ['restore scaffolded_small_model or frontier_model tier', 'validate tier readiness before escalating mode'],
            self::MODE_SAFE_HOLD => ['restore any safe tier (small_model, scaffolded_small_model, or frontier_model)', 'run preflight health check', 'require operator approval before resuming origination'],
        };
    }
}
