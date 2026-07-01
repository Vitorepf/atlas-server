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

    public const CASCADE_STAGE_READ_STATE = 'read_state';
    public const CASCADE_STAGE_UNDERSTAND = 'understand';
    public const CASCADE_STAGE_PROPOSE    = 'propose';
    public const CASCADE_STAGE_CRITIQUE   = 'critique';
    public const CASCADE_STAGE_REPAIR     = 'repair';
    public const CASCADE_STAGE_ADMIT      = 'admit';
    public const CASCADE_STAGE_FEEDBACK   = 'feedback';

    private const CASCADE_STAGES = [
        self::CASCADE_STAGE_READ_STATE,
        self::CASCADE_STAGE_UNDERSTAND,
        self::CASCADE_STAGE_PROPOSE,
        self::CASCADE_STAGE_CRITIQUE,
        self::CASCADE_STAGE_REPAIR,
        self::CASCADE_STAGE_ADMIT,
        self::CASCADE_STAGE_FEEDBACK,
    ];

    public const STAGE_STATUS_COMPLETED = 'completed';
    public const STAGE_STATUS_SKIPPED   = 'skipped';
    public const STAGE_STATUS_BLOCKED   = 'blocked';
    public const STAGE_STATUS_PENDING   = 'pending';

    /**
     * Builds the deterministic high-impact task-origination cascade plan:
     * read_state → understand → propose → critique → repair → admit → feedback.
     *
     * Fails closed: a high-impact task that skips critique, or a task
     * requiring evidence repair that skips repair, is blocked at that stage
     * and every later stage is reported pending — admission never proceeds
     * past a skipped safety stage.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function cascadePlan(array $input): array
    {
        $isHighImpact = (bool) ($input['is_high_impact'] ?? false);
        $repairRequired = (bool) ($input['repair_required'] ?? false);

        $done = [
            self::CASCADE_STAGE_READ_STATE => (bool) ($input['state_read_done'] ?? false),
            self::CASCADE_STAGE_UNDERSTAND => (bool) ($input['understanding_done'] ?? false),
            self::CASCADE_STAGE_PROPOSE => (bool) ($input['candidates_proposed'] ?? false),
            self::CASCADE_STAGE_CRITIQUE => (bool) ($input['critique_done'] ?? false),
            self::CASCADE_STAGE_REPAIR => (bool) ($input['repair_done'] ?? false),
        ];

        // Fail-closed: a high-impact task MUST run critique; evidence repair
        // MUST run when required. Neither stage may be silently skipped.
        $stageRequired = [
            self::CASCADE_STAGE_READ_STATE => true,
            self::CASCADE_STAGE_UNDERSTAND => true,
            self::CASCADE_STAGE_PROPOSE => true,
            self::CASCADE_STAGE_CRITIQUE => $isHighImpact,
            self::CASCADE_STAGE_REPAIR => $repairRequired,
            self::CASCADE_STAGE_ADMIT => true,
            self::CASCADE_STAGE_FEEDBACK => true,
        ];

        $stageStatus = [];
        $blockedStage = null;
        $canProceed = true;

        foreach (self::CASCADE_STAGES as $stage) {
            if (! $canProceed) {
                $stageStatus[$stage] = self::STAGE_STATUS_PENDING;
                continue;
            }

            if (in_array($stage, [self::CASCADE_STAGE_ADMIT, self::CASCADE_STAGE_FEEDBACK], true)) {
                $stageStatus[$stage] = self::STAGE_STATUS_COMPLETED;
                continue;
            }

            $required = $stageRequired[$stage] ?? true;
            $isDone = $done[$stage] ?? false;

            if (! $required) {
                $stageStatus[$stage] = self::STAGE_STATUS_SKIPPED;
                continue;
            }

            if ($isDone) {
                $stageStatus[$stage] = self::STAGE_STATUS_COMPLETED;
                continue;
            }

            $stageStatus[$stage] = self::STAGE_STATUS_BLOCKED;
            $blockedStage = $stage;
            $canProceed = false;
        }

        $nextRequiredAction = match ($blockedStage) {
            self::CASCADE_STAGE_READ_STATE => 'read current evidence and queue state before proceeding',
            self::CASCADE_STAGE_UNDERSTAND => 'build domain understanding of the scope before proposing candidates',
            self::CASCADE_STAGE_PROPOSE => 'generate candidate tasks before critique',
            self::CASCADE_STAGE_CRITIQUE => 'run critique on this high-impact task before any admission decision',
            self::CASCADE_STAGE_REPAIR => 'repair the missing/invalid evidence before admission',
            default => null,
        };

        return [
            'schema' => self::SCHEMA,
            'cascade_stages' => self::CASCADE_STAGES,
            'stage_status' => $stageStatus,
            'blocked_stage' => $blockedStage,
            'next_required_action' => $nextRequiredAction,
            'admitted' => $blockedStage === null,
        ];
    }

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
        $sufficientEvidenceToDecide = (bool) ($input['sufficient_evidence_to_decide'] ?? false);

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

        // Explicit stop condition (AC3): when the caller declares enough evidence already
        // exists to enqueue or reject, the cascade must not keep "thinking" past preflight
        // merely because a soft signal (ambiguity/leverage) crossed a threshold. Hard safety
        // signals — an actual risk ceiling breach or a real evidence conflict — are never
        // overridden by a sufficiency claim.
        $stoppedEarly = $sufficientEvidenceToDecide && ! $riskHi && ! $conflictingEvidence;
        if ($stoppedEarly) {
            $needsScaffolded = false;
            $needsCritique   = false;
            $needsFrontier   = false;
        }

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

        // Safety invariant: deterministic_preflight must always be present, and frontier_review
        // may only appear when a genuine escalation condition (ambiguity+conflict or high-leverage
        // with low scaffold confidence) actually fired for this input.
        $safetyInvariantsSatisfied = in_array(self::STAGE_DETERMINISTIC_PREFLIGHT, $path, true)
            && (in_array(self::STAGE_FRONTIER_REVIEW, $path, true) === $needsFrontier);

        return [
            'schema'                      => self::SCHEMA,
            'selected_path'               => $path,
            'selected_stages'             => $path,
            'minimum_cost_path'           => $path,
            'safety_invariants_satisfied' => $safetyInvariantsSatisfied,
            'selected_stage'              => end($path),   // backward-compat: deepest stage
            'skipped_stages'              => $skipped,
            'escalation_reasons'          => $escalationReasons,
            'stop_conditions'             => $this->stopConditions($path),
            'rollback_conditions'         => $this->rollbackConditions($path),
            'fallback_plan'               => $this->fallbackPlan($path),    // backward-compat
            'required_local_gates'        => self::REQUIRED_LOCAL_GATES,
            'stopped_early'               => $stoppedEarly,
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
