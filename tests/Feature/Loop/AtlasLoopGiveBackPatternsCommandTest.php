<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the give-back pattern miner is live at the operator surface: the command emits the deterministic
 * clustered give-back pattern facts (top reasons, rate by class, top reason by class, worker concentration).
 */
final class AtlasLoopGiveBackPatternsCommandTest extends TestCase
{
    public function test_give_back_patterns_emits_clustered_facts(): void
    {
        $exit = Artisan::call('atlas:loop:give-back-patterns', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.give_back_patterns.v1', $decoded['schema_version']);
        $this->assertIsArray($decoded['patterns']);
        foreach (['top_reasons', 'give_back_rate_by_class', 'top_reason_by_class', 'worker_concentration'] as $key) {
            $this->assertArrayHasKey($key, $decoded['patterns']);
        }
    }
}
