<?php

namespace Tests\Feature\Ai\FinanceDomain;

use App\Services\Ai\Finance\Kernel\FinanceEnterpriseAnalysisService;
use Tests\TestCase;

class FinanceEnterpriseAnalysisTest extends TestCase
{
    public function test_enterprise_analysis_packet_models_institutional_finance_stack_without_execution(): void
    {
        $packet = app(FinanceEnterpriseAnalysisService::class)->packet('MSFT');

        $this->assertSame(FinanceEnterpriseAnalysisService::SCHEMA, $packet['schema']);
        $this->assertSame('finance', $packet['domain_id']);
        $this->assertSame('MSFT', $packet['asset']);
        $this->assertTrue($packet['readiness']['ok']);
        $this->assertGreaterThanOrEqual(6, $packet['readiness']['connector_count']);
        $this->assertGreaterThanOrEqual(6, $packet['readiness']['flow_count']);
        $this->assertGreaterThanOrEqual(6, $packet['readiness']['agent_count']);
        $this->assertGreaterThanOrEqual(6, $packet['readiness']['work_product_count']);
        $this->assertTrue($packet['invariants']['live_trading_blocked_default']);
        $this->assertFalse($packet['invariants']['broker_execution_allowed']);
        $this->assertFalse($packet['invariants']['external_money_movement_allowed']);
        $this->assertSame(64, strlen((string) $packet['receipt_hash']));

        foreach ($packet['connectors'] as $connector) {
            $this->assertFalse($connector['external_side_effects']);
            $this->assertTrue($connector['source_links_required']);
            $this->assertNotEmpty($connector['contract_hash']);
        }

        foreach ($packet['flows'] as $flow) {
            $this->assertFalse($flow['external_side_effects']);
            $this->assertTrue($flow['audit_trail_required']);
            $this->assertContains('no_live_execution', $flow['required_gates']);
            $this->assertNotEmpty($flow['flow_hash']);
        }
    }

    public function test_enterprise_analysis_command_returns_json(): void
    {
        $exit = $this->artisan('atlas:ai:finance-domain', [
            '--action' => 'enterprise-analysis',
            '--asset' => 'MSFT',
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit);
    }
}
