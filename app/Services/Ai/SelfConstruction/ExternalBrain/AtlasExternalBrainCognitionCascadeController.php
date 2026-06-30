<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Controls a cheap-to-expensive cognition cascade for task origination.
 * Selects the minimum-cost path that satisfies all safety invariants.
 *
 * STAGES (ordered cheapest → most expensive):
 *   deterministic_preflight  — local evidence checks; no model call (always included)
 *   scaffolded_small_model   — cheaper model with strict scaffold enforcement
 *   critique_quorum          — multiple critique passes required
 *   frontier_review          — frontier / most expensive model
 *   repair_loop              — self-repair after failure
 *
 * STAGE INCLUSION RULES:
 *   scaffolded_small_model — evidence_quality < floor, OR ambiguity >= ceiling,
 *                            OR risk >= ceiling, OR has_conflicting_evidence
 *   critique_quorum        — risk >= ceiling, OR has_conflicting_evidence,
 *                            OR (ambiguity >= ceiling AND quality < floor)
 *   frontier_review        — (ambiguity >= ceiling AND has_conflicting_evidence)
 *                            OR (leverage_score >= high_leverage_floor AND
 *                                scaffold_confidence < scaffold_conf_floor)
 *   repair_loop            — needs_repair, OR (risk >= ceiling AND has_conflicting_evidence)
 *
 * FRONTIER SKIP: frontier_review is skipped when risk < ceiling AND quality >= floor.
 *
 * DEFAULT THRESHOLDS:
 *   evidence_floor       = 0.60
 *   ambiguity_ceiling    = 0.70
 *   risk_ceiling         = 0.70
 *   high_leverage_floor  = 0.80
 *   scaffold_conf_floor  = 0.70
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainCognitionCascadeController
{
    public const SCHEMA = 'atlas.external_brain.cognition_cascade_controller.v1';

    public const STAGE_DETERMINISTIC_PREFLIGHT = 'deterministic_preflight';
    public const STAGE_SCAFFOLDED_SMALL_MODEL  = 'scaffolded_small_model';
    public const STAGE_CRITIQUE_QUORUM         = 'critique_quorum';
    public const STAGE_FRONTIER_REVIEW         = 'frontier_review';
    public const STAGE_REPAIR_LOOP             = 'repair_loop';

    // Backward-compat aliases
    public const STAGE_LOCAL_ONLY          = self::STAGE_DETERMINISTIC_PREFLIGHT;
    public const STAGE_FRONTIER_ESCALATION = self::STAGE_FRONTIER_REVIEW;

    private const ALL_STAGES = [
        self::STAGE_DETERMINISTIC_PREFLIGHT,
        self::STAGE_SCAFFOLDED_SMALL_MODEL,
        self::STAGE_CRITIQUE_QUORUM,
        self::STAGE_FRONTIER_REVIEW,
        self::STAGE_REPAIR_LOOP,
    ];

    private const DEFAULT_EVIDENCE_FLOOR      = 0.60;
    private const DEFAULT_AMBIGUITY_CEILING   = 0.70;
    private const DEFAULT_RISK_CEILING        = 0.70;
    private const DEFAULT_HIGH_LEVERAGE_FLOOR = 0.80;
    private const DEFAULT_SCAFFOLD_CONF_FLOOR = 0.70;

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
        $evidenceQuality     = (float) ($input['evidence_quality']         ?? 0.0);
        $ambiguityScore      = (float) ($input['ambiguity_score']          ?? 0.0);
        $riskScore           = (float) ($input['risk_score']               ?? 0.0);
        $scaffoldConfidence  = (float) ($input['scaffold_confidence']      ?? 1.0);
        $conflictingEvidence = (bool)  ($input['has_conflicting_evidence'] ?? false);
        $leverageScore       = (float) ($input['leverage_score']           ?? 0.0);
        $needsRepair         = (bool)  ($input['needs_repair']             ?? false);

        $thresholds        = is_array($input['thresholds'] ?? null) ? $input['thresholds'] : [];
        $evidenceFloor     = (float) ($thresholds['evidence_floor']      ?? self::DEFAULT_EVIDENCE_FLOOR);
        $ambiguityCeiling  = (float) ($thresholds['ambiguity_ceiling']   ?? self::DEFAULT_AMBIGUITY_CEILING);
        $riskCeiling       = (float) ($thresholds['risk_ceiling']        ?? self::DEFAULT_RISK_CEILING);
        $highLeverageFloor = (float) ($thresholds['high_leverage_floor'] ?? self::DEFAULT_HIGH_LEVERAGE_FLOOR);
        $scaffoldConfFloor = (float) ($thresholds['scaffold_conf_floor'] ?? self::DEFAULT_SCAFFOLD_CONF_FLOOR);

        $qualityOk       = $evidenceQuality >= $evidenceFloor;
        $ambiguityHi     = $ambiguityScore >= $ambiguityCeiling;
        $riskHi          = $riskScore >= $riskCeiling;
        $highLeverage    = $leverageScore >= $highLeverageFloor;
        $lowScaffoldConf = $scaffoldConfidence < $scaffoldConfFloor;

        $needsScaffolded = ! $qualityOk || $ambiguityHi || $riskHi || $conflictingEvidence;
        $needsCritique   = $riskHi || $conflictingEvidence || ($ambiguityHi && ! $qualityOk);
        $needsFrontier   = ($ambiguityHi && $conflictingEvidence) || ($highLeverage && $lowScaffoldConf);
        $needsRepairLoop = $needsRepair || ($riskHi && $conflictingEvidence);

        $path              = [self::STAGE_DETERMINISTIC_PREFLIGHT];
        $escalationReasons = [];

        if ($needsScaffolded || $needsCritique || $needsFrontier || $needsRepairLoop) {
            $path[] = self::STAGE_SCAFFOLDED_SMALL_MODEL;
            if (! $qualityOk) {
                $escalationReasons[] = sprintf('evidence_quality %.3f below floor %.3f', $evidenceQuality, $evidenceFloor);
            }
            if ($ambiguityHi) {
                $escalationReasons[] = sprintf('ambiguity_score %.3f meets or exceeds ceiling %.3f', $ambiguityScore, $ambiguityCeiling);
            }
            if ($riskHi) {
                $escalationReasons[] = sprintf('risk_score %.3f meets or exceeds ceiling %.3f', $riskScore, $riskCeiling);
            }
            if ($conflictingEvidence) {
                $escalationReasons[] = 'conflicting_evidence detected';
            }
        }

        if ($needsCritique || $needsFrontier || $needsRepairLoop) {
            $path[] = self::STAGE_CRITIQUE_QUORUM;
        }

        if ($needsFrontier) {
            $path[] = self::STAGE_FRONTIER_REVIEW;
            if ($ambiguityHi && $conflictingEvidence) {
                $escalationReasons[] = 'frontier required: high ambiguity with conflicting evidence';
            }
            if ($highLeverage && $lowScaffoldConf) {
                $escalationReasons[] = sprintf(
                    'frontier required: high leverage %.3f with low scaffold confidence %.3f',
                    $leverageScore, $scaffoldConfidence,
                );
            }
        }

        if ($needsRepairLoop) {
            $path[] = self::STAGE_REPAIR_LOOP;
            $escalationReasons[] = 'repair_loop required: prior failure or high-risk conflicting evidence';
        }

        $skipped = array_values(array_diff(self::ALL_STAGES, $path));

        return [
            'schema'               => self::SCHEMA,
            'selected_path'        => $path,
            'selected_stage'       => end($path),   // backward-compat: deepest stage
            'skipped_stages'       => $skipped,
            'escalation_reasons'   => $escalationReasons,
            'stop_conditions'      => $this->stopConditions($path),
            'rollback_conditions'  => $this->rollbackConditions($path),
            'fallback_plan'        => $this->fallbackPlan($path),    // backward-compat
            'required_local_gates' => self::REQUIRED_LOCAL_GATES,
        ];
    }

    /** @param list<string> $path */
    private function stopConditions(array $path): array
    {
        $conds = ['deterministic_preflight passes all required local gates'];
        if (in_array(self::STAGE_SCAFFOLDED_SMALL_MODEL, $path, true)) {
            $conds[] = 'scaffolded_small_model confidence above floor with no escalation triggers remaining';
        }
        if (in_array(self::STAGE_CRITIQUE_QUORUM, $path, true)) {
            $conds[] = 'critique_quorum achieves ≥ 2 agreeing critiques with no blocking objection';
        }
        if (in_array(self::STAGE_FRONTIER_REVIEW, $path, true)) {
            $conds[] = 'frontier_review confirms task is sound and evidence non-conflicting';
        }
        if (in_array(self::STAGE_REPAIR_LOOP, $path, true)) {
            $conds[] = 'repair_loop produces clean diff with passing tests';
        }

        return $conds;
    }

    /** @param list<string> $path */
    private function rollbackConditions(array $path): array
    {
        $conds = ['evidence contradicts spec → rollback to deterministic_preflight'];
        if (in_array(self::STAGE_FRONTIER_REVIEW, $path, true)) {
            $conds[] = 'frontier_review rejects task → halt and require human review';
        }
        if (in_array(self::STAGE_REPAIR_LOOP, $path, true)) {
            $conds[] = 'repair_loop exceeds max iterations → quarantine task';
        }

        return $conds;
    }

    /** @param list<string> $path */
    private function fallbackPlan(array $path): string
    {
        return match (end($path)) {
            self::STAGE_DETERMINISTIC_PREFLIGHT => 'upgrade to scaffolded_small_model if ambiguity or risk rises',
            self::STAGE_SCAFFOLDED_SMALL_MODEL  => 'escalate to critique_quorum if scaffold compliance fails',
            self::STAGE_CRITIQUE_QUORUM         => 'escalate to frontier_review if critique consensus fails',
            self::STAGE_FRONTIER_REVIEW         => 'no further escalation; review evidence quality and reduce scope',
            self::STAGE_REPAIR_LOOP             => 'quarantine task if repair loop exceeds limit',
            default                             => 'unknown',
        };
    }
}
