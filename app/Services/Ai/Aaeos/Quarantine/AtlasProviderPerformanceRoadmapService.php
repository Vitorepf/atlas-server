<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use InvalidArgumentException;

/**
 * Pure, deterministic decider for the Provider Performance Evolution Roadmap.
 *
 * This is NOT a provider client and it never spends tokens. It encodes the
 * concrete decision rules the doc states over plain typed arrays — no
 * database, no models, no side effects — so the contract can be pinned and
 * reused independently of the live routing pipeline.
 *
 * Rules implemented (mapped to the doc sections):
 *
 *   - "Modes": the three named modes (`auto_best_allowed`,
 *     `auto_best_available`, `manual_override`) with their distinct semantics.
 *     In auto modes Atlas Decide owns the model/provider choice. A manual
 *     override is an AUDITED override (never the default path) and always
 *     forces a Decision Receipt recording the override and its consequences.
 *
 *   - "AP-99 Role": the fixed 7-signal empirical evidence schema, in order:
 *     task domain/flow, provider/model, latency+cost, gate outcomes, repair
 *     rate, human acceptance, final quality score. AP-99 lets Atlas route by
 *     evidence instead of brand preference, so an evidence record missing any
 *     required signal is NOT routing-ready.
 *
 *   - "Provider Launch Intake": a launched provider product is classified into
 *     exactly one of {provider_driver, skill, evaluation, tool_recipe,
 *     irrelevant}. Useful patterns are promoted into Atlas contracts and
 *     direct provider usage is always kept behind Atlas.
 *
 *   - "Dynamic Compute Market": Atlas Decide acts as a compute broker. Low-risk
 *     work routes to cheap local / fast models; high-risk architecture,
 *     finance, strategy and final-review work routes to premium models.
 *
 *   - "Anti-Fragility Test": a provider release is good for Atlas only if Atlas
 *     can absorb it WITHOUT changing user habit. If the user must leave Atlas
 *     to use the release, the surface/driver layer is incomplete.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/evolution/provider-performance-roadmap.md
 */
final class AtlasProviderPerformanceRoadmapService
{
    public const SCHEMA_VERSION = 'atlas.evolution.provider_performance_roadmap.v1';

    public const MODE_AUTO_BEST_ALLOWED = 'auto_best_allowed';
    public const MODE_AUTO_BEST_AVAILABLE = 'auto_best_available';
    public const MODE_MANUAL_OVERRIDE = 'manual_override';

    /**
     * The three documented selection modes. Order is the doc's table order.
     *
     * @var list<string>
     */
    public const MODES = [
        self::MODE_AUTO_BEST_ALLOWED,
        self::MODE_AUTO_BEST_AVAILABLE,
        self::MODE_MANUAL_OVERRIDE,
    ];

    /**
     * AP-99 empirical evidence signals, in the doc's enumerated order (1..7).
     * Routing-by-evidence requires every one of these to be present.
     *
     * @var list<string>
     */
    public const AP99_SIGNALS = [
        'task_domain_flow',
        'provider_model',
        'latency_and_cost',
        'gate_outcomes',
        'repair_rate',
        'human_acceptance',
        'final_quality_score',
    ];

    /**
     * The exhaustive Provider Launch Intake classifications. The doc's
     * head-to-head comparison step maps to the neutral `evaluation`
     * classification here.
     *
     * @var list<string>
     */
    public const INTAKE_CLASSES = [
        'provider_driver',
        'skill',
        'evaluation',
        'tool_recipe',
        'irrelevant',
    ];

    public const TIER_LOCAL_FAST = 'local_fast';
    public const TIER_PREMIUM = 'premium';

    /**
     * High-risk work classes the doc routes to premium models.
     *
     * @var list<string>
     */
    public const HIGH_RISK_WORK = [
        'architecture',
        'finance',
        'strategy',
        'final_review',
    ];

