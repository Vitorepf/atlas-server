<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopModelProjectionCritic;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionWorker;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use ReflectionMethod;
use Tests\TestCase;

/**
 * P5 (MITIGA, NÃO ELIMINA) — wire AtlasLoopProjectionWorker's grounded critique to the critic's MULTI-LENS
 * depth (lensedObligations) behind a default-OFF flag. Proves: flag OFF ⇒ the single-pass additionalObligations
 * as before (1 critic pass); flag ON ⇒ one pass PER configured lens; lenses are env-configurable and fall back
 * to DEFAULT_LENSES. The per-lens passes are counted via a stub completion injected into the critic (NO real
 * provider). Depth ≠ independence: the model stays single-provider — true cross-model is the open residual.
 */
final class AtlasLoopProjectionWorkerLensedCritiqueTest extends TestCase
{
    /** Each invocation of the injected completion (one per lens) is recorded. */
    private array $passPrompts = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The grounded phase + the cross-model critic must be ON for the worker to call the critic at all.
        config(['atlas.loop.grounded_projection_enabled' => true]);
        config(['atlas.loop.grounded_projection_model_critic_enabled' => true]);
        $this->passPrompts = [];
    }

    private function scopeModel(): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: [[
                'rel_path' => 'app/Foo/Bar.php',
                'fqcn' => 'App\\Foo\\Bar',
                'public_methods' => ['run'],
                'is_orphan' => false,
                'is_forbidden' => false,
                'clone_cluster_id' => null,
            ]],
            edges: [],
            orphans: [],
            cloneClusters: [],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: [],
            snapshotId: 'snap-lens-test',
        );
    }

    /** A worker whose critic counts each completion pass; no real provider is ever called. */
    private function worker(): AtlasLoopProjectionWorker
    {
        $counter = function (string $provider, string $prompt): ?string {
            $this->passPrompts[] = $prompt;

            return ''; // empty completion ⇒ critic grounds nothing (harmless); we only count the passes
        };

        return new AtlasLoopProjectionWorker(
            app(AtlasLoopStore::class),
            null,
            null,
            null,
            $this->scopeModel(),
            null,
            new AtlasLoopModelProjectionCritic($counter),
        );
    }

    private function runGroundedRoles(AtlasLoopProjectionWorker $worker): void
    {
        $m = new ReflectionMethod($worker, 'groundedRolesFor');
        $m->setAccessible(true);
        $m->invoke($worker, '/repo', 'app/Foo/Bar.php', 'wired', '');
    }

    // (a) flag OFF ⇒ the single-pass additionalObligations (today's behavior) — exactly ONE critic pass.
    public function test_flag_off_runs_a_single_critique_pass(): void
    {
        config(['atlas.loop.grounded_projection_lensed_critique_enabled' => false]);

        $this->runGroundedRoles($this->worker());

        $this->assertCount(1, $this->passPrompts, 'flag OFF ⇒ one single-lens pass, byte-identical to before');
    }

    // (b) flag ON ⇒ one pass PER lens (default 4), proven via the injected completion counter.
    public function test_flag_on_runs_one_pass_per_default_lens(): void
    {
        config(['atlas.loop.grounded_projection_lensed_critique_enabled' => true]);

        $this->runGroundedRoles($this->worker());

        $this->assertCount(4, $this->passPrompts, 'flag ON ⇒ one critic pass per DEFAULT_LENS (4)');
        $this->assertCount(4, AtlasLoopModelProjectionCritic::DEFAULT_LENSES, 'sanity: 4 default lenses');
    }

    // (c) lenses are env-configurable (CSV); empty falls back to DEFAULT_LENSES.
    public function test_configured_lenses_csv_controls_pass_count(): void
    {
        config(['atlas.loop.grounded_projection_lensed_critique_enabled' => true]);
        config(['atlas.loop.grounded_projection_lenses' => 'correctness, security']);

        $this->runGroundedRoles($this->worker());
        $this->assertCount(2, $this->passPrompts, 'two configured lenses ⇒ two passes');

        // empty CSV ⇒ DEFAULT_LENSES (4)
        $this->passPrompts = [];
        config(['atlas.loop.grounded_projection_lenses' => '  ,  ']);
        $this->runGroundedRoles($this->worker());
        $this->assertCount(4, $this->passPrompts, 'empty lens config falls back to DEFAULT_LENSES');
    }
}
