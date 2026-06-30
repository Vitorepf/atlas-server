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

            $phasePlan[] = [
                'phase_id'           => $id,
                'phase_type'         => $type,
                'description'        => $description,
                'model_tier'         => $tier,
                'required_artifacts' => $artifacts,
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
        ];
    }

    private function classifyPhase(array $raw, string $description, float $blastRadius, float $ambiguity, bool $conflicting): string
    {
        // Explicit type provided → validate it, defaulting to keyword inference if unrecognised.
        $explicit = strtolower(trim((string) ($raw['type'] ?? '')));
        $known    = ['extraction', 'verification', 'critique', 'synthesis', 'escalation'];
        if (in_array($explicit, $known, true)) {
            // Still escalate a synthesis if mission risk is high.
            if ($explicit === 'synthesis' && $this->missionHighRisk($ambiguity, $conflicting)) {
                return 'escalation';
            }
            if ($explicit !== 'escalation' && $blastRadius >= self::ESCALATION_BLAST) {
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
                    // Synthesis with high mission risk → escalate.
                    if ($type === 'synthesis' && $this->missionHighRisk($ambiguity, $conflicting)) {
                        return 'escalation';
                    }
                    return $type;
                }
            }
        }

        return 'synthesis'; // safest default: scaffold tier (not small)
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
}
