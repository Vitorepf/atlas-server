<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasTokenEconomyRuntimeService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class TokenEconomyShadowParityTest extends TestCase
{
    public function test_must_keep_allocator_default_on_does_not_change_payload_or_hash_and_writes_shadow_jsonl(): void
    {
        Storage::fake('local');
        config([
            'atlas.context_budget.must_keep_allocator_enabled' => false,
            'atlas.context_budget.must_keep_allocator_shadow_disk' => 'local',
            'atlas.context_budget.must_keep_allocator_shadow_path' => 'atlas/context-budget/must-keep-shadow.jsonl',
        ]);

        $input = [
            'provider' => 'local',
            'risk_level' => 'low',
            'segments' => [
                ['kind' => 'decision', 'ref' => 'decision:keep', 'tokens' => 4200, 'priority' => 1.0, 'must_keep' => true],
                ['kind' => 'blocker', 'ref' => 'blocker:keep', 'tokens' => 2600, 'priority' => 0.98, 'must_keep' => true],
                ['kind' => 'memory', 'ref' => 'memory:optional', 'tokens' => 1600, 'priority' => 0.5, 'must_keep' => false],
            ],
        ];

        $service = app(AtlasTokenEconomyRuntimeService::class);
        $off = $service->optimize($input);

        config(['atlas.context_budget.must_keep_allocator_enabled' => true]);
        $on = $service->optimize($input);

        $this->assertSame($off['token_economy_hash'], $on['token_economy_hash']);
        $this->assertArrayNotHasKey('must_keep_budget_allocation', $on);
        $this->assertSame($this->stablePayload($off), $this->stablePayload($on));

        Storage::disk('local')->assertExists('atlas/context-budget/must-keep-shadow.jsonl');
        $lines = array_values(array_filter(explode("\n", Storage::disk('local')->get('atlas/context-budget/must-keep-shadow.jsonl'))));
        $this->assertCount(1, $lines);

        $shadow = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.context.must_keep_budget_allocation.shadow.v1', $shadow['schema_version']);
        $this->assertSame($on['token_economy_hash'], $shadow['token_economy_hash']);
        $this->assertSame('atlas.context.must_keep_budget_allocation.v1', data_get($shadow, 'allocation.schema_version'));
        $this->assertLessThan(1.0, data_get($shadow, 'allocation.must_keep_coverage'));
        $this->assertNotEmpty(data_get($shadow, 'allocation.blockers'));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stablePayload(array $payload): array
    {
        unset($payload['generated_at']);

        return $payload;
    }
}
