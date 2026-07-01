<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves AtlasExternalBrainComprehensionDeepeningMap is wired into
 * atlas:external-brain:capability-proof-map, analogous to how
 * AtlasExternalBrainAreaImpactLedger is already wired there.
 */
final class AtlasExternalBrainComprehensionDeepeningMapWiringWiredTest extends TestCase
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
        $this->inputPath = tempnam(sys_get_temp_dir(), 'comprehension_deepening_wiring_').'.json';
        file_put_contents($this->inputPath, (string) json_encode($payload));

        Artisan::call('atlas:external-brain:capability-proof-map', ['--input' => $this->inputPath]);
        $raw = trim(Artisan::output());
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Command output is not valid JSON:\n{$raw}");

        return $decoded;
    }

    public function test_shallow_context_domain_blocks_its_architecture_target(): void
    {
        $decoded = $this->callCommand([
            'comprehension_domains' => [
                ['domain_id' => 'marketing', 'has_owner_docs' => false, 'unresolved_contradictions' => 1, 'recent_failed_assumptions' => 1],
            ],
            'comprehension_architecture_targets' => ['marketing'],
        ]);

        $this->assertArrayHasKey('comprehension_deepening_map', $decoded);
        $map = $decoded['comprehension_deepening_map'];
        $this->assertSame('atlas.external_brain.comprehension_deepening_map.v1', $map['schema_version']);
        $this->assertSame(['marketing'], $map['blocked_architecture_targets']);
        $this->assertSame('shallow_context', $map['ranked_domains'][0]['context_level']);
    }

    public function test_gap_rankings_are_included_and_investigate_decision_is_reachable(): void
    {
        $decoded = $this->callCommand([
            'comprehension_gaps' => [
                ['gap_id' => 'g1', 'missing_context' => 'billing_edge_cases', 'impact_on_quality' => 0.8, 'impact_on_autonomy' => 0.8],
            ],
        ]);

        $this->assertArrayHasKey('comprehension_gap_rankings', $decoded);
        $ranked = $decoded['comprehension_gap_rankings']['ranked_gaps'];
        $this->assertSame('investigate', $ranked[0]['decision']);
    }

    public function test_empty_sections_produce_empty_comprehension_output(): void
    {
        $decoded = $this->callCommand([]);

        $this->assertSame([], $decoded['comprehension_deepening_map']['ranked_domains']);
        $this->assertSame([], $decoded['comprehension_gap_rankings']['ranked_gaps']);
    }
}
