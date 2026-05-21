<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasTokenEconomyRuntimeService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class TokenEconomyRuntimeTest extends TestCase
{
    public function test_low_risk_context_reduces_tokens_with_quality_gate_passed(): void
    {
        $payload = app(AtlasTokenEconomyRuntimeService::class)->optimize([
            'provider' => 'gpt',
            'risk_level' => 'low',
        ]);

        $this->assertSame(AtlasTokenEconomyRuntimeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('passed', data_get($payload, 'quality_check.quality_gate_status'));
        $this->assertSame(1.0, data_get($payload, 'quality_check.must_keep_coverage'));
        $this->assertGreaterThan(0, data_get($payload, 'compression_receipt.savings_estimate'));
        $this->assertLessThan(data_get($payload, 'compression_receipt.input_tokens_before'), data_get($payload, 'compression_receipt.input_tokens_after'));
        $this->assertFalse(data_get($payload, 'claims.providers_invoked'));
    }

    public function test_must_keep_loss_blocks_token_savings(): void
    {
        $payload = app(AtlasTokenEconomyRuntimeService::class)->optimize([
            'provider' => 'gpt',
            'risk_level' => 'low',
            'must_keep_coverage' => 0.99,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked', data_get($payload, 'quality_check.quality_gate_status'));
        $this->assertContains('must_keep_coverage_below_one', data_get($payload, 'quality_check.blockers'));
    }

    public function test_high_risk_does_not_select_cheap_local_provider(): void
    {
        $payload = app(AtlasTokenEconomyRuntimeService::class)->optimize([
            'provider' => 'local',
            'risk_level' => 'high',
        ]);

        $this->assertSame('ready', $payload['status']);
        $this->assertSame('gpt', data_get($payload, 'provider_model_selection.selected_provider'));
        $this->assertTrue(data_get($payload, 'provider_model_selection.cheap_provider_blocked_by_risk'));
    }

    public function test_local_prereasoning_can_avoid_provider_for_simple_tasks(): void
    {
        $payload = app(AtlasTokenEconomyRuntimeService::class)->optimize([
            'provider' => 'gpt',
            'risk_level' => 'low',
            'task_type' => 'count',
        ]);

        $this->assertTrue(data_get($payload, 'local_prereasoning.can_resolve_locally'));
        $this->assertTrue(data_get($payload, 'local_prereasoning.provider_call_avoidable'));
        $this->assertSame('none_local_only', data_get($payload, 'provider_model_selection.selected_provider'));
    }

    public function test_hash_is_deterministic(): void
    {
        $service = app(AtlasTokenEconomyRuntimeService::class);

        $first = $service->optimize(['provider' => 'gpt', 'risk_level' => 'medium']);
        $second = $service->optimize(['provider' => 'gpt', 'risk_level' => 'medium']);

        $this->assertSame($first['token_economy_hash'], $second['token_economy_hash']);
        $this->assertSame(data_get($first, 'quality_check.receipt_hash'), data_get($second, 'quality_check.receipt_hash'));
    }

    public function test_command_emits_json(): void
    {
        $exit = Artisan::call('atlas:context:token-economy', [
            '--provider' => 'gpt',
            '--risk' => 'low',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasTokenEconomyRuntimeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
    }
}
