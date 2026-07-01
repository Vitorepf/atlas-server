<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves AtlasExternalBrainCapabilityGapTaskChainCompiler is wired into
 * atlas:external-brain:autonomy-governor, analogous to how
 * AtlasExternalBrainBacklogCostModel is already wired there.
 */
final class AtlasExternalBrainCapabilityGapTaskChainCompilerWiringWiredTest extends TestCase
{
    private string $inputPath;

    protected function tearDown(): void
    {
        if (isset($this->inputPath) && is_file($this->inputPath)) {
            unlink($this->inputPath);
        }
        parent::tearDown();
    }

    private function callCommand(array $payload): array
    {
        $this->inputPath = tempnam(sys_get_temp_dir(), 'capability_gap_chain_wiring_').'.json';
        file_put_contents($this->inputPath, (string) json_encode($payload));

        Artisan::call('atlas:external-brain:autonomy-governor', ['--input' => $this->inputPath]);
        $raw = trim(Artisan::output());
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Command output is not valid JSON:\n{$raw}");

        return $decoded;
    }

    public function test_gap_blockers_compile_into_ordered_task_chain(): void
    {
        $decoded = $this->callCommand([
            'capability_gaps' => [
                [
                    'gap_id' => 'gap-1',
                    'blockers' => [
                        ['type' => 'no_runtime_integration'],
                        ['type' => 'missing_context'],
                    ],
                ],
            ],
        ]);

        $this->assertArrayHasKey('capability_gap_task_chain', $decoded);
        $chain = $decoded['capability_gap_task_chain'];
        $this->assertSame('atlas.self_construction.external_brain.capability_gap_task_chain_compiler.v1', $chain['schema']);
        $this->assertCount(2, $chain['chain']);
        $this->assertSame('missing_context', $chain['chain'][0]['blocker_type']);
        $this->assertSame('no_runtime_integration', $chain['chain'][1]['blocker_type']);
        $this->assertSame([$chain['chain'][0]['task_id']], $chain['chain'][1]['dependency_ids']);
    }

    public function test_empty_capability_gaps_produces_empty_chain(): void
    {
        $decoded = $this->callCommand([]);

        $this->assertSame([], $decoded['capability_gap_task_chain']['chain']);
        $this->assertSame([], $decoded['capability_gap_task_chain']['gap_chains']);
    }
}
