<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiTelemetryEvidencePerformanceService;
use Tests\TestCase;

/**
 * Pins the documented telemetry / evidence / performance invariants.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
 */
final class AtlasAiTelemetryEvidencePerformanceTest extends TestCase
{
    private AtlasAiTelemetryEvidencePerformanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasAiTelemetryEvidencePerformanceService;
    }

    /**
     * @param  array<string,mixed>  $overrides
     *
     * @return array<string,mixed>
     */
    private function projectionTrace(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => AtlasAiTelemetryEvidencePerformanceService::PROJECTION_SCHEMA_VERSION,
            'projection_id' => AtlasAiTelemetryEvidencePerformanceService::PROJECTION_ID,
            'provider' => null,
            'model' => null,
            'has_active_rate' => false,
        ], $overrides);
    }

    /**
     * Invariante: a ledger projection without provider/model is NOT a provider
     * execution; cost is estimated / provider_not_applicable / not_applicable.
     */
    public function test_ledger_projection_without_provider_is_not_applicable_cost(): void
    {
        $c = $this->service->classifyTraceCost($this->projectionTrace());

        $this->assertSame('estimated', $c['cost_confidence']);
        $this->assertSame('provider_not_applicable', $c['cost_source']);
        $this->assertSame('not_applicable', $c['cost_mode']);
        $this->assertFalse($c['provider_execution']);
        $this->assertFalse($c['actionable']);
    }

    /**
     * Invariante: the missing-cost-rate report excludes a projection-without-
     * provider trace "mesmo quando houver summary antigo ainda nao recomputado".
     */
    public function test_projection_excluded_from_missing_cost_report_even_with_stale_summary(): void
    {
        $report = $this->service->includeInMissingCostReport(
            $this->projectionTrace(['has_stale_summary' => true])
        );

        $this->assertFalse($report['include']);
        $this->assertSame('excluded_projection_not_applicable', $report['reason']);
    }

    /**
     * Invariante: a real CLI/provider trace with no active rate is unknown cost
     * AND actionable as missing_active_cost_rate (and lands on the report).
     */
    public function test_real_provider_without_active_rate_is_actionable_and_reported(): void
    {
        $trace = [
            'provider' => 'claude-code-cli',
            'model' => 'opus',
            'has_active_rate' => false,
        ];

        $c = $this->service->classifyTraceCost($trace);
        $this->assertSame('unknown', $c['cost_confidence']);
        $this->assertTrue($c['provider_execution']);
        $this->assertTrue($c['actionable']);
        $this->assertSame('missing_active_cost_rate', $c['action']);

        $report = $this->service->includeInMissingCostReport($trace);
        $this->assertTrue($report['include']);
        $this->assertSame('missing_active_cost_rate', $report['action']);
    }

    /**
     * Invariante: trend may not cross different aggregator_version without
     * reprocessing or an explicit note; a single version trends freely.
     */
    public function test_aggregator_version_gate_blocks_mixed_versions(): void
    {
        $blocked = $this->service->gateAggregatorComparability(['v3', 'v4']);
        $this->assertFalse($blocked['allow_trend']);
        $this->assertTrue($blocked['mixed_versions']);
        $this->assertSame('mixed_aggregator_versions', $blocked['reason']);

        $single = $this->service->gateAggregatorComparability(['v4', 'v4']);
        $this->assertTrue($single['allow_trend']);
        $this->assertFalse($single['mixed_versions']);

        $withNote = $this->service->gateAggregatorComparability(
            ['v3', 'v4'],
            ['explicit_note' => 'rollup pending; comparing v3 baseline to v4 trial']
        );
        $this->assertTrue($withNote['allow_trend']);
        $this->assertSame('explicit_note_acknowledged', $withNote['reason']);
    }

    /**
     * Invariante: "Baixa amostra gera `watch`, nao falso `critical`." Below the
     * minimum sample, a proposed critical is clamped to watch; above it, the
     * proposed status stands.
     */
    public function test_low_sample_clamps_critical_to_watch(): void
    {
        $low = $this->service->resolveHealthStatus('critical', 2, 10);
        $this->assertSame('watch', $low['status']);
        $this->assertTrue($low['clamped']);
        $this->assertSame('critical', $low['proposed_status']);

        $ok = $this->service->resolveHealthStatus('critical', 40, 10);
        $this->assertSame('critical', $ok['status']);
        $this->assertFalse($ok['clamped']);
    }

    /**
     * Invariante: the notification receipt never authorizes provider call,
     * runtime execution, policy patch or memory write; it is replay/audit only.
     */
    public function test_notification_receipt_authorizes_nothing_actionable(): void
    {
        foreach (['provider_call', 'runtime_execution', 'policy_patch', 'memory_write'] as $authority) {
            $d = $this->service->receiptAuthorizes($authority);
            $this->assertFalse($d['authorized'], "receipt must NOT authorize {$authority}");
            $this->assertTrue($d['replay_only']);
        }

        $replay = $this->service->receiptAuthorizes('replay');
        $this->assertTrue($replay['authorized']);
        $this->assertTrue($replay['replay_only']);
    }

    /**
     * Invariante: estimated cost stays estimated — the Atlas never converts an
     * operational estimate into a real charge; only metered cost is chargeable.
     */
    public function test_estimated_cost_is_never_chargeable(): void
    {
        $this->assertFalse($this->service->assertChargeable('estimated')['may_charge']);
        $this->assertFalse($this->service->assertChargeable('unknown')['may_charge']);
        $this->assertTrue($this->service->assertChargeable('metered')['may_charge']);
    }
}
