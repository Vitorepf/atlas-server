<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the contract-gap scanner is live at the operator surface: the command globs the AutonomousEvolution
 * tree and emits the deterministic capability-contract gap facts (count + per-interface records).
 */
final class AtlasLoopContractGapScanCommandTest extends TestCase
{
    public function test_contract_gap_scan_emits_capability_gap_facts(): void
    {
        $exit = Artisan::call('atlas:loop:contract-gap-scan', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.contract_gap_scan.v1', $decoded['schema_version']);
        $this->assertIsArray($decoded['gaps']);
        $this->assertSame(count($decoded['gaps']), $decoded['gaps_count']);

        if ($decoded['gaps'] !== []) {
            foreach (['fqcn', 'file', 'methods', 'implementer_count'] as $key) {
                $this->assertArrayHasKey($key, $decoded['gaps'][0]);
            }
        }
    }
}
