<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use InvalidArgumentException;

/**
 * Pure, deterministic decider for the Atlas Master Architecture Competitive
 * Strategy doc.
 *
 * This service NEVER reads runtime state and touches no database. It encodes
 * the concrete decision contracts the doc states over plain typed arrays so the
 * strategic posture can be pinned, tested and reused independently of any live
 * provider-intelligence pipeline.
 *
 * The doc states four enforceable contracts:
 *
 *   1. POSITION. Atlas does not compete model-vs-model; it competes as the
 *      governed upper layer that decides how provider features enter the
 *      operator's operating system. A posture of "match the model head to head"
 *      is INVALID; only the "governed upper layer" posture is admissible.
 *
 *   2. ABSORPTION LOOP. When a provider releases a capability (finance agent,
 *      design mode, voice mode, connector, skill pack) Atlas runs five ordered
 *      steps:
 *        1. classify capability
 *        2. compare via the comparison suite WHEN USEFUL (conditional step)
 *        3. map to provider driver / skill / domain recipe / evaluation / AP
 *        4. update Policy/Profile and Model Selection IF evidence supports it
 *        5. preserve the single Atlas channel
 *      Step 2 is skipped when comparison is not useful; step 4 is gated on
 *      evidence; the single-channel invariant (step 5) is always preserved.
 *
 *   3. MOAT. Atlas owns eight structural advantages providers do not own. The
 *      enumeration is fixed and ordered.
 *
 *   4. STOP-THE-LINE. If a provider launch makes users leave Atlas to get
 *      better outcomes, Atlas MUST either create an AP to absorb the capability
 *      OR explicitly reject it WITH evidence. There is no third option, and a
 *      rejection without evidence is INVALID. A launch that does not cause
 *      users to leave does not trip the line.
 *
 *   5. DIRECT-USAGE SIGNAL (frontmatter decision). Direct provider usage is a
 *      signal that Atlas surface / driver / skill coverage is incomplete.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/master-architecture/competitive-strategy.md
 */
final class AtlasMasterCompetitiveStrategyService
{
    public const SCHEMA_VERSION = 'atlas.master_competitive_strategy.decision.v1';

    /**
     * The only admissible competitive posture. Any model-vs-model posture is
     * rejected by {@see classifyPosition()}.
     */
    public const POSTURE_GOVERNED_UPPER_LAYER = 'governed_upper_layer';
    public const POSTURE_MODEL_VS_MODEL = 'model_vs_model';

    /**
     * The five ordered Absorption Loop steps. `conditional` marks step 2
     * (compare only "when useful"); `evidence_gated` marks step 4 (update only
     * "if evidence supports it"); `invariant` marks step 5 (always preserved).
     *
     * @var list<array{ordinal:int,id:string,label:string,conditional:bool,evidence_gated:bool,invariant:bool}>
     */
    public const ABSORPTION_STEPS = [
        ['ordinal' => 1, 'id' => 'classify_capability', 'label' => 'Classify capability', 'conditional' => false, 'evidence_gated' => false, 'invariant' => false],
        ['ordinal' => 2, 'id' => 'compare_when_useful', 'label' => 'Compare via comparison suite when useful', 'conditional' => true, 'evidence_gated' => false, 'invariant' => false],
        ['ordinal' => 3, 'id' => 'map_to_surface', 'label' => 'Map to provider driver, skill, domain recipe, evaluation or AP', 'conditional' => false, 'evidence_gated' => false, 'invariant' => false],
        ['ordinal' => 4, 'id' => 'update_policy_profile', 'label' => 'Update Policy/Profile and Model Selection if evidence supports it', 'conditional' => false, 'evidence_gated' => true, 'invariant' => false],
        ['ordinal' => 5, 'id' => 'preserve_single_channel', 'label' => 'Preserve single Atlas channel', 'conditional' => false, 'evidence_gated' => false, 'invariant' => true],
    ];

