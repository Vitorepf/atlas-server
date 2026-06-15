<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopPipelineDrift;
use Tests\TestCase;

/**
 * Single source of truth for "is a live supervisor running STALE engine code?", shared by the
 * in-process supervisor check AND the out-of-process keepalive backstop. These freeze the two
 * pure decisions that govern an autonomous fresh-code reload (no human recycle):
 *
 *  1. pipelineFiles(): ONLY engine-path changes count — target merges (the loop's normal work)
 *     must never trigger a restart (else ~5min churn per merge).
 *  2. shouldRecycle(): TIME-anchored (process-start vs newest engine commit) so the watchdog is
 *     immune to the in-process check's two blind spots — a grind-starved inner loop and a null
 *     boot HEAD. Grace protects a just-respawned (already-fresh) supervisor; the decision is
 *     self-clearing; missing evidence fails SAFE (never recycle).
 */
final class AtlasLoopPipelineDriftTest extends TestCase
{
    public function test_engine_paths_count_as_pipeline_drift(): void
    {
        foreach ([
            'app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php',
            'app/Services/Ai/AutonomousEvolution/Campaign/AtlasLoopCampaignSupervisor.php',
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AdversarialProofPanelService.php',
            'app/Models/AtlasLoopProposal.php',
            'config/atlas.php',
            'database/migrations/2026_06_12_000100_governed_merge_door_atlas_loop_proposals.php',
        ] as $file) {
            $this->assertSame([$file], AtlasLoopPipelineDrift::pipelineFiles([$file]), "$file is engine");
        }
    }

    public function test_target_file_merges_are_not_pipeline_drift(): void
    {
        $this->assertSame([], AtlasLoopPipelineDrift::pipelineFiles([
            'app/Services/Ai/Aaeos/Generated/AtlasContractSchemaRegistryService.php',
            'app/Support/TerminalMarkdownRenderer.php',
            'app/Services/Ai/Kernel/Decision/ProviderFitWeightPolicy.php',
        ]), 'os merges normais de alvo NÃO disparam restart (sem churn)');
    }

    public function test_mixed_set_returns_only_the_engine_files_deduped(): void
    {
        $this->assertSame(
            ['app/Services/Ai/AutonomousEvolution/AtlasEvolutionScenarioExplorer.php'],
            AtlasLoopPipelineDrift::pipelineFiles([
                'app/Support/TerminalMarkdownRenderer.php',
                'app/Services/Ai/AutonomousEvolution/AtlasEvolutionScenarioExplorer.php',
                'app/Services/Ai/AutonomousEvolution/AtlasEvolutionScenarioExplorer.php',
                'app/Services/Ai/Aaeos/Generated/Foo.php',
            ]),
        );
    }

    public function test_recycle_when_engine_commit_is_newer_than_boot_and_past_grace(): void
    {
        // boot at t=1000, engine commit at t=2000, alive 600s, grace 120s → STALE → recycle.
        $this->assertTrue(AtlasLoopPipelineDrift::shouldRecycle(1000, 2000, 600, 120));
    }

    public function test_no_recycle_when_boot_is_newer_than_or_equal_to_commit(): void
    {
        // A just-respawned supervisor booted AFTER the commit is already fresh → never recycle.
        $this->assertFalse(AtlasLoopPipelineDrift::shouldRecycle(2000, 1500, 600, 120), 'boot after commit = fresh');
        $this->assertFalse(AtlasLoopPipelineDrift::shouldRecycle(2000, 2000, 600, 120), 'equal = fresh');
    }

    public function test_no_recycle_within_grace_window(): void
    {
        // Stale by the clock, but only alive 30s (< 120s grace) — could be mid-respawn; let it settle.
        $this->assertFalse(AtlasLoopPipelineDrift::shouldRecycle(1000, 2000, 30, 120));
    }

    public function test_missing_evidence_never_recycles(): void
    {
        $this->assertFalse(AtlasLoopPipelineDrift::shouldRecycle(null, 2000, 600, 120), 'no boot epoch = fail-safe');
        $this->assertFalse(AtlasLoopPipelineDrift::shouldRecycle(1000, null, 600, 120), 'no commit epoch = fail-safe');
    }

    public function test_latest_commit_epoch_uses_resolver_and_degrades_to_null_on_error(): void
    {
        $epoch = AtlasLoopPipelineDrift::latestPipelineCommitEpoch('/any', static fn (): int => 1718000000);
        $this->assertSame(1718000000, $epoch);

        $degraded = AtlasLoopPipelineDrift::latestPipelineCommitEpoch('/any', static function (): int {
            throw new \RuntimeException('git boom');
        });
        $this->assertNull($degraded, 'a git error degrades to no-drift, never throws into the watchdog');
    }
}
