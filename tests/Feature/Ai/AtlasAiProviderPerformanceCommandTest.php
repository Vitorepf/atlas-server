<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Evidence\ProviderUsagePayload;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasAiProviderPerformanceCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_command_summarizes_provider_performance_as_json(): void
    {
        $this->recordProviderReturned('codex_cli', 'programming', 'feature', 'programming.frontend', 'succeeded', 1.2, null, 1000, 100);
        $this->recordProviderReturned('codex_cli', 'programming', 'feature', 'programming.frontend', 'failed', 2.4, 'rate_limited', 2000, 200);
        $this->recordProviderReturned('claude_cli', 'programming', 'review', 'programming.architecture', 'succeeded', 3.0, null, 900, 90);

        $exit = Artisan::call('atlas:ai:provider-performance', [
            '--hours' => 24,
            '--provider' => 'codex_cli',
            '--domain' => 'programming',
            '--specialist-profile' => 'programming.frontend',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame([
            'provider_cli' => 'codex_cli',
            'domain' => 'programming',
            'specialist_profile' => 'programming.frontend',
        ], $payload['filters']);
        $this->assertSame($payload['filters'], data_get($payload, 'provider_performance.filters'));
        $this->assertSame(2, data_get($payload, 'provider_performance.event_count'));
        $this->assertSame(2, data_get($payload, 'provider_performance.returned_count'));
        $this->assertSame(1, data_get($payload, 'provider_performance.success_count'));
        $this->assertSame(1, data_get($payload, 'provider_performance.failure_count'));
        $this->assertSame(0.5, data_get($payload, 'provider_performance.success_rate'));
        $this->assertSame(3000, data_get($payload, 'provider_performance.total_cost_microusd'));
        $this->assertSame(1500, data_get($payload, 'provider_performance.average_cost_microusd'));
        $this->assertSame(300, data_get($payload, 'provider_performance.total_tokens'));
        $this->assertSame(150, data_get($payload, 'provider_performance.average_total_tokens'));
        $this->assertSame(['estimated' => 2], data_get($payload, 'provider_performance.cost_confidence_counts'));
        $this->assertSame(['codex_cli' => 2], data_get($payload, 'provider_performance.provider_counts'));
        $this->assertSame(['programming.frontend' => 2], data_get($payload, 'provider_performance.specialist_profile_counts'));
        $this->assertSame('codex_cli', data_get($payload, 'provider_performance.groups.0.provider_cli'));
        $this->assertSame('programming.frontend', data_get($payload, 'provider_performance.groups.0.specialist_profile'));
        $this->assertSame('feature', data_get($payload, 'provider_performance.groups.0.task_type'));
        $this->assertSame(1500, data_get($payload, 'provider_performance.groups.0.average_cost_microusd'));
    }

    public function test_command_human_output_includes_group_rows(): void
    {
        $this->recordProviderReturned('codex_cli', 'programming', 'feature', 'programming.frontend', 'succeeded', 1.2);

        $exit = Artisan::call('atlas:ai:provider-performance', [
            '--hours' => 24,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Provider Performance', $output);
        $this->assertStringContainsString('Success rate', $output);
        $this->assertStringContainsString('Avg cost microusd', $output);
        $this->assertStringContainsString('Avg tokens', $output);
        $this->assertStringContainsString('Review signal', $output);
        $this->assertStringContainsString('Recommended action', $output);
        $this->assertStringContainsString('codex_cli', $output);
        $this->assertStringContainsString('programming', $output);
        $this->assertStringContainsString('programming.frontend', $output);
    }

    public function test_command_reports_unavailable_when_ledger_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $exit = Artisan::call('atlas:ai:provider-performance', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('ledger_unavailable', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'provider_performance.available'));
        $this->assertSame(0, data_get($payload, 'provider_performance.event_count'));
    }

    private function recordProviderReturned(string $provider, string $domain, string $taskType, string $specialistProfile, string $status, float $latency, ?string $failureReason = null, ?int $costMicrousd = null, ?int $totalTokens = null): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => (string) Str::ulid(),
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_provider_performance',
            'operator_id' => 'operator_provider_performance',
            'envelope_id' => 'env_'.Str::random(8),
            'receipt_id' => 'rcpt_'.Str::random(8),
            'trace_id' => 'trace_'.Str::random(8),
            'correlation_id' => 'corr_'.Str::random(8),
            'causation_id' => null,
            'event_type' => LedgerEventType::ProviderReturned->value,
            'emitter_stage' => 'test',
            'emitter_version' => 'test',
            'payload' => [
                'schema_version' => ProviderUsagePayload::SCHEMA_VERSION,
                'phase' => 'returned',
                'provider_cli' => $provider,
                'domain' => $domain,
                'task_type' => $taskType,
                'specialist_profile' => $specialistProfile,
                'flow' => 'programming.dev',
                'risk' => 'medium',
                'exit_status' => $status,
                'latency_seconds' => $latency,
                'repair_count' => $status === 'succeeded' ? 0 : 1,
                'failure_reason' => $failureReason,
                'selection_mode' => 'auto',
                'prompt_tokens' => $totalTokens === null ? null : (int) floor($totalTokens / 2),
                'completion_tokens' => $totalTokens === null ? null : (int) ceil($totalTokens / 2),
                'total_tokens' => $totalTokens,
                'estimated_tokens' => $totalTokens,
                'token_source' => $totalTokens === null ? null : 'estimated_chars',
                'cost_microusd' => $costMicrousd,
                'cost_confidence' => $costMicrousd === null ? 'unknown' : 'estimated',
                'cost_source' => $costMicrousd === null ? 'missing_cost_rate' : 'estimated_chars_rate',
                'cost_mode' => $costMicrousd === null ? 'unknown' : 'operational_estimate',
            ],
            'payload_hash' => hash('sha256', $provider.$domain.$taskType.$specialistProfile.$status.$latency),
            'occurred_at' => now(),
        ]);
    }
}
