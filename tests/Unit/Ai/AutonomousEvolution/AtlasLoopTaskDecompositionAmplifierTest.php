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

    public function test_variants_are_ranked_by_same_family_win_rate_then_fingerprint_tie_break(): void
    {
        config()->set('atlas.loop.decomposition_amplifier_enabled', true);
        config()->set('atlas.loop.decomposition_corpus_enabled', true);
        $packet = $this->packet(['objective_kind' => 'refactor_extract_class']);
        $amplifier = new AtlasLoopTaskDecompositionAmplifier;
        $fingerprints = array_column($amplifier->amplify($packet), 'fingerprint');
        sort($fingerprints, SORT_STRING);
        [$tieA, $tieB, $winner] = array_slice($fingerprints, 0, 3);

        $this->seedHistory($winner, 'refactor_extract_class', certified: 5, total: 5);
        $this->seedHistory($tieA, 'refactor_extract_class', certified: 1, total: 2);
        $this->seedHistory($tieB, 'refactor_extract_class', certified: 1, total: 2);
        $this->seedHistory($tieB, 'unrelated_family', certified: 10, total: 10);

        $ranked = $amplifier->amplify($packet);
        $positions = array_flip(array_column($ranked, 'fingerprint'));

        $this->assertSame($winner, $ranked[0]['fingerprint']);
        $this->assertLessThan($positions[$tieB], $positions[$tieA]);
        $this->assertSame(0.5, $ranked[$positions[$tieA]]['history']['win_rate']);
        $this->assertSame(0.5, $ranked[$positions[$tieB]]['history']['win_rate']);
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
