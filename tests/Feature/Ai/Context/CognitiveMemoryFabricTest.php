<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasCognitiveMemoryFabricService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CognitiveMemoryFabricTest extends TestCase
{
    private const GB = 1073741824;

    public function test_deep_work_budget_keeps_hot_context_and_estimates_token_savings(): void
    {
        $payload = app(AtlasCognitiveMemoryFabricService::class)->plan([
            'memory_total_bytes' => 48 * self::GB,
            'memory_available_bytes' => 30 * self::GB,
            'swap_used_bytes' => 0,
            'repeated_tokens' => 2400,
        ]);

        $this->assertSame(AtlasCognitiveMemoryFabricService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('deep_work', data_get($payload, 'budget.mode'));
        $this->assertSame(14 * self::GB, data_get($payload, 'budget.ram_budget_bytes'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'working_set.items_kept'));
        $this->assertSame(1.0, data_get($payload, 'working_set.must_keep_coverage'));
        $this->assertGreaterThanOrEqual(2400, data_get($payload, 'delta_receipt.token_savings_estimate'));
        $this->assertFalse(data_get($payload, 'claims.allocates_ram'));
    }

    public function test_below_three_gb_enters_emergency_trim_and_preserves_must_keep(): void
    {
        $payload = app(AtlasCognitiveMemoryFabricService::class)->plan([
            'memory_total_bytes' => 48 * self::GB,
            'memory_available_bytes' => 2 * self::GB,
            'swap_used_bytes' => 10 * self::GB,
            'items' => [
                ['kind' => 'decision', 'ref' => 'decision:1', 'tokens' => 500, 'bytes' => 20 * 1024 * 1024, 'must_keep' => true],
                ['kind' => 'context_pack', 'ref' => 'ctx:large', 'tokens' => 8000, 'bytes' => 4 * self::GB, 'rebuildable' => true],
            ],
        ]);

        $this->assertSame('degraded', $payload['status']);
        $this->assertSame('emergency_trim', data_get($payload, 'budget.mode'));
        $this->assertSame(0, data_get($payload, 'budget.ram_budget_bytes'));
        $this->assertTrue(data_get($payload, 'budget.emergency_trim_required'));
        $this->assertSame(1.0, data_get($payload, 'working_set.must_keep_coverage'));
        $this->assertSame(1, data_get($payload, 'working_set.items_evicted'));
        $this->assertSame('emergency_trim', data_get($payload, 'pressure_event.action'));
    }

    public function test_minimal_mode_spills_rebuildable_before_must_keep(): void
    {
        $payload = app(AtlasCognitiveMemoryFabricService::class)->plan([
            'memory_total_bytes' => 48 * self::GB,
            'memory_available_bytes' => 4 * self::GB,
            'items' => [
                ['kind' => 'blocker', 'ref' => 'blocker:1', 'tokens' => 400, 'bytes' => 10 * 1024 * 1024, 'must_keep' => true],
                ['kind' => 'summary', 'ref' => 'summary:big', 'tokens' => 5000, 'bytes' => 2 * self::GB, 'rebuildable' => true],
            ],
        ]);

        $this->assertSame('minimal', data_get($payload, 'budget.mode'));
        $this->assertSame(1 * self::GB, data_get($payload, 'budget.ram_budget_bytes'));
        $this->assertSame(1, data_get($payload, 'working_set.items_evicted'));
        $this->assertTrue(data_get($payload, 'spillover_receipt.spillover_required'));
        $this->assertSame(1.0, data_get($payload, 'working_set.must_keep_coverage'));
    }

    public function test_sensitive_item_content_is_hashed_not_exposed(): void
    {
        $secret = 'api_key=sk-ACMFSEGREDO1234567890';
        $payload = app(AtlasCognitiveMemoryFabricService::class)->plan([
            'memory_available_bytes' => 12 * self::GB,
            'items' => [
                ['kind' => 'context_pack', 'ref' => 'ctx:sensitive', 'content' => $secret, 'tokens' => 900, 'bytes' => 1024 * 1024],
            ],
        ]);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString('sk-ACMFSEGREDO', $encoded);
        $this->assertFalse(data_get($payload, 'claims.raw_text_exposed'));
        $this->assertFalse(data_get($payload, 'privacy_ref.raw_text_exposed'));
    }

    public function test_command_emits_json(): void
    {
        $exit = Artisan::call('atlas:context:cognitive-memory', [
            '--available-gb' => '16',
            '--total-gb' => '48',
            '--swap-gb' => '0',
            '--repeated-tokens' => '1200',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasCognitiveMemoryFabricService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('performance', data_get($payload, 'budget.mode'));
    }
}
