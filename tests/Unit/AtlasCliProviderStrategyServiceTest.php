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
        $this->assertNull($payload['fallback_provider']);
    }

    public function test_critical_mode_uses_council_when_claude_and_codex_are_online(): void
    {
        config()->set('atlas.ai.council_allow_auto', true);
        config()->set('atlas.ai.providers.codex_cli.allow_auto', true);

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

    public function test_dev_mode_never_recommends_gemini_even_when_default_and_online(): void
    {
        config()->set('atlas.ai.default_provider', 'gemini_cli');

        AiProviderHealthSnapshot::query()->create([
            'provider' => 'gemini_cli',
            'status' => 'online',
            'checked_at' => now(),
            'operational_pain_score' => 0,
            'p50_latency_ms' => 10,
            'metadata' => [],
            'created_at' => now(),
        ]);
        AiProviderHealthSnapshot::query()->create([
            'provider' => 'codex_cli',
            'status' => 'online',
            'checked_at' => now(),
            'operational_pain_score' => 1,
            'p50_latency_ms' => 100,
            'metadata' => [],
            'created_at' => now(),
        ]);
        AiProviderHealthSnapshot::query()->create([
            'provider' => 'claude_cli',
            'status' => 'offline',
            'checked_at' => now(),
            'operational_pain_score' => 4,
            'metadata' => [],
            'created_at' => now(),
        ]);

        $payload = app(AtlasCliProviderStrategyService::class)->recommend('dev');

        $this->assertSame('claude_cli', $payload['recommended_provider']);
    }

    public function test_dev_mode_falls_back_to_hermes_not_gemini_when_gemini_is_the_only_online_provider(): void
    {
        config()->set('atlas.ai.default_provider', 'gemini_cli');

        AiProviderHealthSnapshot::query()->create([
            'provider' => 'gemini_cli',
            'status' => 'online',
            'checked_at' => now(),
            'operational_pain_score' => 0,
            'p50_latency_ms' => 10,
            'metadata' => [],
            'created_at' => now(),
        ]);

        $payload = app(AtlasCliProviderStrategyService::class)->recommend('debug');

        $this->assertSame('hermes_cli', $payload['recommended_provider']);
        $this->assertNull($payload['fallback_provider']);
    }

    public function test_reason_explains_when_default_provider_is_blocked_for_automatic_use(): void
    {
        config()->set('atlas.ai.default_provider', 'codex_cli');
        config()->set('atlas.ai.providers.codex_cli.allow_auto', false);
        config()->set('atlas.ai.providers.claude_cli.allow_auto', true);

        AiProviderHealthSnapshot::query()->create([
            'provider' => 'codex_cli',
            'status' => 'online',
            'checked_at' => now(),
            'operational_pain_score' => 0,
            'p50_latency_ms' => 50,
            'metadata' => [],
            'created_at' => now(),
        ]);
        AiProviderHealthSnapshot::query()->create([
            'provider' => 'claude_cli',
            'status' => 'online',
            'checked_at' => now(),
            'operational_pain_score' => 1,
            'p50_latency_ms' => 100,
            'metadata' => [],
            'created_at' => now(),
        ]);

        $payload = app(AtlasCliProviderStrategyService::class)->recommend('dev');
        $codex = collect($payload['providers'])->firstWhere('provider', 'codex_cli');

        $this->assertSame('claude_cli', $payload['recommended_provider']);
        $this->assertStringContainsString('Default codex_cli esta bloqueado para automatico', $payload['reason']);
        $this->assertFalse(data_get($codex, 'allow_auto'));
        $this->assertTrue(data_get($codex, 'allow_manual'));
    }
}
