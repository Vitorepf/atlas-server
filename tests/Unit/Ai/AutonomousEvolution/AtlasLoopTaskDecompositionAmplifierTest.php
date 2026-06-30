<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Models\AtlasLoopDecompositionOutcome;
use App\Services\Ai\AutonomousEvolution\AtlasLoopScenarioProviderPortfolio;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTaskDecompositionAmplifier;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasLoopTaskDecompositionAmplifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('atlas_loop_decomposition_outcomes')) {
            (require base_path('database/migrations/2026_06_16_000100_create_atlas_loop_decomposition_outcomes_table.php'))->up();
        }

        AtlasLoopDecompositionOutcome::query()->delete();
    }

    public function test_flag_off_returns_single_baseline_shape_without_mutating_packet(): void
    {
        config()->set('atlas.loop.decomposition_amplifier_enabled', false);
        config()->set('atlas.loop.decomposition_corpus_enabled', true);
        $packet = $this->packet();
        $checksum = $this->guardedChecksum($packet);

        $variants = (new AtlasLoopTaskDecompositionAmplifier)->amplify($packet);

        $this->assertCount(1, $variants);
        $this->assertSame('baseline_single_node', $variants[0]['shape_key']);
        $this->assertFalse((bool) $variants[0]['enabled']);
        $this->assertSame(['certified' => 0, 'total' => 0, 'win_rate' => 0.0], $variants[0]['history']);
        $this->assertSame($checksum, $this->guardedChecksum($packet));
    }

    public function test_flag_on_emits_at_least_three_distinct_fingerprints_without_mutating_governed_inputs(): void
    {
        config()->set('atlas.loop.decomposition_amplifier_enabled', true);
        config()->set('atlas.loop.decomposition_corpus_enabled', true);
        $packet = $this->packet();
        $checksum = $this->guardedChecksum($packet);

        $variants = (new AtlasLoopTaskDecompositionAmplifier)->amplify($packet);
        $fingerprints = array_column($variants, 'fingerprint');

        $this->assertGreaterThanOrEqual(3, count($variants));
        $this->assertSame($fingerprints, array_values(array_unique($fingerprints)));
        $this->assertCount(count($variants), array_unique($fingerprints));
        $this->assertSame($checksum, $this->guardedChecksum($packet));
        $this->assertNotContains('', $fingerprints);
    }

    public function test_variants_are_ranked_by_wilson_lower_bound_then_fingerprint_tie_break(): void
    {
        config()->set('atlas.loop.decomposition_amplifier_enabled', true);
        config()->set('atlas.loop.decomposition_corpus_enabled', true);
        $packet = $this->packet(['objective_kind' => 'refactor_extract_class']);
        $amplifier = new AtlasLoopTaskDecompositionAmplifier;
        $fingerprints = array_column($amplifier->amplify($packet), 'fingerprint');
        sort($fingerprints, SORT_STRING);
        [$tieA, $tieB, $winner] = array_slice($fingerprints, 0, 3);

        $this->seedHistory($winner, 'refactor_extract_class', certified: 40, total: 50);
        $this->seedHistory($tieA, 'refactor_extract_class', certified: 10, total: 20);
        $this->seedHistory($tieB, 'refactor_extract_class', certified: 10, total: 20);
        $this->seedHistory($tieB, 'unrelated_family', certified: 10, total: 10);

        $ranked = $amplifier->amplify($packet);
        $positions = array_flip(array_column($ranked, 'fingerprint'));

        $this->assertSame($winner, $ranked[0]['fingerprint']);
        $this->assertLessThan($positions[$tieB], $positions[$tieA]);
        $this->assertSame(0.5, $ranked[$positions[$tieA]]['history']['win_rate']);
        $this->assertSame(0.5, $ranked[$positions[$tieB]]['history']['win_rate']);
    }

    public function test_proven_shape_ranks_above_small_sample_fluke(): void
    {
        config()->set('atlas.loop.decomposition_amplifier_enabled', true);
        config()->set('atlas.loop.decomposition_corpus_enabled', true);
        $packet = $this->packet(['objective_kind' => 'refactor_extract_class']);
        $amplifier = new AtlasLoopTaskDecompositionAmplifier;
        $fingerprints = array_column($amplifier->amplify($packet), 'fingerprint');
        sort($fingerprints, SORT_STRING);
        [$fluke, $proven] = array_slice($fingerprints, 0, 2);

        $this->seedHistory($fluke, 'refactor_extract_class', certified: 1, total: 1);
        $this->seedHistory($proven, 'refactor_extract_class', certified: 80, total: 100);

        $ranked = $amplifier->amplify($packet);
        $positions = array_flip(array_column($ranked, 'fingerprint'));

        $this->assertLessThan($positions[$fluke], $positions[$proven], '80/100 proven shape must rank above 1/1 fluke');
        $this->assertGreaterThan(0.0, $ranked[$positions[$proven]]['prior']['lower_bound']);
        $this->assertSame(0.0, $ranked[$positions[$fluke]]['prior']['lower_bound']);
    }

    public function test_portfolio_exposes_attempt_indexed_decomposition_rotation(): void
    {
        config()->set('atlas.loop.decomposition_amplifier_enabled', true);
        config()->set('atlas.loop.decomposition_corpus_enabled', false);
        $portfolio = new AtlasLoopScenarioProviderPortfolio(new AtlasLoopTaskDecompositionAmplifier);
        $task = $this->packet(['scenario_providers' => ['codex', 'claude']]);

        $decompositions = $portfolio->decompositionsFor($task);

        $this->assertGreaterThanOrEqual(3, count($decompositions));
        $this->assertSame('codex', $portfolio->providerFor($task, 0, 'default'));
        $this->assertSame('claude', $portfolio->providerFor($task, 1, 'default'));
        $this->assertSame($decompositions[0]['fingerprint'], $portfolio->decompositionFor($task, 0)['fingerprint']);
        $this->assertSame($decompositions[0]['fingerprint'], $portfolio->decompositionFor($task, count($decompositions))['fingerprint']);
    }

    public function test_every_variant_has_graph_ladder_with_required_keys(): void
    {
        config()->set('atlas.loop.decomposition_amplifier_enabled', true);
        config()->set('atlas.loop.decomposition_corpus_enabled', false);

        $variants = (new AtlasLoopTaskDecompositionAmplifier)->amplify($this->packet());

        $this->assertGreaterThanOrEqual(1, count($variants));
        foreach ($variants as $v) {
            $this->assertArrayHasKey('graph_ladder', $v);
            $gl = $v['graph_ladder'];
            $this->assertArrayHasKey('unlock_edges', $gl);
            $this->assertArrayHasKey('proof_node_ids', $gl);
            $this->assertArrayHasKey('risk_notes', $gl);
            $this->assertArrayHasKey('recommended_for', $gl);
            $this->assertIsArray($gl['unlock_edges']);
            $this->assertIsArray($gl['proof_node_ids']);
            $this->assertNotEmpty($gl['risk_notes']);
            $this->assertNotEmpty($gl['recommended_for']);
        }
    }

    public function test_proof_first_chain_ladder_has_correct_unlock_edge_and_proof_node(): void
    {
        config()->set('atlas.loop.decomposition_amplifier_enabled', true);
        config()->set('atlas.loop.decomposition_corpus_enabled', false);

        $variants = (new AtlasLoopTaskDecompositionAmplifier)->amplify($this->packet());
        $proofFirst = array_values(array_filter($variants, fn (array $v): bool => $v['shape_key'] === 'proof_first_chain'));

        $this->assertCount(1, $proofFirst, 'proof_first_chain shape must be emitted');
        $gl = $proofFirst[0]['graph_ladder'];

        // proof → implementation unlock edge.
        $edges = $gl['unlock_edges'];
        $proofEdge = array_values(array_filter($edges, fn (array $e): bool => $e['from'] === 'proof' && $e['to'] === 'implementation'));
        $this->assertCount(1, $proofEdge, 'proof_first_chain must have a proof→implementation unlock edge');

        // 'proof' node must be in proof_node_ids.
        $this->assertContains('proof', $gl['proof_node_ids']);

        // recommended_for is non-empty string, risk_notes mentions proof/test risk.
        $this->assertStringContainsStringIgnoringCase('macro', $gl['recommended_for']);
        $this->assertNotEmpty($gl['risk_notes']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function packet(array $overrides = []): array
    {
        return $overrides + [
            'task_packet_id' => 'loop-w7-p5-task-decomposition-amplifier',
            'objective_kind' => 'refactor_extract_class',
            'objective' => 'Build a deterministic task decomposition amplifier.',
            'allowed_files' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopTaskDecompositionAmplifier.php',
                'app/Services/Ai/AutonomousEvolution/AtlasLoopScenarioProviderPortfolio.php',
                'tests/Unit/Ai/AutonomousEvolution/AtlasLoopTaskDecompositionAmplifierTest.php',
            ],
            'acceptance' => [
                'metric_kind' => 'gate',
                'commands' => ['vendor/bin/phpunit tests/Unit/Ai/AutonomousEvolution/AtlasLoopTaskDecompositionAmplifierTest.php'],
            ],
            'acceptance_criteria' => ['do not mutate acceptance'],
            'frozen_judge_inputs' => ['judge' => 'frozen'],
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    private function guardedChecksum(array $packet): string
    {
        return hash('sha256', json_encode([
            'allowed_files' => $packet['allowed_files'] ?? null,
            'acceptance' => $packet['acceptance'] ?? null,
            'acceptance_criteria' => $packet['acceptance_criteria'] ?? null,
            'frozen_judge_inputs' => $packet['frozen_judge_inputs'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function seedHistory(string $fingerprint, string $objectiveKind, int $certified, int $total): void
    {
        for ($i = 0; $i < $total; $i++) {
            $isCertified = $i < $certified;
            AtlasLoopDecompositionOutcome::query()->create([
                'fingerprint_hash' => $fingerprint,
                'objective_kind' => $objectiveKind,
                'node_count' => 1,
                'certified' => $isCertified,
                'thrashed' => ! $isCertified,
                'terminal_reason' => $isCertified ? 'certified' : 'not_certified',
                'rounds' => 1,
            ]);
        }
    }
}
