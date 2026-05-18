<?php

namespace App\Services\Ai\Finance\Kernel;

use App\Services\Ai\Mission\MissionCertificationService;
use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use App\Services\Ai\Mission\WorkOrderFactoryService;

class FinanceDomainSmokeService
{
    public function __construct(
        private readonly FinanceDomainManifestSeeder $seeder,
        private readonly FinanceRuntimeService $runtime,
        private readonly FinanceResearchDeskService $research,
        private readonly FinanceValuationService $valuation,
        private readonly FinancePortfolioReviewService $portfolio,
        private readonly FinanceRiskReviewService $risk,
        private readonly FinanceComplianceService $compliance,
        private readonly FinanceReportingService $reporting,
        private readonly FinancePaperTradingSimulationService $paperTrading,
        private readonly FinanceControlPlaneProjection $controlPlane,
        private readonly MissionFactoryService $missionFactory,
        private readonly ObjectiveDecomposerService $decomposer,
        private readonly WorkOrderFactoryService $workOrderFactory,
        private readonly MissionLifecycleService $lifecycle,
        private readonly MissionEvidenceService $evidence,
        private readonly MissionCertificationService $certification,
    ) {}

    /**
     * Run a full Finance Company Runtime smoke covering research → valuation →
     * portfolio → risk → compliance → paper trading → reporting, plus
     * mission lifecycle and certification.
     *
     * @return array<string,mixed>
     */
    public function run(?string $prompt = null, string $asset = 'AAPL'): array
    {
        $manifest = $this->seeder->seed();

        $prompt ??= "produzir investment brief review-only para [{$asset}] com research, valuation, portfolio review, risk, compliance e paper trading simulado";
        $mission = $this->missionFactory->create($prompt, [
            'mission_type' => MissionFactoryService::TYPE_MISSION,
            'autonomy_level' => MissionFactoryService::AUTONOMY_SUGGEST,
            'risk_level' => MissionFactoryService::RISK_HIGH,
            'primary_domain' => FinanceDomainCanon::DOMAIN_ID,
        ]);
        $this->decomposer->decompose($mission);
        $this->lifecycle->transition($mission, MissionLifecycleService::STATUS_PLANNED, ['actor_type' => 'finance_runtime']);
        $this->workOrderFactory->plan($mission);
        $this->lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING, ['actor_type' => 'finance_runtime']);

        $runtimeRecord = $this->runtime->open($mission, 'finance.research_desk');

        $research = $this->research->research($mission, $asset, ['sources' => ['internal:atlas-research:default-corpus', 'browser:cache:snapshot']]);
        $valuation = $this->valuation->value($mission, $asset);
        $portfolio = $this->portfolio->review($mission, [
            ['asset' => $asset, 'weight_pct' => 12.5],
            ['asset' => 'CASH', 'weight_pct' => 30.0],
            ['asset' => 'INDEX_VTI', 'weight_pct' => 57.5],
        ], [$asset => 10.0, 'CASH' => 20.0, 'INDEX_VTI' => 70.0]);
        $risk = $this->risk->review($mission, $asset);
        $complianceReview = $this->compliance->review($mission, 'finance.research_desk', $prompt, ['operator_consent' => true]);
        $paper = $this->paperTrading->simulate($mission, [
            ['asset' => $asset, 'side' => 'buy', 'qty' => 10, 'price' => 150.0],
            ['asset' => $asset, 'side' => 'sell', 'qty' => 4, 'price' => 158.0],
        ]);
        $brief = $this->reporting->brief($mission, [
            'research' => $research,
            'valuation' => $valuation,
            'portfolio' => $portfolio,
            'risk' => $risk,
            'compliance' => $complianceReview,
            'paper_trading' => $paper,
        ]);

        $this->evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_ARTIFACT,
            'evidence_ref' => 'finance.investment_brief:'.$mission->uuid,
            'metadata' => ['brief_receipt_hash' => $brief['receipt_hash']],
        ]);
        $this->evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_DOC,
            'evidence_ref' => 'finance.compliance_review:'.$mission->uuid,
            'metadata' => ['decision' => $complianceReview['decision']],
        ]);

        $this->lifecycle->transition($mission, MissionLifecycleService::STATUS_CERTIFYING, ['actor_type' => 'finance_runtime']);
        $certification = $this->certification->certify($mission);
        if ($certification->status === MissionCertificationService::STATUS_PASSED) {
            $this->runtime->complete($runtimeRecord);
            $this->lifecycle->transition($mission, MissionLifecycleService::STATUS_COMPLETED, ['actor_type' => 'finance_runtime']);
        }

        $snapshot = $this->controlPlane->snapshot();

        return [
            'ok' => $mission->refresh()->status === MissionLifecycleService::STATUS_COMPLETED
                && $certification->status === 'passed'
                && $complianceReview['decision'] === 'REVIEW',
            'manifest' => [
                'domain_id' => $manifest->domain_id,
                'manifest_hash' => $manifest->manifest_hash,
                'capability_count' => $manifest->capabilities()->count(),
            ],
            'mission' => [
                'id' => $mission->id,
                'uuid' => $mission->uuid,
                'status' => $mission->status,
                'mission_type' => $mission->mission_type,
            ],
            'runtime_record_id' => $runtimeRecord->id,
            'sections' => [
                'research_hash' => $research['receipt_hash'],
                'valuation_hash' => $valuation['receipt_hash'],
                'portfolio_hash' => $portfolio['receipt_hash'],
                'risk_hash' => $risk['receipt_hash'],
                'compliance_decision' => $complianceReview['decision'],
                'paper_trade_pnl_cash_only' => $paper['pnl_cash_only'],
                'brief_hash' => $brief['receipt_hash'],
            ],
            'certification' => [
                'status' => $certification->status,
                'certification_hash' => $certification->certification_hash,
            ],
            'invariants' => $snapshot['invariants'],
            'control_plane_summary' => $snapshot['finance'],
        ];
    }
}
