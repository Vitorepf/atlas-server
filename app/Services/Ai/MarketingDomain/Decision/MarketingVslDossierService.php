<?php

namespace App\Services\Ai\MarketingDomain\Decision;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\BidStrategyDecider;
use App\Services\Ai\MarketingDomain\Campaign\CampaignEconomicsCalculator;
use App\Services\Ai\MarketingDomain\CampaignPlanService;
use App\Services\Ai\MarketingDomain\CopyBriefService;
use App\Services\Ai\MarketingDomain\FunnelPlanService;
use App\Services\Ai\MarketingDomain\ICPPositioningService;

/**
 * MarketingVslDossierService — the per-VSL capstone. It composes EVERY capability into one
 * read-only decision artifact for a single VSL: unit economics, the phased bid plan, the real
 * persuasion/anatomy audit of the script, the execution briefs (copy/funnel/offer/traffic routed
 * by the VSL's awareness), a launch-readiness score, and the single recommended first move.
 *
 * This is what turns the scattered services into ONE answer the operator can act on. Deterministic,
 * no persistence, no side effects.
 */
class MarketingVslDossierService
{
    public function __construct(
        private readonly CampaignEconomicsCalculator $economicsCalc,
        private readonly BidStrategyDecider $bids,
        private readonly VslPersuasionAuditService $auditor,
        private readonly VslStructuralDiagnosisOrchestrator $structural,
        private readonly CopyBriefService $copy,
        private readonly FunnelPlanService $funnel,
        private readonly ICPPositioningService $icp,
        private readonly CampaignPlanService $campaign,
    ) {}

    /**
     * @param  array<string,mixed>  $inputs  payout/margin/refund/cvr (cvr falls back to VSL/assumption)
     * @return array<string,mixed>
     */
    public function compile(AiMarketingVslAsset $vsl, array $inputs): array
    {
        $awareness = $this->str($vsl->awareness_level);

        $economics = ((float) ($inputs['payout'] ?? 0) > 0)
            ? $this->economicsCalc->compute(array_filter([
                'payout' => (float) $inputs['payout'],
                'target_margin' => $inputs['margin'] ?? null,
                'refund_rate' => $inputs['refund'] ?? null,
                'cvr' => $inputs['cvr'] ?? null,
            ], static fn ($v): bool => $v !== null))
            : [];

        $audit = $this->auditor->audit($vsl);
        $structuralDiagnosis = $this->structural->diagnose($vsl);
        $bidPlan = $economics !== [] ? $this->bids->plan($economics) : null;

        $readiness = $this->readiness($vsl, $audit, $economics);

        return [
            'vsl' => [
                'id' => $vsl->id,
                'campaign_ref' => $vsl->campaign_ref,
                'niche' => $vsl->niche,
                'awareness_level' => $awareness,
                'big_idea' => $this->str($vsl->big_idea),
                'mechanism_name' => $this->str($vsl->mechanism_name),
                'has_transcript' => trim((string) $vsl->transcript) !== '',
            ],
            'economics' => $economics,
            'bid_plan' => $bidPlan,
            'vsl_audit' => $audit,
            'structural_diagnosis' => $structuralDiagnosis,
            'execution_briefs' => [
                'copy' => $this->copy->blueprint($awareness),
                'funnel' => $this->funnel->blueprint('cold'),
                'offer' => $this->icp->blueprint($awareness),
                'traffic' => $this->campaign->blueprint('google_search'),
            ],
            'readiness' => $readiness,
            'recommended_first_move' => $this->firstMove($audit, $economics),
        ];
    }

    /**
     * Launch-readiness: a blended 0-100 of script quality (audit), economics viability and extraction
     * completeness, plus the blocking gaps that must close before paid traffic.
     *
     * @param  array<string,mixed>  $audit
     * @param  array<string,mixed>  $economics
     * @return array<string,mixed>
     */
    private function readiness(AiMarketingVslAsset $vsl, array $audit, array $economics): array
    {
        $blockers = [];

        $scriptScore = (int) ($audit['score'] ?? 0);
        if (! ($audit['has_transcript'] ?? false)) {
            $blockers[] = 'VSL sem transcript/extração — rode ingestion+extract.';
        }
        if ($scriptScore < 70) {
            $blockers[] = "Script com score {$scriptScore}/100 — fechar os blocos fracos antes de escalar.";
        }

        $economicsViable = false;
        if ($economics !== []) {
            $maxCpa = (float) ($economics['max_cpa'] ?? 0);
            $economicsViable = $maxCpa > 0;
            if (! $economicsViable) {
                $blockers[] = 'Economia inviável (Max CPA ≤ 0) — payout/refund/margem não fecham.';
            }
        } else {
            $blockers[] = 'Sem payout — passe --payout pra travar a economia (Max CPA/ROAS).';
        }

        $extractionComplete = trim((string) $vsl->solution_mechanism) !== '' && is_array($vsl->offer) && $vsl->offer !== [];
        if (! $extractionComplete) {
            $blockers[] = 'Extração incompleta (mecanismo/oferta) — reprocessar o extractor.';
        }

        $score = (int) round(
            $scriptScore * 0.5
            + ($economicsViable ? 30 : 0)
            + ($extractionComplete ? 20 : 0)
        );

        return [
            'score' => $score,
            'launchable' => $blockers === [],
            'blockers' => $blockers,
            'components' => [
                'script' => $scriptScore,
                'economics_viable' => $economicsViable,
                'extraction_complete' => $extractionComplete,
            ],
        ];
    }

    /**
     * The single highest-leverage move: the script's top fix if the script is weak, else launch/scale.
     *
     * @param  array<string,mixed>  $audit
     * @param  array<string,mixed>  $economics
     * @return array<string,mixed>
     */
    private function firstMove(array $audit, array $economics): array
    {
        $fixes = (array) ($audit['fixes'] ?? []);
        $topFix = $fixes[0] ?? null;

        if ($topFix !== null && ($topFix['priority'] ?? '') === 'high') {
            return [
                'move' => 'fix_script',
                'action' => $topFix['action'] ?? null,
                'why' => (string) ($topFix['issue'] ?? ''),
                'rule' => (string) ($topFix['rule'] ?? ''),
            ];
        }

        if ($economics === []) {
            return ['move' => 'set_economics', 'why' => 'passar o payout pra travar Max CPA/ROAS antes de lançar.'];
        }

        return [
            'move' => 'launch_first_test',
            'why' => 'script e economia prontos — subir o teste fase-1 (brand+product+solution aware) e medir o funil.',
            'budget' => $economics['daily_budget'] ?? null,
            'kill_at' => $economics['kill_spend_no_sale'] ?? null,
        ];
    }

    private function str(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : $v;
    }
}
