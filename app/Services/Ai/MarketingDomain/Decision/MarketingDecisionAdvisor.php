<?php

namespace App\Services\Ai\MarketingDomain\Decision;

use App\Models\AiMarketingWinningPattern;
use App\Services\Ai\MarketingDomain\Campaign\CampaignEconomicsCalculator;

/**
 * MarketingDecisionAdvisor — the grounded Stage-1 brain. It raises certainty two ways the bare
 * symptom→action tree cannot:
 *
 *  1. GROUNDS the diagnosis in REAL data — the per-niche Nivor winning pattern supplies the real
 *     CVR (→ economics) and real funnel rates (→ floors), so "below the floor" means "below what
 *     ACTUALLY worked for this niche", not a generic benchmark.
 *  2. COMPOUNDS the ledger — the recommendation carries the historical win-rate of that exact lever
 *     for this niche, and if the lever has lost here before it nudges toward the alternative action.
 *     Judgment composes across offers instead of resetting each time.
 *
 * The floor-derivation is a pure static method (testable without a DB); the orchestration loads the
 * pattern + ledger.
 */
class MarketingDecisionAdvisor
{
    /** Floor = winners' rate × this fraction. You should be at least this close to proven winners. */
    public const GROUND_FRACTION = 0.5;

    public function __construct(
        private readonly CampaignEconomicsCalculator $economicsCalc,
        private readonly MarketingSymptomActionTree $tree,
        private readonly MarketingDecisionLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $funnel
     * @param  array<string,mixed>  $inputs  payout/margin/refund/cvr (+ niche/campaign for grounding)
     * @return array<string,mixed>
     */
    public function advise(array $funnel, array $inputs): array
    {
        $niche = isset($inputs['niche']) ? trim((string) $inputs['niche']) : '';
        $pattern = $niche !== ''
            ? AiMarketingWinningPattern::query()->where('niche', $niche)->first()
            : null;

        // 1a. Ground the CVR in real data (real winning-pattern CVR beats the assumption).
        $cvrSource = 'assumption_default';
        if (isset($inputs['cvr']) && is_numeric($inputs['cvr'])) {
            $cvrSource = 'operator_input';
        } elseif ($pattern !== null && (float) $pattern->real_cvr > 0) {
            $inputs['cvr'] = (float) $pattern->real_cvr;
            $cvrSource = 'nivor_winning_pattern:'.$niche;
        }

        $economics = ((float) ($inputs['payout'] ?? 0) > 0)
            ? $this->economicsCalc->compute(array_filter([
                'payout' => (float) $inputs['payout'],
                'target_margin' => $inputs['margin'] ?? null,
                'refund_rate' => $inputs['refund'] ?? null,
                'cvr' => $inputs['cvr'] ?? null,
            ], static fn ($v): bool => $v !== null))
            : [];

        // 1b. Ground the floors in the winners' real funnel rates.
        $groundedFloors = $pattern !== null
            ? self::floorsFromFunnelProfile((array) $pattern->funnel_profile)
            : [];

        $diagnosis = $this->tree->diagnose($funnel, $economics, ['floors' => $groundedFloors]);

        // 2. Compound the ledger into the recommendation.
        $diagnosis['grounding'] = [
            'cvr_source' => $cvrSource,
            'grounded_floors' => $groundedFloors,
            'pattern_found' => $pattern !== null,
        ];
        if (! empty($diagnosis['primary']) && $niche !== '') {
            $diagnosis['primary'] = $this->annotateWithHistory($diagnosis['primary'], $niche);
        }

        return $diagnosis;
    }

    /**
     * Pure: convert a winning-pattern funnel_profile (percentages) into stage floors (fractions),
     * scaled by GROUND_FRACTION. Only maps stages Nivor actually measures; ad_ctr / bridge_to_vsl
     * have no Nivor equivalent and keep the tree's directional defaults.
     *
     * @param  array<string,mixed>  $funnelProfile
     * @return array<string,float>
     */
    public static function floorsFromFunnelProfile(array $funnelProfile): array
    {
        $floors = [];
        $map = [
            'vsl_watch_through' => 'vsl_completion_rate_pct',
            'checkout_rate' => 'checkout_conversion_pct',
        ];
        foreach ($map as $stage => $pctKey) {
            $pct = $funnelProfile[$pctKey] ?? null;
            if (is_numeric($pct) && (float) $pct > 0) {
                $floors[$stage] = round(((float) $pct / 100.0) * self::GROUND_FRACTION, 4);
            }
        }

        return $floors;
    }

    /**
     * Fold the ledger win-rate for this (niche, stage, action) into the primary recommendation.
     * If the lever has a losing track record here, nudge toward the alternative action.
     *
     * @param  array<string,mixed>  $primary
     * @return array<string,mixed>
     */
    private function annotateWithHistory(array $primary, string $niche): array
    {
        $stage = (string) ($primary['stage'] ?? '');
        $action = (string) ($primary['action'] ?? '');
        $recall = $this->ledger->recall(['niche' => $niche, 'stage' => $stage, 'action' => $action, 'limit' => 100]);

        $stats = $recall['summary']['by_action'][$action] ?? null;
        $winRate = $stats['win_rate'] ?? null;

        $primary['history'] = [
            'prior_decisions' => $recall['count'],
            'win_rate' => $winRate,
        ];

        if ($winRate !== null && $winRate < 0.5 && ! empty($primary['alt_action'])) {
            $primary['history']['note'] = "Este lever ({$action}) tem win-rate {$winRate} neste nicho — considerar a alternativa: {$primary['alt_action']}.";
        } elseif ($winRate !== null && $winRate >= 0.5) {
            $primary['history']['note'] = "Este lever ({$action}) já funcionou aqui (win-rate {$winRate}) — confiança histórica alta.";
        } else {
            $primary['history']['note'] = 'Sem histórico resolvido pra este lever neste nicho ainda — gravar o resultado fecha o loop.';
        }

        return $primary;
    }
}
