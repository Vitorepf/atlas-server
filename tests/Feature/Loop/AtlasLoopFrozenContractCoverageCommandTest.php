<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the frozen-contract coverage reporter is live at the operator surface: the command emits the
 * deterministic coverage fact lists (with/without contract, with-sentinel, orphan contracts).
 */
final class AtlasLoopFrozenContractCoverageCommandTest extends TestCase
{
    public function test_frozen_contract_coverage_emits_covered_and_uncovered_lists(): void
    {
        $exit = Artisan::call('atlas:loop:frozen-contract-coverage', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($decoded);
        foreach (['with_contract', 'without_contract', 'with_sentinel', 'orphan_contracts_without_sentinel'] as $key) {
            $this->assertArrayHasKey($key, $decoded);
            $this->assertIsArray($decoded[$key]);
        }
    }
}
