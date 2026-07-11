<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Compounding\AtlasRagFeedbackService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\TestCase;

final class ContextPolicyTrendCommandTest extends TestCase
{
    use BootsCompoundingSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootCompoundingSchema();
        Carbon::setTestNow(Carbon::parse('2026-07-11 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->dropCompoundingSchema();

        parent::tearDown();
    }

    public function test_policy_trend_reports_measured_windows_empty_windows_and_roi_delta(): void
    {
        $this->recordEvent('trend-old-a', '2026-07-09 09:00:00', utility: 60, used: 1, delivered: 2);
        $this->recordEvent('trend-old-b', '2026-07-09 10:00:00', utility: 80, used: 2, delivered: 2);
        $this->recordEvent('trend-current-a', '2026-07-11 09:00:00', utility: 90, used: 2, delivered: 2);
        $this->recordEvent('trend-current-b', '2026-07-11 10:00:00', utility: 100, used: 2, delivered: 2);
        $this->recordEvent('trend-ignored-transcript', '2026-07-11 11:00:00', utility: 1, used: 0, delivered: 2, attributionQuality: 'transcript_inferred');

        $exit = Artisan::call('atlas:context:policy-trend', [
            '--flow-id' => 'policy.trend',
            '--window' => 'day',
            '--windows' => 3,
            '--min-total' => 2,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.context.policy_trend.v1', $payload['schema_version']);
        $this->assertCount(3, $payload['windows']);
        $this->assertSame('valid', data_get($payload, 'windows.0.status'));
        $this->assertSame('empty', data_get($payload, 'windows.1.status'));
        $this->assertSame('valid', data_get($payload, 'windows.2.status'));
        $this->assertSame(2, data_get($payload, 'windows.2.measured_count'));
        $this->assertSame(3, data_get($payload, 'windows.2.total_event_count'));
        $this->assertSame(95.0, data_get($payload, 'windows.2.avg_utility'));
        $this->assertSame(1.0, data_get($payload, 'windows.2.avg_used_ratio'));
        $this->assertSame(0.0, data_get($payload, 'windows.2.noise_ratio'));
        $this->assertSame(0, data_get($payload, 'windows.2.unresolved_missed_count'));
        $this->assertSame(25.0, data_get($payload, 'windows.2.roi_trend.utility_delta'));
        $this->assertSame(['initial_context_budget_multiplier' => 0.85], data_get($payload, 'windows.2.multipliers'));
    }

    public function test_policy_trend_resets_delta_across_formula_versions_and_marks_low_volume_invalid(): void
    {
        $this->recordEvent('trend-v1', '2026-07-10 10:00:00', utility: 60, used: 1, delivered: 2, formulaVersion: 'formula.v1');
        $this->recordEvent('trend-v2', '2026-07-11 10:00:00', utility: 95, used: 2, delivered: 2, formulaVersion: 'formula.v2');

        $exit = Artisan::call('atlas:context:policy-trend', [
            '--flow-id' => 'policy.trend',
            '--window' => 'day',
            '--windows' => 2,
            '--min-total' => 2,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('invalid', data_get($payload, 'windows.0.status'));
        $this->assertSame('invalid', data_get($payload, 'windows.1.status'));
        $this->assertNull(data_get($payload, 'windows.1.roi_trend.utility_delta'));
        $this->assertSame('below_total_event_floor', data_get($payload, 'windows.1.invalid_reason'));

        $exit = Artisan::call('atlas:context:policy-trend', [
            '--flow-id' => 'policy.trend',
            '--window' => 'day',
            '--windows' => 2,
            '--min-total' => 1,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('valid', data_get($payload, 'windows.0.status'));
        $this->assertSame('valid', data_get($payload, 'windows.1.status'));
        $this->assertSame('formula.v2', data_get($payload, 'windows.1.formula_version'));
        $this->assertNull(data_get($payload, 'windows.1.roi_trend.utility_delta'));
    }

    private function recordEvent(
        string $receiptId,
        string $createdAt,
        int $utility,
        int $used,
        int $delivered,
        string $attributionQuality = 'gate_verified',
        string $formulaVersion = 'formula.v1',
    ): void {
        $event = app(AtlasRagFeedbackService::class)->record([
            'retrieval_receipt_id' => $receiptId,
            'flow_id' => 'policy.trend',
            'query_plan_hash' => hash('sha256', $receiptId),
            'included_sources' => $delivered,
            'used_sources' => $used,
            'noise_sources' => max(0, $delivered - $used - 1),
            'missed_required_sources' => [],
            'context_sufficiency' => 80,
            'post_execution_utility' => $utility,
            'source_utility' => [],
            'outcome_status' => 'passed',
            'measured' => true,
            'payload' => [
                'schema_version' => 'atlas.aucri.retrieval_feedback_loop.v1',
                'measured' => true,
                'attribution_quality' => $attributionQuality,
                'formula_version' => $formulaVersion,
                'applied_policy_snapshot' => [
                    'status' => 'active',
                    'initial_context_budget_multiplier' => 0.85,
                    'source_selection_policy' => [
                        'budget_multipliers' => [
                            'code' => 1.0,
                            'graph' => 1.0,
                            'memory' => 0.85,
                        ],
                    ],
                ],
                'context_roi' => [
                    'measured' => true,
                    'post_execution_utility' => $utility,
                    'formula_version' => $formulaVersion,
                    'use_ratio' => round($used / max(1, $delivered), 4),
                    'roi_score' => $utility / 100,
                ],
                'context_ref_attribution' => [
                    'measured' => true,
                    'usage_basis' => 'explicit_used_refs',
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
