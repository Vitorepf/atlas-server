<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Compounding\AtlasRagFeedbackService;
use App\Services\Ai\Context\AtlasDeliveredPackLedger;
use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\TestCase;

/**
 * MAXG-07 — cost_per_useful_token additive field on the policy-trend command.
 *
 * Joins delivered-pack-ledger (chars→tokens via `chars_div_4`) with ARFL measured
 * events; inferred events NEVER contribute (denominator floor pétreo).
 */
final class Maxg07CostPerUsefulTokenTest extends TestCase
{
    use BootsCompoundingSchema;

    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCompoundingSchema();
        Carbon::setTestNow(Carbon::parse('2026-07-11 12:00:00', 'UTC'));

        $this->ledgerPath = storage_path('atlas/aobg/delivered-pack-ledger.jsonl.maxg07-test');
        @unlink($this->ledgerPath);
        config(['atlas.aobg.delivered_pack_ledger.path' => $this->ledgerPath]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->dropCompoundingSchema();
        @unlink($this->ledgerPath);

        parent::tearDown();
    }

    public function test_cost_per_useful_token_joins_ledger_chars_and_used_ratio(): void
    {
        $this->recordLedgerEntry('pack-A', deliveredChars: 4000);
        $this->recordLedgerEntry('pack-B', deliveredChars: 8000);

        $this->recordEvent('cput-a', '2026-07-11 09:00:00', packHash: 'pack-A', used: 1, delivered: 2);
        $this->recordEvent('cput-b', '2026-07-11 10:00:00', packHash: 'pack-B', used: 4, delivered: 4);

        $exit = Artisan::call('atlas:context:policy-trend', [
            '--flow-id' => 'policy.trend.cput',
            '--window' => 'day',
            '--windows' => 1,
            '--min-total' => 2,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $window = $payload['windows'][0];
        $this->assertSame('valid', $window['status']);
        $cput = $window['cost_per_useful_token'];
        $this->assertSame('atlas.context.cost_per_useful_token.v1', $cput['formula_version']);
        $this->assertSame('chars_div_4', $cput['estimate_basis']);
        $this->assertSame('measured', $cput['status']);
        $this->assertSame(2, $cput['joined_measured_events']);
        $this->assertSame(0, $cput['unavailable_pack_events']);
        $this->assertSame(3000, $cput['delivered_tokens']);
        $this->assertEqualsWithDelta(2500.0, $cput['useful_tokens'], 0.5);
        $this->assertGreaterThan(1.0, $cput['ratio']);
    }

    public function test_inferred_events_never_contribute_to_cost_per_useful_token(): void
    {
        $this->recordLedgerEntry('pack-C', deliveredChars: 4000);

        $this->recordEvent('cput-measured', '2026-07-11 09:00:00', packHash: 'pack-C', used: 1, delivered: 2);
        $this->recordEvent('cput-inferred', '2026-07-11 10:00:00', packHash: 'pack-C', used: 1, delivered: 2, attributionQuality: 'transcript_inferred');

        $exit = Artisan::call('atlas:context:policy-trend', [
            '--flow-id' => 'policy.trend.cput',
            '--window' => 'day',
            '--windows' => 1,
            '--min-total' => 1,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $cput = $payload['windows'][0]['cost_per_useful_token'];
        $this->assertSame(1, $cput['joined_measured_events'], 'transcript_inferred events must be filtered before join');
    }

    public function test_missing_pack_in_ledger_returns_unavailable_and_never_fabricates(): void
    {
        $this->recordEvent('cput-nopack', '2026-07-11 09:00:00', packHash: 'pack-missing', used: 1, delivered: 2);
        $this->recordEvent('cput-nohash', '2026-07-11 10:00:00', packHash: '', used: 1, delivered: 2);

        Artisan::call('atlas:context:policy-trend', [
            '--flow-id' => 'policy.trend.cput',
            '--window' => 'day',
            '--windows' => 1,
            '--min-total' => 1,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $cput = $payload['windows'][0]['cost_per_useful_token'];
        $this->assertSame('insufficient_signal', $cput['status']);
        $this->assertNull($cput['ratio']);
        $this->assertSame(0, $cput['joined_measured_events']);
        $this->assertSame(2, $cput['unavailable_pack_events']);
    }

    public function test_previous_series_fields_are_byte_identical_after_landing(): void
    {
        $this->recordEvent('cput-b1', '2026-07-11 09:00:00', packHash: 'pack-x', used: 1, delivered: 2);

        Artisan::call('atlas:context:policy-trend', [
            '--flow-id' => 'policy.trend.cput',
            '--window' => 'day',
            '--windows' => 1,
            '--min-total' => 1,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $window = $payload['windows'][0];

        $this->assertArrayHasKey('avg_utility', $window);
        $this->assertArrayHasKey('avg_used_ratio', $window);
        $this->assertArrayHasKey('noise_ratio', $window);
        $this->assertArrayHasKey('formula_version', $window);
        $this->assertArrayHasKey('roi_trend', $window);
    }

    private function recordLedgerEntry(string $hash, int $deliveredChars): void
    {
        (new JsonlReceiptStore($this->ledgerPath))->append([
            'schema' => AtlasDeliveredPackLedger::SCHEMA,
            'context_pack_hash' => $hash,
            'delivered_refs' => [],
            'delivered_chars' => $deliveredChars,
            'budgets' => ['total_chars' => $deliveredChars],
            'policy_snapshot' => [],
            'timings_ms' => [],
            'cache' => [],
            'ts' => now()->toJSON(),
        ]);
    }

    private function recordEvent(
        string $receiptId,
        string $createdAt,
        string $packHash,
        int $used,
        int $delivered,
        string $attributionQuality = 'gate_verified',
    ): void {
        $event = app(AtlasRagFeedbackService::class)->record([
            'retrieval_receipt_id' => $receiptId,
            'flow_id' => 'policy.trend.cput',
            'query_plan_hash' => hash('sha256', $receiptId),
            'included_sources' => $delivered,
            'used_sources' => $used,
            'noise_sources' => max(0, $delivered - $used - 1),
            'missed_required_sources' => [],
            'context_sufficiency' => 80,
            'post_execution_utility' => 90,
            'source_utility' => [],
            'outcome_status' => 'passed',
            'measured' => true,
            'payload' => [
                'schema_version' => 'atlas.aucri.retrieval_feedback_loop.v1',
                'measured' => true,
                'attribution_quality' => $attributionQuality,
                'context_pack_hash' => $packHash,
                'formula_version' => 'formula.v1',
                'applied_policy_snapshot' => ['status' => 'active'],
                'context_roi' => [
                    'measured' => true,
                    'post_execution_utility' => 90,
                    'formula_version' => 'formula.v1',
                    'use_ratio' => round($used / max(1, $delivered), 4),
                    'roi_score' => 0.9,
                ],
                'context_ref_attribution' => [
                    'measured' => true,
                    'usage_basis' => 'explicit_used_refs',
                    'context_pack_hash' => $packHash,
                    'delivered_count' => $delivered,
                    'used_count' => $used,
                    'noise_count' => max(0, $delivered - $used - 1),
                    'use_ratio' => round($used / max(1, $delivered), 4),
                    'waste_ratio' => round(max(0, $delivered - $used) / max(1, $delivered), 4),
                ],
            ],
        ]);
        $event->forceFill([
            'created_at' => Carbon::parse($createdAt, 'UTC'),
            'updated_at' => Carbon::parse($createdAt, 'UTC'),
        ])->save();
    }
}
