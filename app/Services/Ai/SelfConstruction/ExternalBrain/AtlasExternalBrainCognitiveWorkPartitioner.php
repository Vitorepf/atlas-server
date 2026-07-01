<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure partitioner. Splits a high-leverage origination mission into deterministic
 * cognitive phases and assigns each phase a model-tier hint.
 *
 * Input facts:
 *   phases       — list of {id, type?, description, blast_radius?, produces_artifacts?}.
 *                  type (optional): extraction|verification|critique|synthesis|escalation.
 *                  If absent, type is inferred from description keywords.
 *   risk_profile — {ambiguity?: float, conflicting_evidence?: bool} (global mission risk).
 *
 * AC2 — Phase types:
 *   extraction   — pull / gather / read / scan / retrieve facts.
 *   verification — check / validate / confirm / prove / compare.
 *   critique     — challenge / refute / question / adversarial review.
 *   synthesis    — combine / produce / draft / design / generate a proposal.
 *   escalation   — phase whose blast_radius >= ESCALATION_BLAST (0.70), or synthesis
 *                  when mission ambiguity >= AMBIGUITY_THRESHOLD (0.65) or conflicting_evidence.
 *
 * AC3 — Model tier assignment:
 *   extraction + verification           → small_model
 *   critique + non-escalated synthesis  → scaffolded_small_model
 *   escalation                          → frontier_model
 *
 * AC4 outputs: phase_plan, model_tier_hint, required_artifacts, escalation_points,
 *   fallback_plan.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainCognitiveWorkPartitioner
{
    public const SCHEMA = 'atlas.external_brain.cognitive_work_partitioner.v1';

    private const ESCALATION_BLAST    = 0.70;
    private const AMBIGUITY_THRESHOLD = 0.65;

    private const TIER_SMALL      = 'small_model';
    private const TIER_SCAFFOLDED = 'scaffolded_small_model';
    private const TIER_FRONTIER   = 'frontier_model';

    private const TIER_ORDER = [self::TIER_SMALL => 0, self::TIER_SCAFFOLDED => 1, self::TIER_FRONTIER => 2];

    private const KEYWORDS = [
        'extraction'   => ['extract', 'gather', 'read', 'collect', 'pull', 'scan', 'list', 'find', 'retrieve', 'fetch'],
        'verification' => ['verify', 'check', 'validate', 'confirm', 'assert', 'prove', 'compare', 'test', 'audit'],
        'critique'     => ['critique', 'challenge', 'refute', 'reject', 'adversarial', 'question', 'review', 'scrutinize'],
        'synthesis'    => ['synthesize', 'write', 'generate', 'compose', 'create', 'produce', 'draft', 'design', 'combine', 'formulate'],
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function partition(array $facts): array
    {
        $rawPhases   = is_array($facts['phases'] ?? null) ? $facts['phases'] : [];
        $risk        = is_array($facts['risk_profile'] ?? null) ? $facts['risk_profile'] : [];
        $ambiguity   = max(0.0, min(1.0, (float) ($risk['ambiguity']            ?? 0.0)));
        $conflicting = (bool) ($risk['conflicting_evidence'] ?? false);

        $phasePlan        = [];
        $allArtifacts     = [];
        $escalationPoints = [];
        $highestTierRank  = 0;

        foreach ($rawPhases as $idx => $raw) {
            $id          = (string) ($raw['id'] ?? "phase_$idx");
            $description = (string) ($raw['description'] ?? '');
            $blastRadius = max(0.0, min(1.0, (float) ($raw['blast_radius'] ?? 0.0)));
            $artifacts   = is_array($raw['produces_artifacts'] ?? null) ? $raw['produces_artifacts'] : [];

            // AC2: classify type.
            $type = $this->classifyPhase($raw, $description, $blastRadius, $ambiguity, $conflicting);

            // AC3: assign tier.
            $tier = $this->assignTier($type);

            // Collect artifacts.
            foreach ($artifacts as $a) {
                $allArtifacts[(string) $a] = true;
            }

            if ($tier === self::TIER_FRONTIER) {
                $escalationPoints[] = $id;
            }

            $rank = self::TIER_ORDER[$tier];
            if ($rank > $highestTierRank) {
                $highestTierRank = $rank;
            }

            $escalationReason = $this->escalationReason($tier, $type, $blastRadius, $ambiguity, $conflicting);

            $phasePlan[] = [
                'phase_id'           => $id,
                'phase_type'         => $type,
                'description'        => $description,
                'model_tier'         => $tier,
                'required_artifacts' => $artifacts,
                'escalation_reason'  => $escalationReason,
                'fallback_plan'      => $escalationReason !== null
                    ? "downgrade phase {$id} to a lower model tier if frontier/scaffolded advisory is unavailable; accept reduced quality on this phase, never block the mission"
                    : null,
            ];
        }

        $modelTierHint = array_search($highestTierRank, self::TIER_ORDER, true) ?: self::TIER_SMALL;

        $fallbackPlan = [
            'description'     => empty($escalationPoints)
                ? 'no frontier phases; fallback not needed'
                : 'downgrade frontier phases to scaffolded_small_model; accept reduced synthesis quality',
            'degraded_phases' => $escalationPoints,
        ];

        return [
            'schema_version'    => self::SCHEMA,
            'phase_plan'        => $phasePlan,
            'model_tier_hint'   => $modelTierHint,
            'required_artifacts' => array_values(array_keys($allArtifacts)),
            'escalation_points' => $escalationPoints,
            'fallback_plan'     => $fallbackPlan,
            'escalation_is_advisory_not_steady_state_dependency' => true,
            'steady_state_note' => 'Frontier/scaffolded escalation is advisory guidance for hard phases only; the mission never requires a provider as a permanent steady-state dependency and degrades to the fallback_plan when unavailable.',
        ];
    }

    private function escalationReason(string $tier, string $type, float $blastRadius, float $ambiguity, bool $conflicting): ?string
    {
        if ($tier === self::TIER_SMALL) {
            return null;
        }

        $reasons = [];
        if ($blastRadius >= self::ESCALATION_BLAST) {
            $reasons[] = sprintf('blast_radius:%.2f>=%.2f', $blastRadius, self::ESCALATION_BLAST);
        }
        if ($ambiguity >= self::AMBIGUITY_THRESHOLD) {
            $reasons[] = sprintf('ambiguity:%.2f>=%.2f', $ambiguity, self::AMBIGUITY_THRESHOLD);
        }
        if ($conflicting) {
            $reasons[] = 'conflicting_evidence';
        }
        if ($type === 'synthesis') {
            $reasons[] = 'architectural_synthesis_phase';
        }
        if ($type === 'critique') {
            $reasons[] = 'adversarial_critique_phase';
        }

        return $reasons !== [] ? implode(',', $reasons) : 'requires_scaffolded_or_frontier_review';
    }

    private function classifyPhase(array $raw, string $description, float $blastRadius, float $ambiguity, bool $conflicting): string
    {
        // Explicit type provided → validate it, defaulting to keyword inference if unrecognised.
        $explicit = strtolower(trim((string) ($raw['type'] ?? '')));
        $known    = ['extraction', 'verification', 'critique', 'synthesis', 'escalation'];
        if (in_array($explicit, $known, true)) {
            if ($explicit !== 'escalation' && $blastRadius >= self::ESCALATION_BLAST) {
                return 'escalation';
            }
            // AC2: high mission risk escalates any phase type (extraction/verification included).
            if ($explicit !== 'escalation' && $this->missionHighRisk($ambiguity, $conflicting)) {
                return 'escalation';
            }
            return $explicit;
        }

        // Blast radius forces escalation regardless of description.
        if ($blastRadius >= self::ESCALATION_BLAST) {
            return 'escalation';
        }

        // Keyword-match the description.
        $lower = strtolower($description);
        foreach (self::KEYWORDS as $type => $words) {
            foreach ($words as $word) {
                if (str_contains($lower, $word)) {
                    // AC2: high mission risk escalates any inferred type.
                    if ($this->missionHighRisk($ambiguity, $conflicting)) {
                        return 'escalation';
                    }
                    return $type;
                }
            }
        }

        return $this->missionHighRisk($ambiguity, $conflicting) ? 'escalation' : 'synthesis';
    }

    private function assignTier(string $type): string
    {
        return match ($type) {
            'extraction', 'verification' => self::TIER_SMALL,
            'critique', 'synthesis'      => self::TIER_SCAFFOLDED,
            'escalation'                 => self::TIER_FRONTIER,
            default                      => self::TIER_SCAFFOLDED,
        };
    }

    private function missionHighRisk(float $ambiguity, bool $conflicting): bool
    {
        return $ambiguity >= self::AMBIGUITY_THRESHOLD || $conflicting;
    }

    /** The six specialist lanes originator work is split across, feeding one final_decision lane. */
    public const SPECIALIST_LANES = ['code_evidence', 'queue_health', 'architecture', 'proof', 'model_weakness', 'refactor_roi'];

    public const FINAL_DECISION_LANE = 'final_decision';

    /**
     * Splits ORIGINATOR work (not the phase-plan above) across the specialist lanes: code_evidence,
     * queue_health, architecture, proof, model_weakness, refactor_roi, and final_decision.
     *
     * Simple, low-risk/low-ambiguity origination may stay in a single requested lane. High-risk or
     * high-ambiguity origination (mirrors missionHighRisk()) is NEVER allowed to collapse into a
     * single lane — it is rejected outright, forcing the full specialist-lane spread instead.
     *
     * A lane missing its evidence is reported (missing_evidence_lanes) rather than silently treated
     * as present, so a caller cannot claim full-lane coverage while quietly starving one lane.
     *
     * @param  array<string,mixed>  $facts  { ambiguity?, high_risk?, risk_level?,
     *   requested_lanes?: list<string>, evidence_available?: array<string,bool> }
     * @return array<string,mixed>
     */
    public function partitionSpecialistLanes(array $facts): array
    {
        $ambiguity = max(0.0, min(1.0, (float) ($facts['ambiguity'] ?? 0.0)));
        $riskLevel = strtolower(trim((string) ($facts['risk_level'] ?? 'low')));
        $highRisk = (bool) ($facts['high_risk'] ?? false)
            || $ambiguity >= self::AMBIGUITY_THRESHOLD
            || $riskLevel === 'high';

        $requestedLanes = array_values(array_unique(array_map('strval', (array) ($facts['requested_lanes'] ?? []))));
        if ($requestedLanes === []) {
            $requestedLanes = $highRisk ? self::SPECIALIST_LANES : ['code_evidence'];
        }

        // AC3: a single specialist lane is never sufficient for high-risk/high-ambiguity origination.
        $isSingleLane = count(array_diff($requestedLanes, [self::FINAL_DECISION_LANE])) <= 1;
        if ($highRisk && $isSingleLane) {
            return [
                'schema_version' => self::SCHEMA,
                'decision' => 'rejected',
                'rejected' => true,
                'rejection_reason' => 'single_lane_insufficient_for_high_risk_origination',
                'required_lanes' => array_merge(self::SPECIALIST_LANES, [self::FINAL_DECISION_LANE]),
                'lanes' => [],
                'missing_evidence_lanes' => [],
                'final_decision_inputs' => [],
            ];
        }

        $activeLanes = $highRisk ? self::SPECIALIST_LANES : array_values(array_diff($requestedLanes, [self::FINAL_DECISION_LANE]));
        $evidenceAvailable = is_array($facts['evidence_available'] ?? null) ? $facts['evidence_available'] : [];

        $lanes = [];
        $missingEvidenceLanes = [];
        foreach ($activeLanes as $lane) {
            $hasEvidence = array_key_exists($lane, $evidenceAvailable) ? (bool) $evidenceAvailable[$lane] : true;
            if (! $hasEvidence) {
                $missingEvidenceLanes[] = $lane;
            }
            $lanes[] = ['lane' => $lane, 'has_evidence' => $hasEvidence];
        }
        $lanes[] = ['lane' => self::FINAL_DECISION_LANE, 'has_evidence' => true, 'depends_on' => $activeLanes];

        return [
            'schema_version' => self::SCHEMA,
            'decision' => $highRisk ? 'multi_lane' : 'single_lane',
            'rejected' => false,
            'rejection_reason' => null,
            'lanes' => $lanes,
            'missing_evidence_lanes' => $missingEvidenceLanes,
            'final_decision_inputs' => $activeLanes,
        ];
    }
}
