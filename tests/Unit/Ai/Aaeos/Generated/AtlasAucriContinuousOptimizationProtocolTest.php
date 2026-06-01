<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAucriContinuousOptimizationProtocolService;
use Tests\TestCase;

final class AtlasAucriContinuousOptimizationProtocolTest extends TestCase
{
    private AtlasAucriContinuousOptimizationProtocolService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasAucriContinuousOptimizationProtocolService();
    }

    /**
     * @return array<string,mixed>
     */
    private function cleanExperiment(array $overrides = []): array
    {
        // Doc example: "Atlas Dev envia 40k tokens e resolve com 12k".
        return array_merge([
            'target_block' => 'ATER',
            'baseline_hash' => 'base-aaaa',
            'variant_hash' => 'var-bbbb',
            'input_tokens_before' => 40000,
            'input_tokens_after' => 12000,
            'output_tokens_before' => 2000,
            'output_tokens_after' => 2000,
            'quality_score_before' => 0.91,
            'quality_score_after' => 0.93,
            'must_keep_coverage' => 1.0,
            'evidence_coverage' => 1.0,
            'rollback_ref' => 'rollback://ater/var-bbbb',
            'receipt_hash' => 'receipt-cccc',
            'response_complete' => true,
        ], $overrides);
    }

    public function testCleanTokenCutWithPreservedQualityIsPromoted(): void
    {
        // Fluxo step 6: "Promover apenas se token cai sem queda de qualidade."
        $result = $this->service->evaluateExperiment($this->cleanExperiment());

        $this->assertSame('promote', $result['decision']);
        $this->assertTrue($result['promotable']);
        $this->assertSame([], $result['gates_failed']);
        // 28000 tokens removed from a 42000 total.
        $this->assertSame(28000.0, $result['token_saving']['tokens_saved']);
        $this->assertTrue($result['token_saving']['counts']);
    }

    public function testMustKeepBelowOneForcesRevertNotPromote(): void
    {
        // Evidence section: "must_keep_coverage = 1.0"; Regras para IA: "Nao
        // aceitar compressao que remove decision, blocker, DoD, constraint ou risk."
        $result = $this->service->evaluateExperiment($this->cleanExperiment([
            'must_keep_coverage' => 0.8,
        ]));

        $this->assertSame('revert', $result['decision']);
        $this->assertFalse($result['promotable']);
        $this->assertSame('must_keep_coverage_below_one', $result['reason']);
        $this->assertContains('must_keep_coverage_below_one', $result['gates_failed']);
    }

    public function testQualityRegressionForcesRevertEvenWithBigTokenSaving(): void
    {
        // A huge token cut must NOT buy a quality drop.
        $result = $this->service->evaluateExperiment($this->cleanExperiment([
            'quality_score_before' => 0.92,
            'quality_score_after' => 0.85,
        ]));

        $this->assertSame('revert', $result['decision']);
        $this->assertSame('quality_regression', $result['reason']);
        $this->assertFalse($result['quality_gate']['quality_preserved']);
    }

    public function testIncompleteResponseSavingIsRejected(): void
    {
        // Regras para IA: "Nao contar token saving de resposta incompleta."
        $result = $this->service->evaluateExperiment($this->cleanExperiment([
            'response_complete' => false,
        ]));

        $this->assertSame('reject', $result['decision']);
        $this->assertSame('incomplete_response_saving_not_counted', $result['reason']);
        // The saving must not be counted as valid even though tokens dropped.
        $this->assertFalse($result['token_saving']['counts']);
    }

    public function testHighRiskProviderDowngradeIsRejected(): void
    {
        // Regras para IA: "Nao trocar provider por custo se o risk level exige
        // modelo superior." High risk + capability rank drop => reject.
        $result = $this->service->evaluateExperiment($this->cleanExperiment([
            'risk_level' => 'high',
            'provider_capability_rank_before' => 5,
            'provider_capability_rank_after' => 3,
        ]));

        $this->assertSame('reject', $result['decision']);
        $this->assertSame('provider_downgrade_blocked_high_risk', $result['reason']);
    }

    public function testMissingRollbackRefBlocksPromotion(): void
    {
        // Evidence section requires "rollback_ref existente"; absence => revert.
        $result = $this->service->evaluateExperiment($this->cleanExperiment([
            'rollback_ref' => '',
        ]));

        $this->assertSame('revert', $result['decision']);
        $this->assertSame('missing_rollback_ref', $result['reason']);
        $this->assertFalse($result['quality_gate']['rollback_ref_present']);
    }

    public function testMissingRequiredFieldMakesExperimentNonReproducibleReject(): void
    {
        // Contratos / Regras para IA: "Nao promover variante sem baseline
        // reproduzivel." Drop a minimum field.
        $experiment = $this->cleanExperiment();
        unset($experiment['baseline_hash']);

        $result = $this->service->evaluateExperiment($experiment);

        $this->assertSame('reject', $result['decision']);
        $this->assertSame('missing_required_fields', $result['reason']);
        $this->assertContains('missing_required_fields', $result['gates_failed']);
    }

    public function testNoTokenSavingIsRejectedEvenWhenQualityHolds(): void
    {
        // Fluxo step 6 again: promote requires the token actually drops.
        $result = $this->service->evaluateExperiment($this->cleanExperiment([
            'input_tokens_after' => 40000,
            'output_tokens_after' => 2000,
        ]));

        $this->assertSame('reject', $result['decision']);
        $this->assertSame('no_token_saving', $result['reason']);
        $this->assertSame(0.0, $result['token_saving']['tokens_saved']);
    }

    public function testBatchDoesNotPromoteWhenAHighRiskCaseRegressedEvenIfAverageImproved(): void
    {
        // Regras para IA: "Nao otimizar por media se caso high-risk piorou."
        $batch = $this->service->decideBatch([
            $this->cleanExperiment(), // medium-risk clean promote
            $this->cleanExperiment([  // high-risk case that regressed quality
                'target_block' => 'ACPFR',
                'risk_level' => 'high',
                'quality_score_before' => 0.9,
                'quality_score_after' => 0.7,
            ]),
        ]);

        $this->assertFalse($batch['batch_promotable']);
        $this->assertSame('revert', $batch['batch_decision']);
        $this->assertSame('high_risk_case_regressed', $batch['block_reason']);
        $this->assertSame(1, $batch['summary']['high_risk_regressed']);
    }

    public function testRequiredFieldsContractMatchesDoc(): void
    {
        // Contratos / "Campos minimos".
        $fields = $this->service->requiredFields();

        $this->assertContains('must_keep_coverage', $fields);
        $this->assertContains('evidence_coverage', $fields);
        $this->assertContains('rollback_ref', $fields);
        $this->assertContains('receipt_hash', $fields);
        $this->assertContains('baseline_hash', $fields);
        $this->assertContains('variant_hash', $fields);
        // The doc's "Escopo de Implementacao" lists exactly ten techniques.
        $this->assertCount(10, $this->service->techniques());
        // Protected kinds the doc forbids dropping.
        $this->assertSame(['decision', 'blocker', 'dod', 'constraint', 'risk'], $this->service->mustKeepKinds());
    }
}
