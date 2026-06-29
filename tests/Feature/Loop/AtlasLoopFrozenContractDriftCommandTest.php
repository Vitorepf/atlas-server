<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the frozen-contract drift detector is live at the operator surface: the command emits the deterministic
 * per-contract drift facts (count + records) over the frozen-contract registry.
 */
final class AtlasLoopFrozenContractDriftCommandTest extends TestCase
{
    public function test_frozen_contract_drift_emits_contract_facts(): void
    {
        $exit = Artisan::call('atlas:loop:frozen-contract-drift', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.frozen_contract_drift.v1', $decoded['schema_version']);
        $this->assertIsArray($decoded['contracts']);
        $this->assertSame(count($decoded['contracts']), $decoded['contracts_count']);

        if ($decoded['contracts'] !== []) {
            foreach (['fqcn', 'test_path', 'expected_sha', 'actual_sha', 'drift'] as $key) {
                $this->assertArrayHasKey($key, $decoded['contracts'][0]);
            }
        }
    }
}
