<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Controls a cheap-to-expensive cognition cascade for task origination.
 * Runs local evidence checks first, scaffolded-small-model second, and frontier only when
 * local gates prove it is genuinely needed — and never when a safe scaffold fallback exists.
 *
 * STAGES (cheapest → most expensive):
 *   local_only             — local evidence; no model call
 *   scaffolded_small_model — cheaper model with strict scaffold enforcement
 *   frontier_escalation    — frontier / most expensive model
 *
 * SELECTION (first match wins):
 *   local_only             — quality >= floor AND ambiguity < ceiling AND risk < ceiling
 *   scaffolded_small_model — quality >= floor AND (ambiguity >= ceiling OR risk >= ceiling)
 *                          — OR any escalation trigger when has_safe_scaffold_fallback=true
 *   frontier_escalation    — only when has_safe_scaffold_fallback=false AND
 *                            (quality < floor OR (ambiguity >= ceiling AND risk >= ceiling))
 *
 * INVARIANT: frontier_escalation is NEVER selected when has_safe_scaffold_fallback=true.
 *
 * REQUIRED LOCAL GATES (must pass before any stage):
 *   evidence_list_non_empty       — evidence must be loaded
 *   dedup_proof_present           — duplicate check completed
 *   no_retirable_patterns_in_scope — no retired patterns being injected
 *
 * DEFAULT THRESHOLDS:
 *   evidence_floor    = 0.60
 *   ambiguity_ceiling = 0.70
 *   risk_ceiling      = 0.70
 *
 * OUTPUT:
 *   { schema, selected_stage, skipped_stages, escalation_reasons, fallback_plan,
 *     required_local_gates }
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainCognitionCascadeController
{
    public const SCHEMA = 'atlas.external_brain.cognition_cascade_controller.v1';

    public const STAGE_LOCAL_ONLY             = 'local_only';
    public const STAGE_SCAFFOLDED_SMALL_MODEL = 'scaffolded_small_model';
    public const STAGE_FRONTIER_ESCALATION    = 'frontier_escalation';

    private const DEFAULT_EVIDENCE_FLOOR    = 0.60;
    private const DEFAULT_AMBIGUITY_CEILING = 0.70;
    private const DEFAULT_RISK_CEILING      = 0.70;

    private const ALL_STAGES = [
        self::STAGE_LOCAL_ONLY,
        self::STAGE_SCAFFOLDED_SMALL_MODEL,
        self::STAGE_FRONTIER_ESCALATION,
    ];

    private const REQUIRED_LOCAL_GATES = [
        'evidence_list_non_empty',
        'dedup_proof_present',
        'no_retirable_patterns_in_scope',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function control(array $input): array
    {
        $evidenceQuality     = (float) ($input['evidence_quality'] ?? 0.0);
        $ambiguityScore      = (float) ($input['ambiguity_score'] ?? 0.0);
        $riskScore           = (float) ($input['risk_score'] ?? 0.0);
        $hasSafeScaffold     = (bool) ($input['has_safe_scaffold_fallback'] ?? true);
        $thresholds          = is_array($input['thresholds'] ?? null) ? $input['thresholds'] : [];

        $evidenceFloor    = (float) ($thresholds['evidence_floor'] ?? self::DEFAULT_EVIDENCE_FLOOR);
        $ambiguityCeiling = (float) ($thresholds['ambiguity_ceiling'] ?? self::DEFAULT_AMBIGUITY_CEILING);
        $riskCeiling      = (float) ($thresholds['risk_ceiling'] ?? self::DEFAULT_RISK_CEILING);

        $qualityOk   = $evidenceQuality >= $evidenceFloor;
        $ambiguityHi = $ambiguityScore >= $ambiguityCeiling;
        $riskHi      = $riskScore >= $riskCeiling;

        $escalationReasons = [];

        if (! $qualityOk) {
            $escalationReasons[] = sprintf(
                'evidence_quality %.3f below floor %.3f',
                $evidenceQuality, $evidenceFloor,
            );
        }
        if ($ambiguityHi) {
            $escalationReasons[] = sprintf(
                'ambiguity_score %.3f meets or exceeds ceiling %.3f',
                $ambiguityScore, $ambiguityCeiling,
            );
        }
        if ($riskHi) {
            $escalationReasons[] = sprintf(
                'risk_score %.3f meets or exceeds ceiling %.3f',
                $riskScore, $riskCeiling,
            );
        }

        // Decision (priority order):
        // 1. Frontier: only when safe scaffold is unavailable AND (quality insufficient OR both A+R high)
        // 2. Local only: quality ok AND not ambiguous AND not risky
        // 3. Scaffolded: everything else (safe fallback or single escalation signal)
        if (! $hasSafeScaffold && (! $qualityOk || ($ambiguityHi && $riskHi))) {
            $selected = self::STAGE_FRONTIER_ESCALATION;
        } elseif ($qualityOk && ! $ambiguityHi && ! $riskHi) {
            $selected = self::STAGE_LOCAL_ONLY;
        } else {
            $selected = self::STAGE_SCAFFOLDED_SMALL_MODEL;
        }

        // skipped_stages = stages more expensive than selected (ones we avoided).
        $stageRank = [
            self::STAGE_LOCAL_ONLY             => 0,
            self::STAGE_SCAFFOLDED_SMALL_MODEL => 1,
            self::STAGE_FRONTIER_ESCALATION    => 2,
        ];
        $selectedRank = $stageRank[$selected];
        $skipped = array_values(array_filter(
            self::ALL_STAGES,
            fn (string $s): bool => $stageRank[$s] > $selectedRank,
        ));

        $fallbackPlan = match ($selected) {
            self::STAGE_LOCAL_ONLY             => 'upgrade to scaffolded_small_model if ambiguity or risk rises',
            self::STAGE_SCAFFOLDED_SMALL_MODEL => 'escalate to frontier_escalation only if scaffold compliance fails and has_safe_scaffold_fallback=false',
            self::STAGE_FRONTIER_ESCALATION    => 'no further escalation available; review evidence quality and reduce scope',
            default                            => 'unknown',
        };

        return [
            'schema'               => self::SCHEMA,
            'selected_stage'       => $selected,
            'skipped_stages'       => $skipped,
            'escalation_reasons'   => $escalationReasons,
            'fallback_plan'        => $fallbackPlan,
            'required_local_gates' => self::REQUIRED_LOCAL_GATES,
        ];
    }
}