    /**
     * Resolve a selection mode into its authority/receipt contract.
     *
     * Auto modes are owned by Atlas Decide; `auto_best_available` additionally
     * requires the user to have explicitly permitted higher cost/risk. A manual
     * override is an audited override (not the default path) and always forces a
     * Decision Receipt recording the override and its consequences.
     *
     * @return array{
     *   schema_version:string,
     *   mode:string,
     *   valid:bool,
     *   owner:string,
     *   is_default_path:bool,
     *   requires_explicit_user_permission:bool,
     *   requires_decision_receipt:bool,
     *   records_override_consequences:bool,
     *   reason:string
     * }
     */
    public function resolveMode(string $mode): array
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Unknown selection mode: {$mode}");
        }

        $isManual = $mode === self::MODE_MANUAL_OVERRIDE;
        $owner = $isManual ? 'user' : 'atlas_decide';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => $mode,
            'valid' => true,
            'owner' => $owner,
            // Manual override is explicitly "not the default path".
            'is_default_path' => ! $isManual,
            'requires_explicit_user_permission' => $mode === self::MODE_AUTO_BEST_AVAILABLE,
            'requires_decision_receipt' => $isManual,
            'records_override_consequences' => $isManual,
            'reason' => $isManual
                ? 'Manual override is an audited override; receipt records override and consequences.'
                : 'Atlas Decide owns model/provider choice in auto modes.',
        ];
    }

    /**
     * Validate an AP-99 evidence record against the 7-signal schema.
     *
     * A record is routing-ready only when every required signal is present and
     * non-empty. Missing signals are returned in their canonical order so the
     * caller can repair them; an incomplete record must not drive routing.
     *
     * @param  array<string,mixed>  $record
     * @return array{
     *   schema_version:string,
     *   complete:bool,
     *   routing_ready:bool,
     *   present:list<string>,
     *   missing:list<string>,
     *   reason:string
     * }
     */
    public function validateAp99Evidence(array $record): array
    {
        $present = [];
        $missing = [];

        foreach (self::AP99_SIGNALS as $signal) {
            if (array_key_exists($signal, $record) && $this->isFilled($record[$signal])) {
                $present[] = $signal;
            } else {
                $missing[] = $signal;
            }
        }

        $complete = $missing === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'complete' => $complete,
            // Doc: AP-99 lets Atlas route by evidence instead of brand preference.
            'routing_ready' => $complete,
            'present' => $present,
            'missing' => $missing,
            'reason' => $complete
                ? 'All 7 AP-99 signals present; record can drive evidence-based routing.'
                : 'Incomplete AP-99 evidence is not routing-ready: route by brand preference is forbidden.',
        ];
    }

    /**
     * Provider Launch Intake: classify a launched provider product and decide
     * whether it gets promoted into Atlas contracts. Direct provider usage is
     * always kept behind Atlas, regardless of classification.
     *
     * @return array{
     *   schema_version:string,
     *   classification:string,
     *   valid:bool,
     *   actionable:bool,
     *   promote_to_atlas_contract:bool,
     *   keep_behind_atlas:bool,
     *   reason:string
     * }
     */
    public function classifyProviderLaunch(string $classification): array
    {
        if (! in_array($classification, self::INTAKE_CLASSES, true)) {
            throw new InvalidArgumentException("Unknown intake classification: {$classification}");
        }

        $actionable = $classification !== 'irrelevant';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'classification' => $classification,
            'valid' => true,
            'actionable' => $actionable,
            // "promote useful patterns into Atlas contracts" — only useful ones.
            'promote_to_atlas_contract' => $actionable,
            // "keep direct provider usage behind Atlas" — ALWAYS, even if irrelevant.
            'keep_behind_atlas' => true,
            'reason' => $actionable
                ? "Useful capability ({$classification}): promote pattern into Atlas contracts; keep usage behind Atlas."
                : 'Irrelevant launch: no promotion, but direct provider usage still stays behind Atlas.',
        ];
    }

    /**
     * Dynamic Compute Market routing: Atlas Decide as compute broker.
     *
     * High-risk architecture / finance / strategy / final-review work routes to
     * premium models; everything else (low-risk work) routes to cheap local or
     * fast models.
     *
     * @return array{
     *   schema_version:string,
     *   work_class:string,
     *   high_risk:bool,
     *   tier:string,
     *   broker:string,
     *   reason:string
     * }
     */
    public function routeCompute(string $workClass): array
    {
        $normalized = strtolower(trim($workClass));
        if ($normalized === '') {
            throw new InvalidArgumentException('Work class must not be empty.');
        }

        $highRisk = in_array($normalized, self::HIGH_RISK_WORK, true);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'work_class' => $normalized,
            'high_risk' => $highRisk,
            'tier' => $highRisk ? self::TIER_PREMIUM : self::TIER_LOCAL_FAST,
            'broker' => 'atlas_decide',
            'reason' => $highRisk
                ? 'High-risk work (architecture/finance/strategy/final review) routes to premium models.'
                : 'Low-risk work routes to cheap local or fast models.',
        ];
    }

    /**
     * Anti-Fragility test for a provider release.
     *
     * A release is good for Atlas if Atlas can absorb it WITHOUT changing user
     * habit. If the user must leave Atlas to use the release, the surface/driver
     * layer is incomplete and the gap layer is reported.
     *
     * @return array{
     *   schema_version:string,
     *   absorbed_without_changing_user_habit:bool,
     *   good_for_atlas:bool,
     *   layer_gap:?string,
     *   reason:string
     * }
     */
    public function antiFragilityTest(bool $absorbedWithoutChangingUserHabit, bool $userMustLeaveAtlas): array
    {
        // If the user must leave Atlas, by definition habit changed.
        $absorbed = $absorbedWithoutChangingUserHabit && ! $userMustLeaveAtlas;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'absorbed_without_changing_user_habit' => $absorbed,
            'good_for_atlas' => $absorbed,
            'layer_gap' => $absorbed ? null : 'surface_or_driver_layer_incomplete',
            'reason' => $absorbed
                ? 'Atlas absorbed the release without changing user habit: anti-fragile.'
                : 'User must leave Atlas to use the release: surface/driver layer is incomplete.',
        ];
    }

    private function isFilled(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }
}
