<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Evidence\ProviderUsagePayload;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasAiProviderPerformanceApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_provider_performance_api_returns_filtered_window_summary(): void
    {
        $this->recordProviderReturned('codex_cli', 'programming', 'feature', 'succeeded', 1.2);
        $this->recordProviderReturned('codex_cli', 'programming', 'feature', 'failed', 2.4, 'rate_limited');
        $this->recordProviderReturned('claude_cli', 'programming', 'review', 'succeeded', 3.0);

        $this->getJson('/ai/provider-performance?hours=24&provider=codex_cli&domain=programming', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('filters.provider_cli', 'codex_cli')
            ->assertJsonPath('filters.domain', 'programming')
            ->assertJsonPath('provider_performance.available', true)
            ->assertJsonPath('provider_performance.event_count', 2)
            ->assertJsonPath('provider_performance.success_count', 1)
            ->assertJsonPath('provider_performance.failure_count', 1)
            ->assertJsonPath('provider_performance.success_rate', 0.5)
            ->assertJsonPath('provider_performance.review_signal.status', 'ok')
            ->assertJsonPath('provider_performance.groups.0.provider_cli', 'codex_cli')
            ->assertJsonPath('provider_performance.groups.0.task_type', 'feature');
    }

    public function test_provider_performance_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/provider-performance')
            ->assertUnauthorized();
    }

    public function test_provider_performance_api_returns_service_unavailable_when_ledger_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $this->getJson('/ai/provider-performance', $this->headers)
            ->assertStatus(503)
            ->assertJsonPath('status', 'ledger_unavailable')
            ->assertJsonPath('provider_performance.available', false)
            ->assertJsonPath('provider_performance.review_signal.status', 'unknown')
            ->assertJsonPath('provider_performance.review_signal.recommended_action', 'wait_for_provider_usage_evidence');
    }

    private function recordProviderReturned(string $provider, string $domain, string $taskType, string $status, float $latency, ?string $failureReason = null): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => (string) Str::ulid(),
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_provider_performance_api',
            'operator_id' => 'operator_provider_performance_api',
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
                'flow' => 'programming.dev',
                'risk' => 'medium',
                'exit_status' => $status,
                'latency_seconds' => $latency,
                'repair_count' => $status === 'succeeded' ? 0 : 1,
                'failure_reason' => $failureReason,
                'selection_mode' => 'auto',
            ],
            'payload_hash' => hash('sha256', $provider.$domain.$taskType.$status.$latency),
            'occurred_at' => now(),
        ]);
    }
}
