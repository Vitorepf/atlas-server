<?php

namespace App\Services\Ai\MarketingDomain\Decision;

use App\Models\AiMarketingVslAsset;

/**
 * VslStructuralDiagnosisOrchestrator — turns the REACTIVE symptom→action tree into a PROACTIVE
 * pre-spend diagnostic. It chains the persuasion/anatomy audit, the awareness-alignment audit and
 * the offer (Value-Equation) score, then maps each structurally-weak VSL block to the funnel stage
 * whose floor it will break — so the operator knows where the money will leak BEFORE buying traffic.
 * Deterministic; consumed by MarketingVslDossierService (it does not duplicate economics/bid).
 */
class VslStructuralDiagnosisOrchestrator
{
    /** Weak VSL anatomy block → the MarketingSymptomActionTree funnel stage it will break. */
    private const BLOCK_TO_STAGE = [
        'hook' => 'vsl_watch_through',
        'agitate' => 'vsl_watch_through',
        'problem_mechanism' => 'vsl_watch_through',
        'solution_mechanism' => 'vsl_watch_through',
        'proof' => 'checkout_rate',
        'offer' => 'checkout_rate',
        'guarantee' => 'checkout_rate',
        'scarcity' => 'checkout_rate',
        'cta' => 'checkout_rate',
        'ps_objections' => 'checkout_rate',
    ];

    public function __construct(
        private readonly VslPersuasionAuditService $persuasion,
        private readonly VslAwarenessAlignmentAuditor $awareness,
        private readonly OfferDoctorScorer $offer,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function diagnose(AiMarketingVslAsset $asset): array
    {
        $persuasion = $this->persuasion->audit($asset);
        $awareness = $this->awareness->audit($asset);
        $offer = $this->offer->score($asset);

        $blocks = (array) ($persuasion['anatomy'] ?? []);
        $predicted = $this->predictFunnelSymptoms($blocks);

        // Structural strength: script anatomy (50%) + offer readiness (35%) + awareness aligned (15%).
        $strength = (int) round(
            (int) ($persuasion['score'] ?? 0) * 0.5
            + (int) ($offer['offer_readiness'] ?? 0) * 0.35
            + (($awareness['aligned'] ?? false) ? 15 : 0)
        );

        return [
            'vsl_id' => $asset->id,
            'structural_strength' => $strength,
            'block_scores' => array_map(static fn (array $b): string => $b['status'], $blocks),
            'persuasion_score' => $persuasion['score'] ?? 0,
            'offer_readiness' => $offer['offer_readiness'] ?? 0,
            'offer_weakest_term' => $offer['weakest_term'] ?? null,
            'awareness_aligned' => $awareness['aligned'] ?? false,
            'predicted_funnel_symptoms' => $predicted,
            'expected_floor_breaks' => array_values(array_unique(array_column($predicted, 'stage'))),
            'prioritized_edit_plan' => $this->editPlan($persuasion, $awareness, $offer),
            'note' => 'Diagnóstico estrutural PRÉ-tráfego: prevê qual piso do funil quebra antes de gastar.',
        ];
    }

    /**
     * Map weak/missing blocks to the funnel stage (DEFAULT_FLOORS entry) expected to fail.
     *
     * @param  array<string,array<string,mixed>>  $blocks
     * @return array<int,array<string,mixed>>
     */
    private function predictFunnelSymptoms(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block => $b) {
            if (($b['status'] ?? 'present') === 'present') {
                continue;
            }
            $stage = self::BLOCK_TO_STAGE[$block] ?? null;
            if ($stage === null) {
                continue;
            }
            $out[] = [
                'block' => $block,
                'status' => $b['status'],
                'stage' => $stage,
                'symptom' => $b['weak_symptom'] ?? '',
                'fix_action' => $b['fix_action'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $persuasion
     * @param  array<string,mixed>  $awareness
     * @param  array<string,mixed>  $offer
     * @return array<int,array<string,mixed>>
     */
    private function editPlan(array $persuasion, array $awareness, array $offer): array
    {
        $plan = (array) ($persuasion['fixes'] ?? []);

        foreach ((array) ($awareness['correction_needed'] ?? []) as $c) {
            $plan[] = ['priority' => 'high', 'target' => 'awareness', 'issue' => $c, 'action' => 'edit_vsl_headline'];
        }

        if ((int) ($offer['offer_readiness'] ?? 100) < 70) {
            $plan[] = [
                'priority' => 'high',
                'target' => 'offer:'.($offer['weakest_term'] ?? 'unknown'),
                'issue' => 'offer fraco no termo '.($offer['weakest_term'] ?? '?').' — '.($offer['recommended_lever'] ?? ''),
                'action' => 'strengthen_close',
            ];
        }

        return $plan;
    }
}
