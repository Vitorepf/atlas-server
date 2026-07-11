<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Mcp;

use App\Models\AiTelemetryEvent;
use App\Services\Ai\AtlasOpenBrainMcpService;
use App\Services\Ai\Telemetry\AiTelemetryCollector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

final class OpenBrainToolUsageTelemetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTelemetryTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_telemetry_events');
        parent::tearDown();
    }

    public function test_call_tool_records_append_only_usage_event(): void
    {
        $service = app(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_capabilities',
                'arguments' => [],
            ],
        ]);

        $this->assertArrayHasKey('result', $response);
        $this->assertSame(1, AiTelemetryEvent::query()->count());

        $event = AiTelemetryEvent::query()->firstOrFail();
        $this->assertSame(AtlasOpenBrainMcpService::MCP_TOOL_USAGE_EVENT_NAME, $event->event_name);
        $this->assertSame('atlas_capabilities', data_get($event->metadata, 'tool_name'));
        $this->assertSame('ok', data_get($event->metadata, 'status'));
        $this->assertNotNull($event->received_at);
    }

    public function test_tool_usage_command_aggregates_counts_and_first_seen_at(): void
    {
        $collector = app(AiTelemetryCollector::class);
        $collector->record([
            'event_key' => 'open_brain:mcp_tool:first',
            'surface' => 'server',
            'runtime' => 'laravel',
            'event_name' => AtlasOpenBrainMcpService::MCP_TOOL_USAGE_EVENT_NAME,
            'event_phase' => 'ok',
            'metadata' => [
                'tool_name' => 'atlas_capabilities',
                'called_at' => now()->subMinutes(5)->toIso8601String(),
                'status' => 'ok',
            ],
        ]);
        $collector->record([
            'event_key' => 'open_brain:mcp_tool:second',
            'surface' => 'server',
            'runtime' => 'laravel',
            'event_name' => AtlasOpenBrainMcpService::MCP_TOOL_USAGE_EVENT_NAME,
            'event_phase' => 'error',
            'metadata' => [
                'tool_name' => 'atlas_capabilities',
                'called_at' => now()->toIso8601String(),
                'status' => 'error',
            ],
        ]);

        $exit = Artisan::call('atlas:open-brain:tool-usage', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas_capabilities', $payload['tools'][0]['tool_name']);
        $this->assertSame(2, $payload['tools'][0]['usage_count']);
        $this->assertSame(1, $payload['tools'][0]['ok_count']);
        $this->assertSame(1, $payload['tools'][0]['error_count']);
        $this->assertNotEmpty($payload['tools'][0]['first_seen_at']);
        $this->assertNotEmpty($payload['tools'][0]['last_seen_at']);
    }

    public function test_telemetry_failure_is_fail_open_and_does_not_break_tool_call(): void
    {
        $mock = Mockery::mock(AiTelemetryCollector::class);
        $mock->shouldReceive('record')->andThrow(new \RuntimeException('telemetry down'));
        $this->app->instance(AiTelemetryCollector::class, $mock);

        $service = app(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_capabilities',
                'arguments' => [],
            ],
        ]);

        $this->assertArrayHasKey('result', $response);
        $this->assertFalse((bool) data_get($response, 'result.isError', true));
    }

    private function createTelemetryTable(): void
    {
        Schema::dropIfExists('ai_telemetry_events');
        Schema::create('ai_telemetry_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_key', 180)->unique();
            $table->uuid('correlation_id')->nullable();
            $table->uuid('trace_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->uuid('ai_job_id')->nullable();
            $table->uuid('ai_job_attempt_id')->nullable();
            $table->uuid('client_id')->nullable();
            $table->string('surface', 24);
            $table->string('runtime', 32)->nullable();
            $table->string('app_version', 64)->nullable();
            $table->string('cli_version', 64)->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('agent_slug', 120)->nullable();
            $table->string('event_name', 100);
            $table->string('event_phase', 60)->nullable();
            $table->timestamp('occurred_at_client')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->integer('duration_ms')->nullable();
            $table->decimal('numeric_value', 14, 4)->nullable();
            $table->string('unit', 32)->nullable();
            $table->json('metadata')->default('{}');
            $table->json('privacy')->default('{}');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->timestamp('created_at')->useCurrent();
        });
    }
}
