<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves AtlasExternalBrainAmplifierOutcomeReplayRouter is wired into
 * AtlasExternalBrainLearningCompletenessCommand: it is called once over the
 * full outcomes batch and its result appears in the command's JSON output.
 */
final class AtlasExternalBrainAmplifierOutcomeReplayRouterWiringWiredTest extends TestCase
{
    private function callCommand(array $payload): array
    {
        $path = tempnam(sys_get_temp_dir(), 'learning_completeness_amplifier_').'.json';
        file_put_contents($path, (string) json_encode($payload));

        try {
            Artisan::call('atlas:external-brain:learning-completeness', ['--input' => $path]);

            return (array) json_decode(trim(Artisan::output()), true);
        } finally {
            @unlink($path);
        }
    }

    public function test_amplifier_outcome_routes_key_present_and_routes_high_evidence_outcome(): void
    {
        $decoded = $this->callCommand([
            'cycles' => [],
            'worker_notes' => [],
            'outcomes' => [
                [
                    'outcome_id' => 'o1',
                    'outcome_type' => 'commit_success',
                    'scaffold_variant' => 'v1',
                    'model_tier' => 'small',
                    'evidence_count' => 5,
                ],
            ],
        ]);

        $this->assertArrayHasKey('amplifier_outcome_routes', $decoded);
        $routes = $decoded['amplifier_outcome_routes'];
        $this->assertSame('atlas.external_brain.amplifier_outcome_replay_router.v1', $routes['schema_version']);
        $this->assertCount(1, $routes['routed_updates']);
        $this->assertSame('o1', $routes['routed_updates'][0]['outcome_id']);
        $this->assertSame(['scaffold_selection', 'model_tier_routing'], $routes['routed_updates'][0]['sinks']);
    }

    public function test_low_evidence_outcome_is_ignored_by_amplifier_router(): void
    {
        $decoded = $this->callCommand([
            'outcomes' => [
                ['outcome_id' => 'o2', 'outcome_type' => 'commit_success', 'evidence_count' => 1],
            ],
        ]);

        $routes = $decoded['amplifier_outcome_routes'];
        $this->assertSame([], $routes['routed_updates']);
        $this->assertSame('low_evidence', $routes['ignored_outcomes'][0]['reason']);
    }

    public function test_empty_outcomes_yields_empty_amplifier_routes(): void
    {
        $decoded = $this->callCommand([]);

        $routes = $decoded['amplifier_outcome_routes'];
        $this->assertSame([], $routes['routed_updates']);
        $this->assertSame([], $routes['ignored_outcomes']);
    }
}
