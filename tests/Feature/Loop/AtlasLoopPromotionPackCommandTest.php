<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the runtime-promotion closure execution pack builder is live at the operator surface and emits a
 * deterministic, strictly read-only pack: it carries the ordered operator steps and persistence preflight,
 * and every execution guarantee is OFF (build only, executes nothing, persists no receipt).
 */
final class AtlasLoopPromotionPackCommandTest extends TestCase
{
    public function test_builds_a_read_only_promotion_pack(): void
    {
        $exit = Artisan::call('atlas:loop:promotion-pack', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction.runtime_promotion_closure_execution_pack.v1', $decoded['schema_version']);
        $this->assertSame('read_only_runtime_promotion_closure_execution_pack', $decoded['mode']);

        // build only — every execution guarantee is OFF
        $this->assertFalse($decoded['execution_allowed']);
        $this->assertFalse($decoded['ledger_write_allowed']);
        $this->assertFalse($decoded['token_spend_allowed']);

        $this->assertIsArray($decoded['ordered_operator_steps']);
        $this->assertArrayHasKey('receipt_persistence_preflight', $decoded);
    }
}
