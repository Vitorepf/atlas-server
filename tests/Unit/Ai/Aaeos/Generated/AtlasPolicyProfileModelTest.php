<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasPolicyProfileModelService;
use Tests\TestCase;

final class AtlasPolicyProfileModelTest extends TestCase
{
    private AtlasPolicyProfileModelService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasPolicyProfileModelService();
    }

    public function testLayeringIsTheCanonicalSevenLayerOrderWithModelSelectionAtAtlasDecide(): void
    {
        $layering = $this->service->layering();

        $names = array_column($layering['layers'], 'layer');
        $this->assertSame([
            'atlas_ai_core',
            'domain_profile',
            'flow_profile',
            'policy_profile',
            'atlas_decide',
            'domain_orchestrator',
            'runtime_executor',
        ], $names);

        // Model selection is owned by Atlas Decide, never the surface.
        $this->assertSame('atlas_decide', $layering['model_selection_layer']);
        $decide = array_values(array_filter(
            $layering['layers'],
            static fn (array $l): bool => $l['layer'] === 'atlas_decide'
        ))[0];
        $this->assertTrue($decide['owns_model_selection']);
        $this->assertSame(4, $decide['depth']);
    }

    public function testSurfaceVerbsResolveToDocumentedFlows(): void
    {
        $this->assertSame('programming.dev', $this->service->resolveFlow('dev')['flow']);
        $this->assertSame('programming.forge', $this->service->resolveFlow('forge')['flow']);

        // `atlas fix` enters a Programming repair flow.
        $fix = $this->service->resolveFlow('fix');
        $this->assertSame('programming.repair', $fix['flow']);
        $this->assertSame('repair', $fix['flow_kind']);

        // Tolerates the "atlas dev" surface form.
        $this->assertSame('programming.forge', $this->service->resolveFlow('atlas forge')['flow']);
    }

    public function testUnknownSurfaceVerbIsReportedUnresolvedNotGuessed(): void
    {
        $result = $this->service->resolveFlow('teleport');

        $this->assertFalse($result['resolved']);
        $this->assertNull($result['flow']);
        $this->assertSame('unknown_surface_verb', $result['reason']);
    }

    public function testManualOverrideIsAuditedAndNeverSeizesFlowOwnership(): void
    {
        // With a Decision Receipt the override is allowed, audited, but the flow
        // owner stays the flow profile (not the provider/model).
        $withReceipt = $this->service->evaluateModelOverride('programming.dev', 'manual:claude', true);
        $this->assertTrue($withReceipt['override_allowed']);
        $this->assertTrue($withReceipt['audited']);
        $this->assertSame('flow_profile', $withReceipt['flow_owner']);
        $this->assertFalse($withReceipt['rejected']);

        // Without a receipt, the audited override is rejected: the surface cannot
        // bypass governance.
        $noReceipt = $this->service->evaluateModelOverride('programming.dev', 'manual:claude', false);
        $this->assertTrue($noReceipt['requires_decision_receipt']);
        $this->assertTrue($noReceipt['rejected']);
        $this->assertSame('audited_override_requires_decision_receipt', $noReceipt['reason']);
    }

    public function testNoOverrideKeepsModelSelectionAtAtlasDecide(): void
    {
        $none = $this->service->evaluateModelOverride('programming.dev', null);

        $this->assertFalse($none['override_requested']);
        $this->assertFalse($none['audited']);
        $this->assertSame('flow_profile', $none['flow_owner']);
        $this->assertSame('atlas_decide', $none['model_owner']);
    }

    public function testFinanceNeverAutoExecutesTrades(): void
    {
        $trade = $this->service->autoExecutionPolicy('finance', 'trade');
        $this->assertFalse($trade['auto_execute_allowed']);
        $this->assertTrue($trade['requires_human_authorization']);
        $this->assertSame('finance_trades_never_auto_execute', $trade['reason']);

        // A non-trade finance action (e.g. read-only review) is not blocked by
        // the trade gate.
        $review = $this->service->autoExecutionPolicy('finance', 'review');
        $this->assertTrue($review['auto_execute_allowed']);
    }
}
