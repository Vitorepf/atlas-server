<?php

namespace Tests\Unit;

use App\Models\AiProviderHealthSnapshot;
use App\Services\Ai\Cli\AtlasCliProviderStrategyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasCliProviderStrategyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ai_provider_health_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider');
            $table->string('status');
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->integer('total_jobs_24h')->default(0);
            $table->integer('failed_jobs_24h')->default(0);
            $table->integer('p50_latency_ms')->nullable();
            $table->smallInteger('operational_pain_score')->default(0);
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_provider_health_snapshots');

        parent::tearDown();
    }

    public function test_recommends_available_provider_for_dev_mode(): void
    {
        AiProviderHealthSnapshot::query()->create([
            'provider' => 'codex_cli',
            'status' => 'offline',
            'checked_at' => now(),
            'operational_pain_score' => 4,
            'metadata' => [],
            'created_at' => now(),
        ]);
        AiProviderHealthSnapshot::query()->create([
            'provider' => 'claude_cli',
            'status' => 'online',
            'checked_at' => now(),
            'operational_pain_score' => 0,
            'p50_latency_ms' => 100,
            'metadata' => [],
            'created_at' => now(),
        ]);

        $payload = app(AtlasCliProviderStrategyService::class)->recommend('dev');

        $this->assertSame('claude_cli', $payload['recommended_provider']);
        $this->assertSame('codex_cli', $payload['fallback_provider']);
    }

    public function test_critical_mode_uses_council_when_claude_and_codex_are_online(): void
    {
        AiProviderHealthSnapshot::query()->create([
            'provider' => 'codex_cli',
            'status' => 'online',
            'checked_at' => now(),
            'operational_pain_score' => 0,
            'metadata' => [],
            'created_at' => now(),
        ]);
        AiProviderHealthSnapshot::query()->create([
            'provider' => 'claude_cli',
            'status' => 'online',
            'checked_at' => now(),
            'operational_pain_score' => 0,
            'metadata' => [],
            'created_at' => now(),
        ]);

        $payload = app(AtlasCliProviderStrategyService::class)->recommend('review', critical: true);

        $this->assertSame('claude_codex', $payload['recommended_provider']);
    }
}
