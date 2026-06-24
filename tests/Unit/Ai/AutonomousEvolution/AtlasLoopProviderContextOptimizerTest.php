<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Models\AtlasLoopDecompositionOutcome;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderContextOptimizer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasLoopProviderContextOptimizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('atlas_loop_decomposition_outcomes')) {
            (require base_path('database/migrations/2026_06_16_000100_create_atlas_loop_decomposition_outcomes_table.php'))->up();
        }

        AtlasLoopDecompositionOutcome::query()->delete();
        config()->set('atlas.loop.decomposition_corpus_enabled', true);
    }

    public function test_flag_off_returns_the_raw_unranked_baseline_byte_identical(): void
    {
        config()->set('loop.provider_context_optimizer_enabled', false);

        $optimizer = new AtlasLoopProviderContextOptimizer;
        $packet = $this->packet();
        $expected = $optimizer->rawBaselineBundle($packet);
        $actual = $optimizer->optimize($packet);

        $this->assertSame(json_encode($expected, JSON_UNESCAPED_SLASHES), json_encode($actual, JSON_UNESCAPED_SLASHES));
        $this->assertFalse($actual['enabled']);
        $this->assertSame('raw_unranked_no_read', $actual['metadata']['baseline']);
    }

    public function test_enabled_bundle_is_deterministic_ranks_symbol_hits_and_excludes_forbidden_paths(): void
    {
        config()->set('loop.provider_context_optimizer_enabled', true);

        $optimizer = new AtlasLoopProviderContextOptimizer;
        $packet = $this->packet([
            'objective' => 'Build AtlasLoopProviderContextOptimizer as the provider CONTEXT_BUNDLE shaper.',
        ]);

        $first = $optimizer->optimize($packet);
        $second = $optimizer->optimize($packet);

        $this->assertSame($first, $second);
        $this->assertTrue($first['enabled']);
        $this->assertSame(AtlasLoopProviderContextOptimizer::BUNDLE_KIND, $first['bundle_kind']);
        $this->assertSame('app/Services/Ai/AutonomousEvolution/AtlasLoopProviderContextOptimizer.php', $first['fragments'][0]['path']);
        $this->assertGreaterThan(0, $first['fragments'][0]['score_components']['symbol_overlap']);
        $this->assertNotContains('config/atlas.php', array_column($first['fragments'], 'path'));
        $this->assertFalse($first['guards']['forbidden_files_injected']);
        $this->assertFalse($first['guards']['acceptance_proposed']);
        $this->assertFalse($first['guards']['frozen_judge_inputs_altered']);
    }

    public function test_outcome_ledger_hit_rate_raises_rank_when_symbol_scores_tie(): void
    {
        config()->set('loop.provider_context_optimizer_enabled', true);
        $this->seedOutcome('router-shape', certified: true);
        $this->seedOutcome('router-shape', certified: true);
        $this->seedOutcome('router-shape', certified: false);

        $packet = $this->packet([
            'objective' => 'Shape provider context deterministically.',
            'decomposition_outcome_fingerprints' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopProviderRouter.php' => 'router-shape',
            ],
        ]);

        $bundle = (new AtlasLoopProviderContextOptimizer)->optimize($packet);

        $this->assertSame('app/Services/Ai/AutonomousEvolution/AtlasLoopProviderRouter.php', $bundle['fragments'][0]['path']);
        $this->assertSame(['certified' => 2, 'total' => 3], $bundle['fragments'][0]['score_components']['outcome_history']);
        $this->assertGreaterThan(0, $bundle['fragments'][0]['score_components']['outcome_hit_rate']);
    }

    public function test_provider_token_cap_bounds_the_emitted_bundle(): void
    {
        config()->set('loop.provider_context_optimizer_enabled', true);

        $packet = $this->packet([
            'provider' => 'tiny_provider',
            'provider_routing' => [
                'context_token_limits' => ['tiny_provider' => 32],
            ],
        ]);

        $bundle = (new AtlasLoopProviderContextOptimizer)->optimize($packet);

        $this->assertSame(256, $bundle['token_cap'], 'router clamps unsafe tiny caps to the minimum context budget');
        $this->assertLessThanOrEqual($bundle['token_cap'], $bundle['token_estimate']);
        $this->assertNotContains('config/atlas.php', array_column($bundle['fragments'], 'path'));
        foreach ($bundle['fragments'] as $fragment) {
            if (($fragment['read'] ?? false) === true) {
                $this->assertContains($fragment['path'], $packet['allowed_files']);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function packet(array $overrides = []): array
    {
        return array_replace_recursive([
            'provider' => 'default',
            'objective' => 'Build provider context for AtlasLoopProviderContextOptimizer.',
            'allowed_files' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopProviderContextOptimizer.php',
                'app/Services/Ai/AutonomousEvolution/AtlasLoopProviderRouter.php',
            ],
            'scope_in' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopProviderContextOptimizer.php',
                'app/Services/Ai/AutonomousEvolution/AtlasLoopProviderRouter.php',
                'app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php',
                'config/atlas.php',
            ],
            'forbidden_files' => ['config/atlas.php'],
            'comprehension_model' => $this->model(),
            'origination' => [
                'originated' => true,
                'objective' => 'Shape provider context before grind/verifier calls.',
                'cited_symbols' => ['AtlasLoopProviderContextOptimizer'],
            ],
        ], $overrides);
    }

    private function model(): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: [
                [
                    'rel_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopProviderContextOptimizer.php',
                    'fqcn' => 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopProviderContextOptimizer',
                    'public_methods' => ['optimize', 'rawBaselineBundle'],
                    'is_orphan' => false,
                    'is_forbidden' => false,
                    'clone_cluster_id' => null,
                ],
                [
                    'rel_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopProviderRouter.php',
                    'fqcn' => 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopProviderRouter',
                    'public_methods' => ['route', 'contextTokenLimit'],
                    'is_orphan' => false,
                    'is_forbidden' => false,
                    'clone_cluster_id' => null,
                ],
            ],
            edges: [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopProviderContextOptimizer.php' => [
                    'app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php',
                ],
                'app/Services/Ai/AutonomousEvolution/AtlasLoopProviderRouter.php' => [
                    'app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php',
                ],
            ],
            orphans: [],
            cloneClusters: [],
            forbidden: ['config/atlas.php'],
            docPurposes: [
                'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopProviderContextOptimizer' => 'Builds CONTEXT_BUNDLE slices for provider prompts.',
            ],
            docStatedGaps: [],
            snapshotId: 'provider-context-snapshot',
        );
    }

    private function seedOutcome(string $fingerprintHash, bool $certified): void
    {
        AtlasLoopDecompositionOutcome::query()->create([
            'fingerprint_hash' => $fingerprintHash,
            'objective_kind' => 'provider_context',
            'node_count' => 2,
            'certified' => $certified,
            'thrashed' => ! $certified,
            'terminal_reason' => $certified ? 'certified' : 'not_certified',
            'rounds' => 1,
        ]);
    }
}
