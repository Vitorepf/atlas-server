<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionToolGapBridgeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L5-4 · Self-construction of tools — the recurrent-capability-gap bridge.
 *
 * THE keystone the item asks for: "Quando o loss-observer detectar um gap
 * RECORRENTE de capacidade (ex: falta um fixture builder, um linter de contrato),
 * o Atlas constrói a própria ferramenta — obra gated, parked para revisão, nunca
 * silencioso."
 *
 * This freezes the MECHANISM + GATE:
 *   - a recurrent CAPABILITY-gap loss (worktree/framework-gate helper missing)
 *     routes into the governed self-construction corridor as a PARKED proposal
 *     (requires_human_approval=true), never silent, never merged, never promoted;
 *   - an ordinary CODE-FIX loss (a bug in an existing file) does NOT trigger
 *     self-construction;
 *   - dry-run persists nothing; --write persists exactly the parked proposal;
 *   - the proposal id/hash is deterministic (idempotent over the same gap).
 */
final class AtlasSelfConstructionToolGapBridgeTest extends TestCase
{
    private string $proposalsLog;

    private string $approvalsLog;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        $base = storage_path('framework/testing');
        $this->proposalsLog = $base.'/atlas-l5-4-proposals-'.(string) Str::uuid().'.jsonl';
        $this->approvalsLog = $base.'/atlas-l5-4-approvals-'.(string) Str::uuid().'.jsonl';
        @unlink($this->proposalsLog);
        @unlink($this->approvalsLog);

