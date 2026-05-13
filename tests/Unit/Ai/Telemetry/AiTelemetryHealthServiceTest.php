<?php

namespace Tests\Unit\Ai\Telemetry;

use App\Models\AiInboxItem;
use App\Services\Ai\Mobile\InsightInboxEmitter;
use App\Services\Ai\Telemetry\AiProviderCostRateService;
use App\Services\Ai\Telemetry\AiTelemetryHealthService;
use App\Services\Ai\Telemetry\AiTelemetryScorecardService;
use Mockery;
use Tests\TestCase;

final class AiTelemetryHealthServiceTest extends TestCase
{
    public function test_emit_insight_includes_hashable_notification_receipt_without_runtime_authority(): void
    {
        $captured = null;
        $item = new AiInboxItem;
        $item->id = 'telemetry-health-inbox-item';

        $insights = Mockery::mock(InsightInboxEmitter::class);
        $insights->shouldReceive('emit')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$captured, $item): AiInboxItem {
                $captured = $payload;

                return $item;
            });

        $service = new AiTelemetryHealthService(
            Mockery::mock(AiTelemetryScorecardService::class),
            Mockery::mock(AiProviderCostRateService::class),
            $insights,
        );

        $emission = $service->emitInsight($this->warningEvaluation(), dryRun: false);

        $this->assertTrue($emission['emitted']);
        $this->assertSame($item->id, $emission['item_id']);
        $this->assertSame('atlas.telemetry_health.notification_receipt.v1', data_get($captured, 'payload.health.notification_receipt.schema_version'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($captured, 'payload.health.notification_receipt.receipt_hash'));
        $this->assertSame('telemetry_health_daily_digest', data_get($captured, 'payload.health.notification_receipt.push_reason'));
        $this->assertTrue((bool) data_get($captured, 'payload.health.notification_receipt.no_provider_call'));
        $this->assertTrue((bool) data_get($captured, 'payload.health.notification_receipt.no_runtime_execution'));
        $this->assertTrue((bool) data_get($captured, 'payload.health.notification_receipt.no_policy_patch'));
        $this->assertTrue((bool) data_get($captured, 'payload.health.notification_receipt.no_memory_write'));
        $this->assertSame('final_quality_avg', data_get($captured, 'payload.health.notification_receipt.issue_summaries.0.key'));
        $this->assertArrayNotHasKey('summary', data_get($captured, 'payload.health.notification_receipt.issue_summaries.0'));
        $this->assertStringNotContainsString('Exact local trace payload', json_encode(data_get($captured, 'payload.health.notification_receipt'), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string,mixed>
     */
    private function warningEvaluation(): array
    {
        return [
            'available' => true,
            'status' => 'warning',
            'health_score' => 64,
            'window' => [
                'since' => '2026-05-12T20:00:00Z',
                'until' => '2026-05-12T23:00:00Z',
            ],
            'issues' => [[
                'key' => 'final_quality_avg',
                'severity' => 'warning',
                'value' => 61,
                'threshold' => 70,
                'summary' => 'Exact local trace payload should not enter notification receipt.',
            ]],
            'actions' => ['Review quality flags before changing policy.'],
            'scorecard' => [
                'totals' => [
                    'traces' => 12,
                    'final_quality_avg' => 61,
                ],
            ],
            'evidence' => [],
        ];
    }
}
