<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Mcp;

use App\Models\AiTelemetryEvent;
use App\Services\Ai\AtlasOpenBrainMcpService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OpenBrainSurfaceReviewTest extends TestCase
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

    public function test_surface_review_covers_every_tool_and_emits_evidence_based_verdicts(): void
    {
        $service = app(AtlasOpenBrainMcpService::class);
        $toolNames = array_values(array_map(
            static fn (array $tool): string => (string) $tool['name'],
            $service->tools(),
        ));
        $nonPrimary = array_values(array_diff($toolNames, AtlasOpenBrainMcpService::PRIMARY_TOOLS));

        $this->assertCount(9, AtlasOpenBrainMcpService::PRIMARY_TOOLS);
        $this->assertNotEmpty($nonPrimary);

        $usedCompatTool = $nonPrimary[0];
        $candidateTool = $nonPrimary[1] ?? $nonPrimary[0];

        $this->insertToolUsage('atlas_capabilities', now()->subDays(91));
        $this->insertToolUsage($usedCompatTool, now()->subDays(91));

        $exit = Artisan::call('atlas:open-brain:surface-review', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.open_brain.surface_review.v1', $payload['schema_version']);
        $this->assertSame('zero_removals', data_get($payload, 'policy.current_action'));
        $this->assertEqualsCanonicalizing($toolNames, data_get($payload, 'tools.*.tool_name'));

        foreach (AtlasOpenBrainMcpService::PRIMARY_TOOLS as $primaryTool) {
            $this->assertSame('keep_primary', data_get($payload, "tools_by_name.{$primaryTool}.verdict"));
        }

        $this->assertSame('keep_used', data_get($payload, "tools_by_name.{$usedCompatTool}.verdict"));
        $this->assertSame('deprecation_candidate', data_get($payload, "tools_by_name.{$candidateTool}.verdict"));
        $this->assertSame(0, data_get($payload, "tools_by_name.{$candidateTool}.usage_count"));
        $this->assertNotEmpty(data_get($payload, "tools_by_name.{$candidateTool}.window_started_at"));
    }

    public function test_surface_review_refuses_deprecation_candidate_before_minimum_window(): void
    {
        $service = app(AtlasOpenBrainMcpService::class);
        $toolNames = array_values(array_map(
            static fn (array $tool): string => (string) $tool['name'],
            $service->tools(),
        ));
        $nonPrimary = array_values(array_diff($toolNames, AtlasOpenBrainMcpService::PRIMARY_TOOLS));

        $this->assertNotEmpty($nonPrimary);

        $this->insertToolUsage('atlas_capabilities', now()->subDays(5));

        $exit = Artisan::call('atlas:open-brain:surface-review', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('insufficient_window', data_get($payload, "tools_by_name.{$nonPrimary[0]}.verdict"));
        $this->assertNotContains('deprecation_candidate', data_get($payload, 'tools.*.verdict'));
    }

    private function insertToolUsage(string $toolName, \DateTimeInterface $receivedAt): void
    {
        AiTelemetryEvent::query()->create([
            'id' => (string) Str::uuid(),
            'event_key' => 'surface-review:'.Str::uuid(),
            'surface' => 'server',
            'runtime' => 'laravel',
            'event_name' => AtlasOpenBrainMcpService::MCP_TOOL_USAGE_EVENT_NAME,
            'event_phase' => 'ok',
            'received_at' => $receivedAt,
            'created_at' => $receivedAt,
            'metadata' => [
                'tool_name' => $toolName,
                'called_at' => $receivedAt->format(DATE_ATOM),
                'status' => 'ok',
            ],
            'privacy' => ['provider_safe' => true],
            'schema_version' => 1,
        ]);
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
