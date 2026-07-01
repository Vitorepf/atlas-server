<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves AtlasExternalBrainAreaImpactLedger is wired into
 * AtlasExternalBrainCapabilityProofMapCommand: it is called once over the
 * full area_impact_samples batch and its result appears in the command's
 * JSON output.
 */
final class AtlasExternalBrainAreaImpactLedgerWiringWiredTest extends TestCase
{
    private function callCommand(array $payload): array
    {
        $path = tempnam(sys_get_temp_dir(), 'capability_proof_map_area_impact_').'.json';
        file_put_contents($path, (string) json_encode($payload));

        try {
            Artisan::call('atlas:external-brain:capability-proof-map', ['--input' => $path]);

            return (array) json_decode(trim(Artisan::output()), true);
        } finally {
            @unlink($path);
        }
    }

    public function test_area_impact_ledger_key_present_and_aggregates_samples(): void
    {
        $decoded = $this->callCommand([
            'area_impact_samples' => [
                ['area' => 'foo', 'value_class' => 'real_capability', 'integration_evidence' => true, 'task_count' => 2],
            ],
        ]);

        $this->assertArrayHasKey('area_impact_ledger', $decoded);
        $ledger = $decoded['area_impact_ledger'];
        $this->assertSame('atlas.external_brain.area_impact_ledger.v1', $ledger['schema']);
        $this->assertSame(1, $ledger['area_count']);
        $this->assertSame(2, $ledger['areas']['foo']['capability_gain']);
    }

    public function test_empty_area_impact_samples_yields_empty_ledger(): void
    {
        $decoded = $this->callCommand([]);

        $ledger = $decoded['area_impact_ledger'];
        $this->assertSame(0, $ledger['area_count']);
        $this->assertSame(0, $ledger['sample_count']);
    }
}
