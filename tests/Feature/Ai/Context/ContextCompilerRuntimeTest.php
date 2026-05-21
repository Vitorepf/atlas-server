<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasContextCompilerRuntimeService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ContextCompilerRuntimeTest extends TestCase
{
    public function test_compiler_preserves_decision_blocker_and_dod_with_full_loss_check(): void
    {
        $payload = app(AtlasContextCompilerRuntimeService::class)->compile([
            'provider' => 'local',
            'segments' => [
                ['kind' => 'decision', 'ref' => 'decision:1', 'tokens' => 3000, 'must_keep' => true],
                ['kind' => 'blocker', 'ref' => 'blocker:1', 'tokens' => 2200, 'must_keep' => true],
                ['kind' => 'dod', 'ref' => 'dod:1', 'tokens' => 1400, 'must_keep' => true],
                ['kind' => 'memory', 'ref' => 'memory:optional', 'tokens' => 8000, 'priority' => 0.1],
            ],
        ]);

        $this->assertSame(AtlasContextCompilerRuntimeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(1.0, data_get($payload, 'loss_check.must_keep_coverage'));
        $this->assertSame('passed', data_get($payload, 'loss_check.status'));
        $this->assertCount(3, array_filter(data_get($payload, 'compiled_pack.compiled_sections'), static fn (array $section): bool => (bool) $section['must_keep']));
        $this->assertSame(8000, data_get($payload, 'prompt_budget_receipt.token_savings_estimate'));
    }

    public function test_provider_profiles_change_budget_and_strategy(): void
    {
        $claude = app(AtlasContextCompilerRuntimeService::class)->compile(['provider' => 'claude']);
        $gemini = app(AtlasContextCompilerRuntimeService::class)->compile(['provider' => 'gemini']);

        $this->assertSame('claude', data_get($claude, 'provider_profile.provider'));
        $this->assertSame('gemini', data_get($gemini, 'provider_profile.provider'));
        $this->assertGreaterThan(data_get($claude, 'provider_profile.token_budget'), data_get($gemini, 'provider_profile.token_budget'));
        $this->assertNotSame(data_get($claude, 'provider_profile.format_strategy'), data_get($gemini, 'provider_profile.format_strategy'));
    }

    public function test_compiler_never_exposes_raw_segment_content(): void
    {
        $secret = 'api_key=sk-ACCRSEGREDO1234567890';
        $payload = app(AtlasContextCompilerRuntimeService::class)->compile([
            'segments' => [
                ['kind' => 'decision', 'ref' => 'decision:secret', 'content' => $secret, 'tokens' => 800, 'must_keep' => true],
            ],
        ]);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString('sk-ACCRSEGREDO', $encoded);
        $this->assertFalse(data_get($payload, 'claims.raw_text_exposed'));
    }

    public function test_hash_is_deterministic(): void
    {
        $service = app(AtlasContextCompilerRuntimeService::class);

        $first = $service->compile(['provider' => 'gpt', 'risk_level' => 'medium']);
        $second = $service->compile(['provider' => 'gpt', 'risk_level' => 'medium']);

        $this->assertSame($first['context_compiler_hash'], $second['context_compiler_hash']);
        $this->assertSame(data_get($first, 'compiled_pack.compiled_hash'), data_get($second, 'compiled_pack.compiled_hash'));
    }

    public function test_command_emits_json(): void
    {
        $exit = Artisan::call('atlas:context:compile', [
            '--provider' => 'gpt',
            '--risk' => 'low',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasContextCompilerRuntimeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('gpt', data_get($payload, 'provider_profile.provider'));
    }
}