        // Route the builder's append-only logs into the test sandbox so a --write
        // run is observable and isolated from the runtime ledger.
        $builder = new AtlasSelfConstructionSubsystemBuilderService(
            app(\App\Services\Ai\Cognition\AtlasCognitionScoreCardService::class),
        );
        $builder->setProposalsLogPathForTesting($this->proposalsLog);
        $builder->setApprovalsLogPathForTesting($this->approvalsLog);
        $this->app->instance(AtlasSelfConstructionSubsystemBuilderService::class, $builder);
    }

    protected function tearDown(): void
    {
        @unlink($this->proposalsLog);
        @unlink($this->approvalsLog);
        parent::tearDown();
    }

    /**
     * @param  list<array{reason:string,target:string,count:int}>  $losses
     */
    private function seedLosses(string $campaignId, array $losses): void
    {
        AtlasLoopCampaign::query()->create([
            'id' => $campaignId,
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'l5-4-tool-gap-bridge-test',
            'base_workspace' => base_path(),
            'config' => [],
            'max_seconds' => 3600,
        ]);
        $i = 0;
        foreach ($losses as $loss) {
            for ($n = 0; $n < $loss['count']; $n++, $i++) {
                $taskId = (string) Str::uuid();
                DB::table('atlas_loop_tasks')->insert([
                    'id' => $taskId,
                    'campaign_id' => $campaignId,
                    'schema_version' => 'atlas.loop.task.v1',
                    'status' => 'done',
                    'source' => 'discovery',
                    'self_contained' => true,
                    'target_path' => $loss['target'],
                    'objective' => 'synthetic loss '.$i,
                    'payload' => json_encode(['allowed_files' => [$loss['target']]]),
                    'priority' => 0,
                    'attempts' => 1,
                    'max_attempts' => 1,
                    'dedupe_key' => hash('sha256', $campaignId.'|'.$i),
                    'result' => json_encode(['status' => 'no_winner']),
                    'created_at' => now()->subHour(),
                    'updated_at' => now()->subHour(),
                ]);
                DB::table('atlas_loop_explorations')->insert([
                    'id' => (string) Str::uuid(),
                    'campaign_id' => $campaignId,
                    'task_id' => $taskId,
                    'schema_version' => 'atlas.loop.exploration.v1',
                    'objective' => 'synthetic loss '.$i,
                    'provider' => 'test',
                    'scenarios_explored' => 1,
                    'scenarios_accepted' => 0,
                    'has_winner' => false,
                    'converged' => false,
                    'rejected_reasons' => json_encode([$loss['reason']]),
                    'elapsed_seconds' => 1.0,
                    'created_at' => now()->subMinutes(30),
                    'updated_at' => now()->subMinutes(30),
                ]);
            }
        }
    }

    public function test_recurrent_capability_gap_routes_to_parked_self_construction_proposal(): void
    {
        $campaignId = (string) Str::uuid();
        // A recurrent CAPABILITY gap: the framework gate keeps failing to add a
        // worktree → Atlas is missing a worktree-staging helper TOOL.
        $this->seedLosses($campaignId, [
            ['reason' => 'gate_error:framework_gate_worktree_add_failed', 'target' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php', 'count' => 4],
        ]);

        /** @var AtlasSelfConstructionToolGapBridgeService $bridge */
        $bridge = app(AtlasSelfConstructionToolGapBridgeService::class);
        $result = $bridge->detectAndRoute([
            'campaign_id' => $campaignId,
            'window_hours' => 24,
            'min_occurrences' => 3,
            'write' => true,
        ]);

        $this->assertSame('proposed_for_review', $result['status']);
        $this->assertSame(1, $result['capability_gap_count']);
        $this->assertSame(1, $result['proposal_count']);

        $proposal = $result['proposals'][0];
        $this->assertSame(AtlasSelfConstructionSubsystemBuilderService::GAP_RECURRENT_CAPABILITY, $proposal['gap_kind']);
        $this->assertSame('WORKTREEHELPER', $proposal['tool_acronym']);
        $this->assertTrue($proposal['persisted']);
        $this->assertTrue($proposal['requires_human_approval']);
        $this->assertStringStartsWith('prop_', $proposal['proposal_id']);
        $this->assertStringContainsString('App\\Services\\Ai\\SelfConstruction', $proposal['service_class']);

        // Never-silent / never-merge / never-auto-apply claim policy.
        $this->assertFalse($result['claim_policy']['provider_calls_made']);
        $this->assertFalse($result['claim_policy']['workspace_mutated']);
        $this->assertFalse($result['claim_policy']['merged_to_main']);
        $this->assertFalse($result['claim_policy']['auto_approved']);
        $this->assertFalse($result['claim_policy']['auto_promoted']);
        $this->assertTrue($result['claim_policy']['never_silent']);

        // --write persisted exactly the parked proposal to the builder's jsonl.
        $this->assertFileExists($this->proposalsLog);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->proposalsLog))));
        $this->assertCount(1, $lines);
        $persisted = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($proposal['proposal_id'], $persisted['proposal_id']);
        $this->assertSame('recurrent_capability', $persisted['gap']['kind']);
        $this->assertTrue($persisted['requires_human_approval']);
        // No approval was ever auto-appended.
        $this->assertFileDoesNotExist($this->approvalsLog);
    }

    public function test_ordinary_code_fix_loss_does_not_trigger_self_construction(): void
    {
        $campaignId = (string) Str::uuid();
        // A recurrent but ORDINARY loss: a normal verification failure in an
        // existing file. This is a backlog code-fix intent, NOT a missing tool.
        $this->seedLosses($campaignId, [
            ['reason' => 'gate_error:semantic_certifier_timeout', 'target' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php', 'count' => 5],
        ]);

        /** @var AtlasSelfConstructionToolGapBridgeService $bridge */
        $bridge = app(AtlasSelfConstructionToolGapBridgeService::class);
        $result = $bridge->detectAndRoute([
            'campaign_id' => $campaignId,
            'window_hours' => 24,
            'min_occurrences' => 3,
            'write' => true,
        ]);

        $this->assertSame('no_capability_gap', $result['status']);
        $this->assertSame(0, $result['capability_gap_count']);
        $this->assertSame(0, $result['proposal_count']);
        $this->assertNotEmpty($result['skipped_non_capability']);
        $this->assertSame('code_fix_intent', $result['skipped_non_capability'][0]['classification']);
        // Nothing persisted — self-construction stayed out of an ordinary bug.
        $this->assertFileDoesNotExist($this->proposalsLog);
    }

    public function test_dry_run_classifies_but_persists_nothing(): void
    {
        $campaignId = (string) Str::uuid();
        $this->seedLosses($campaignId, [
            ['reason' => 'gate_error:fixture_builder_missing', 'target' => 'app/Services/Ai/Foo.php', 'count' => 3],
        ]);

        /** @var AtlasSelfConstructionToolGapBridgeService $bridge */
        $bridge = app(AtlasSelfConstructionToolGapBridgeService::class);
        $result = $bridge->detectAndRoute([
            'campaign_id' => $campaignId,
            'window_hours' => 24,
            'min_occurrences' => 3,
            'write' => false,
        ]);

        $this->assertSame('dry_run', $result['status']);
        $this->assertSame(1, $result['capability_gap_count']);
        $this->assertSame(1, $result['proposal_count']);
        $this->assertSame('FIXTUREBUILDER', $result['proposals'][0]['tool_acronym']);
        $this->assertFalse($result['proposals'][0]['persisted']);
        // Genuine dry-run: zero disk writes.
        $this->assertFileDoesNotExist($this->proposalsLog);
    }

    public function test_proposal_id_is_deterministic_over_the_same_gap(): void
    {
        $campaignId = (string) Str::uuid();
        $this->seedLosses($campaignId, [
            ['reason' => 'gate_error:framework_gate_worktree_add_failed', 'target' => 'app/Services/Ai/Bar.php', 'count' => 3],
        ]);

        /** @var AtlasSelfConstructionToolGapBridgeService $bridge */
        $bridge = app(AtlasSelfConstructionToolGapBridgeService::class);

        $first = $bridge->detectAndRoute(['campaign_id' => $campaignId, 'min_occurrences' => 3, 'write' => false]);
        $second = $bridge->detectAndRoute(['campaign_id' => $campaignId, 'min_occurrences' => 3, 'write' => false]);

        $this->assertSame(
            $first['proposals'][0]['proposal_id'],
            $second['proposals'][0]['proposal_id'],
            'same recurrent capability gap must map to the same parked proposal id',
        );
        $this->assertSame(
            $first['proposals'][0]['proposal_hash'],
            $second['proposals'][0]['proposal_hash'],
        );
    }
}