    /**
     * The eight structural moat advantages, in the doc's order. This set is the
     * full enumeration the doc claims providers do not own.
     *
     * @var list<string>
     */
    public const MOAT_ADVANTAGES = [
        'local_operational_memory',
        'operator_specific_context',
        'cross_domain_evidence',
        'personal_company_project_continuity',
        'governed_local_tools_and_runtimes',
        'documentation_operating_system',
        'curator_and_self_improvement_loops',
        'provider_agnostic_routing',
    ];

    /** Stop-The-Line outcomes. */
    public const STOP_LINE_ABSORB = 'create_ap_to_absorb';
    public const STOP_LINE_REJECT = 'explicitly_reject_with_evidence';
    public const STOP_LINE_NO_ACTION = 'no_action_line_not_tripped';
    public const STOP_LINE_INVALID = 'invalid_rejection_without_evidence';

    /**
     * Classify a proposed competitive posture against the doc's Position rule.
     *
     * Only {@see POSTURE_GOVERNED_UPPER_LAYER} is admissible. Any model-vs-model
     * framing (the canonical anti-pattern, including a fragile pass-through
     * wrapper) is rejected.
     *
     * @return array{
     *   schema_version:string,
     *   posture:string,
     *   admissible:bool,
     *   competes_as:string,
     *   reason:string
     * }
     */
    public function classifyPosition(string $posture): array
    {
        $admissible = $posture === self::POSTURE_GOVERNED_UPPER_LAYER;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'posture' => $posture,
            'admissible' => $admissible,
            'competes_as' => self::POSTURE_GOVERNED_UPPER_LAYER,
            'reason' => $admissible
                ? 'Atlas competes as the governed upper layer that decides how provider features enter the operating system.'
                : 'Atlas does not compete model-vs-model; only the governed-upper-layer posture is admissible.',
        ];
    }

    /**
     * Resolve the Absorption Loop for a released provider capability.
     *
     * Returns the ordered list of steps that actually execute for this release.
     * Step 2 (compare) is included only when `comparisonUseful` is true; step 4
     * (update Policy/Profile) executes only when `evidenceSupportsUpdate` is
     * true, but is ALWAYS listed (with `executes` reflecting the gate) because
     * the loop position is fixed. Step 5 (single channel) is an invariant and
     * always executes. The terminal invariant `single_channel_preserved` is
     * therefore always true.
     *
     * @return array{
     *   schema_version:string,
     *   capability:string,
     *   steps:list<array{ordinal:int,id:string,label:string,executes:bool,reason:string}>,
     *   executed_step_ids:list<string>,
     *   single_channel_preserved:bool
     * }
     */
    public function resolveAbsorptionLoop(string $capability, bool $comparisonUseful = true, bool $evidenceSupportsUpdate = true): array
    {
        $capability = trim($capability);
        if ($capability === '') {
            throw new InvalidArgumentException('A capability identifier is required to resolve the absorption loop.');
        }

        $steps = [];
        $executedIds = [];

        foreach (self::ABSORPTION_STEPS as $step) {
            $executes = true;
            $reason = 'Step always runs.';

            if ($step['conditional']) {
                $executes = $comparisonUseful;
                $reason = $comparisonUseful
                    ? 'Comparison is useful for this capability: comparison suite runs.'
                    : 'Comparison is not useful for this capability: step skipped per "when useful".';
            } elseif ($step['evidence_gated']) {
                $executes = $evidenceSupportsUpdate;
                $reason = $evidenceSupportsUpdate
                    ? 'Evidence supports the change: Policy/Profile and Model Selection are updated.'
                    : 'Evidence does not support the change: Policy/Profile left unchanged per "if evidence supports it".';
            } elseif ($step['invariant']) {
                $reason = 'Single Atlas channel is an invariant and is always preserved.';
            }

            if ($executes) {
                $executedIds[] = $step['id'];
            }

            $steps[] = [
                'ordinal' => $step['ordinal'],
                'id' => $step['id'],
                'label' => $step['label'],
                'executes' => $executes,
                'reason' => $reason,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'capability' => $capability,
            'steps' => $steps,
            'executed_step_ids' => $executedIds,
            // Step 5 is an invariant; the single channel is always preserved.
            'single_channel_preserved' => true,
        ];
    }

    /**
     * Return the eight structural moat advantages with ordinals.
     *
     * @return array{
     *   schema_version:string,
     *   count:int,
     *   advantages:list<array{ordinal:int,id:string}>
     * }
     */
    public function moat(): array
    {
        $advantages = [];
        foreach (self::MOAT_ADVANTAGES as $i => $id) {
            $advantages[] = ['ordinal' => $i + 1, 'id' => $id];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'count' => count($advantages),
            'advantages' => $advantages,
        ];
    }

    /**
     * Apply the Stop-The-Line rule to a provider launch.
     *
     * The line is tripped only when the launch makes users leave Atlas to get
     * better outcomes. When tripped, exactly two outcomes are admissible:
     *   - absorb: create an AP to absorb the capability;
     *   - reject: explicitly reject the capability WITH evidence.
     * A rejection without evidence is INVALID (the doc requires evidence). When
     * the line is not tripped, no Stop-The-Line action is mandated.
     *
     * @param  'absorb'|'reject'  $intendedResponse
     * @return array{
     *   schema_version:string,
     *   line_tripped:bool,
     *   intended_response:string,
     *   outcome:string,
     *   action_required:bool,
     *   valid:bool,
     *   reason:string
     * }
     */
    public function evaluateStopTheLine(bool $usersLeaveForBetterOutcomes, string $intendedResponse, bool $hasEvidence = false): array
    {
        $normalized = strtolower(trim($intendedResponse));
        if (! in_array($normalized, ['absorb', 'reject'], true)) {
            throw new InvalidArgumentException('Intended response must be "absorb" or "reject".');
        }

        if (! $usersLeaveForBetterOutcomes) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'line_tripped' => false,
                'intended_response' => $normalized,
                'outcome' => self::STOP_LINE_NO_ACTION,
                'action_required' => false,
                'valid' => true,
                'reason' => 'Launch does not make users leave Atlas for better outcomes: Stop-The-Line is not tripped.',
            ];
        }

        if ($normalized === 'absorb') {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'line_tripped' => true,
                'intended_response' => $normalized,
                'outcome' => self::STOP_LINE_ABSORB,
                'action_required' => true,
                'valid' => true,
                'reason' => 'Line tripped: create an AP to absorb the capability into the single Atlas channel.',
            ];
        }

        // reject requires evidence
        if (! $hasEvidence) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'line_tripped' => true,
                'intended_response' => $normalized,
                'outcome' => self::STOP_LINE_INVALID,
                'action_required' => true,
                'valid' => false,
                'reason' => 'Line tripped and response is reject, but no evidence supplied: explicit rejection requires evidence.',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'line_tripped' => true,
            'intended_response' => $normalized,
            'outcome' => self::STOP_LINE_REJECT,
            'action_required' => true,
            'valid' => true,
            'reason' => 'Line tripped: capability explicitly rejected with evidence.',
        ];
    }

    /**
     * Direct-usage signal (frontmatter decision): direct provider usage is a
     * signal that Atlas surface / driver / skill coverage is incomplete. When
     * the operator had to use a provider directly (bypassing the Atlas
     * channel), the relevant coverage gap must be closed.
     *
     * @return array{
     *   schema_version:string,
     *   coverage_complete:bool,
     *   signals_gap:bool,
     *   gap_kind:string,
     *   reason:string
     * }
     */
    public function assessDirectProviderUsage(bool $usedProviderDirectly, string $missingSurface = 'surface'): array
    {
        $gapKind = $usedProviderDirectly ? (trim($missingSurface) !== '' ? trim($missingSurface) : 'surface') : 'none';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'coverage_complete' => ! $usedProviderDirectly,
            'signals_gap' => $usedProviderDirectly,
            'gap_kind' => $gapKind,
            'reason' => $usedProviderDirectly
                ? 'Direct provider usage signals incomplete Atlas surface/driver/skill coverage; close the gap.'
                : 'No direct provider usage: Atlas channel coverage is sufficient for this case.',
        ];
    }
}
