<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;

/**
 * ConversionStrategist — the unified conversion BRAIN that orchestrates the organs.
 *
 * The OS has many instruments (awareness/sophistication routers, mechanism forge, value-equation auditor,
 * aggressive-tactics library). Alone they are siloed checks. This composes them into ONE strategic plan
 * for a given asset: where the reader is (awareness) → which aggressive levers fit and in what order;
 * how burned-out the market is (sophistication) → the claim/mechanism move; the forged proprietary
 * mechanism name; the Value-Equation gaps the offer must close. One call → the full blueprint the
 * operator/engine acts on to sell THIS offer to THIS market. Deterministic, provider-free, niche-agnostic.
 */
class ConversionStrategist
{
    public function __construct(
        private readonly AwarenessAggressionRouter $awareness = new AwarenessAggressionRouter,
        private readonly MarketSophisticationRouter $sophistication = new MarketSophisticationRouter,
        private readonly ValueEquationAuditor $valueEquation = new ValueEquationAuditor,
        private readonly MechanismNameForge $forge = new MechanismNameForge,
        private readonly ProofSubstanceAuditor $proof = new ProofSubstanceAuditor,
        private readonly BigIdeaLeadForge $lead = new BigIdeaLeadForge,
        private readonly ObjectionLoopEngine $objectionLoop = new ObjectionLoopEngine,
    ) {}

    /**
     * @return array{awareness:array<string,mixed>,sophistication:array<string,mixed>,mechanism:array<string,mixed>,offer_gaps:array<int,array<string,mixed>>,aggression_order:array<int,string>,summary:string}
     */
    public function plan(AiMarketingVslAsset $asset, string $copy = ''): array
    {
        $awarenessLevel = (string) $asset->awareness_level;
        $sophLevel = (string) $asset->sophistication_level;

        $aggressionOrder = $this->awareness->fittingTactics($awarenessLevel);
        $soph = $this->sophistication->strategy($sophLevel);
        $mech = $this->forge->forge($asset);

        // Offer levers: audit the page copy if given, else assemble the asset's own offer ammunition.
        $offerText = $copy !== '' ? $copy : trim(implode(' ', array_filter([
            (string) $asset->big_idea, (string) $asset->core_promise,
            is_array($asset->offer) ? implode(' ', array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $asset->offer)) : (string) $asset->offer,
            // Include the asset's proof/claim ammunition so the brain audits ALL selling material.
            implode(' ', array_filter(array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', (array) $asset->claims))),
            implode(' ', array_filter(array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', (array) (is_array($asset->metrics) ? ($asset->metrics['result_claims'] ?? []) : [])))),
        ])));
        $ve = $this->valueEquation->audit($offerText);
        $proof = $this->proof->audit($offerText);

        $awarenessNorm = $this->awareness->normalize($awarenessLevel);
        // The forge always yields a name; the sophistication move decides whether to LEAD with it.
        $mechName = $mech['best'] ?? null;

        $summary = sprintf(
            'Awareness=%s → abre com %s. Mercado nv%d (%s)%s. Oferta: %d/4 alavancas%s.',
            $awarenessNorm,
            $aggressionOrder[0] ?? '—',
            $soph['level'],
            $soph['strategy'],
            $soph['forge_mechanism'] && $mechName ? ' → mecanismo "'.$mechName.'"' : '',
            count($ve['covered']),
            $ve['gaps'] !== [] ? ' (fechar: '.implode(', ', array_map(static fn ($g) => $g['key'], array_slice($ve['gaps'], 0, 2))).')' : ''
        );

        return [
            'awareness' => ['level' => $awarenessNorm, 'opening_tactics' => $aggressionOrder, 'why' => $this->awareness->rationale($awarenessLevel)],
            'sophistication' => $soph,
            'mechanism' => ['lead_with_mechanism' => $soph['forge_mechanism'], 'name' => $mechName, 'candidates' => $mech['candidates']],
            'offer_gaps' => $ve['gaps'],
            // Proof is the #1 lever — surface its CONCRETENESS, not just whether the offer mentions it.
            'proof' => ['concrete' => $proof['concrete'], 'vague' => $proof['vague'], 'has_concrete' => $proof['has_concrete'], 'note' => $proof['note']],
            'aggression_order' => $aggressionOrder,
            // The raw author-ready material so this plan is a COMPLETE brief (pillar 2: hand the muscle a
            // perfect package — the lead variants to open with, and the Belfort loop for the top objection).
            'lead' => $this->lead->forge($asset),
            'objection_loop' => $this->objectionLoop->loop($asset),
            'summary' => $summary.($proof['has_concrete'] ? '' : ' ⚠ PROVA fraca: '.$proof['note']),
        ];
    }
}
