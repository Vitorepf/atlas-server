<?php

namespace Tests\Unit\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Evidence\ProviderPerformanceProjection;
use App\Services\Ai\Kernel\Evidence\ProviderUsagePayload;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderPerformanceProjectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 32)->primary();
            $table->string('schema_version', 40)->default('atlas.ledger_event.v1');
            $table->string('tenant_id', 120)->index();
            $table->string('operator_id', 120)->index();
            $table->string('envelope_id', 80)->index();
            $table->string('receipt_id', 80)->nullable()->index();
            $table->string('trace_id', 80)->nullable()->index();
            $table->string('correlation_id', 120)->index();
            $table->string('causation_id', 80)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('emitter_stage', 120)->index();
            $table->string('emitter_version', 80);
            $table->json('payload');
            $table->string('payload_hash', 64)->index();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_provider_performance_projection_groups_by_provider_domain_specialist_and_task_type(): void
    {
        $this->recordProviderReturned('codex_cli', 'programming', 'feature', 'programming.frontend', 'succeeded', 1.2, null, 1000, 100);
        $this->recordProviderReturned('codex_cli', 'programming', 'feature', 'programming.frontend', 'failed', 2.4, 'rate_limited', 2000, 200);
        $this->recordProviderReturned('claude_cli', 'programming', 'review', 'programming.architecture', 'succeeded', 3.0, null, 900, 90);
        $this->recordProviderFallback('gemini_cli', 'programming', 'feature', 'programming.frontend', 'claude_cli', 'auth_expired');

        $report = app(ProviderPerformanceProjection::class)->reportForWindow(now()->subHour());

        $this->assertTrue((bool) $report['available']);
        $this->assertSame(ProviderUsagePayload::SCHEMA_VERSION, $report['schema_version']);
        $this->assertSame(4, $report['event_count']);
        $this->assertSame(3, $report['returned_count']);
        $this->assertSame(1, $report['fallback_count']);
        $this->assertSame(2, $report['success_count']);
        $this->assertSame(2, $report['failure_count']);
        $this->assertSame(0.6667, $report['success_rate']);
        $this->assertSame(3900, $report['total_cost_microusd']);
        $this->assertSame(1300.0, $report['average_cost_microusd']);
        $this->assertSame(3, $report['costed_event_count']);
        $this->assertSame(1, $report['unknown_cost_count']);
        $this->assertSame(390, $report['total_tokens']);
        $this->assertSame(130.0, $report['average_total_tokens']);
        $this->assertSame(['estimated' => 3, 'unknown' => 1], $report['cost_confidence_counts']);
        $this->assertSame(['operational_estimate' => 3, 'unknown' => 1], $report['cost_mode_counts']);
        $this->assertSame(['codex_cli' => 2, 'claude_cli' => 1, 'gemini_cli' => 1], $report['provider_counts']);
        $this->assertSame(['programming.frontend' => 3, 'programming.architecture' => 1], $report['specialist_profile_counts']);

        $codexFeature = collect($report['groups'])->firstWhere('provider_cli', 'codex_cli');
        $this->assertSame('programming', data_get($codexFeature, 'domain'));
        $this->assertSame('programming.frontend', data_get($codexFeature, 'specialist_profile'));
        $this->assertSame('feature', data_get($codexFeature, 'task_type'));
        $this->assertSame(2, data_get($codexFeature, 'returned_count'));
        $this->assertSame(0.5, data_get($codexFeature, 'success_rate'));
        $this->assertSame(1.8, data_get($codexFeature, 'average_latency_seconds'));
        $this->assertSame(3000, data_get($codexFeature, 'total_cost_microusd'));
        $this->assertSame(1500.0, data_get($codexFeature, 'average_cost_microusd'));
        $this->assertSame(300, data_get($codexFeature, 'total_tokens'));
        $this->assertSame(['estimated' => 2], data_get($codexFeature, 'cost_confidence_counts'));

        $filtered = app(ProviderPerformanceProjection::class)->reportForWindow(now()->subHour(), filters: [
            'provider' => 'codex_cli',
            'domain' => 'programming',
            'specialist_profile' => 'programming.frontend',
        ]);
        $this->assertSame(2, $filtered['event_count']);
        $this->assertSame(['codex_cli' => 2], $filtered['provider_counts']);
        $this->assertSame(['programming.frontend' => 2], $filtered['specialist_profile_counts']);
        $this->assertSame(3000, $filtered['total_cost_microusd']);
    }

    private function recordProviderReturned(string $provider, string $domain, string $taskType, string $specialistProfile, string $status, float $latency, ?string $failureReason = null, ?int $costMicrousd = null, ?int $totalTokens = null): void
    {
        $this->record(LedgerEventType::ProviderReturned, [
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
        ]);
    }

    private function recordProviderFallback(string $provider, string $domain, string $taskType, string $specialistProfile, string $fallbackProvider, string $reason): void
    {
        $this->record(LedgerEventType::ProviderFallback, [
            'schema_version' => ProviderUsagePayload::SCHEMA_VERSION,
            'phase' => 'fallback',
            'provider_cli' => $provider,
            'domain' => $domain,
            'task_type' => $taskType,
            'specialist_profile' => $specialistProfile,
            'flow' => 'programming.dev',
            'risk' => 'medium',
            'exit_status' => 'fallback',
            'latency_seconds' => 0.4,
            'repair_count' => 0,
            'failure_reason' => $reason,
            'fallback_provider' => $fallbackProvider,
            'selection_mode' => 'auto',
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'total_tokens' => null,
            'estimated_tokens' => null,
            'token_source' => null,
            'cost_microusd' => null,
            'cost_confidence' => 'unknown',
            'cost_source' => 'missing_cost_rate',
            'cost_mode' => 'unknown',
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function record(LedgerEventType $type, array $payload): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => (string) Str::ulid(),
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant',
            'operator_id' => 'operator',
            'envelope_id' => 'env_'.Str::random(8),
            'receipt_id' => 'rcpt_'.Str::random(8),
            'trace_id' => 'trace_'.Str::random(8),
            'correlation_id' => 'corr_'.Str::random(8),
            'event_type' => $type->value,
            'emitter_stage' => 'test',
            'emitter_version' => 'test',
            'payload' => $payload,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'occurred_at' => now(),
        ]);
    }
}
