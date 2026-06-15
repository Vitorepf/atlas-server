<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTaskGrinder;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopMultiFileRefactorSynthesizer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraClusterCandidate;
use App\Services\Ai\Programming\AtlasForgeMultiNodeL410ProofService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * OPTION 3 · slice-1 END-TO-END — the FULL produce->route->park chain as one flow: the autonomous
 * multi-file refactor SYNTHESIZER produces a real >=2-file payload, the grinder HARD-routes it to
 * the obra bridge, and it PARKS for operator review (with a REAL L4-10 receipt) or correctly BLOCKS
 * to no_winner (without one) — NEVER auto-merging, NEVER single-file-materializing a multi-file diff.
 *
 * The L4-10 evidence is the REAL certifier-passing fixture (mirrors AtlasLoopMultiFileRefactorRouting
 * Test::writeRealL410Evidence) — never a stub the certifier would reject (adversary must-fix).
 */
final class AtlasLoopMultiFileRefactorProduceToParkTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    private string $evidencePath = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        $this->evidencePath = storage_path('framework/testing/mf-produce-park-l410-'.Str::uuid().'.json');
        // Tests the OBRA produce-to-park route — pin the normal-lane flag OFF so it is hermetic
        // regardless of the operator's live .env (Path B via_normal_lane ON).
        config(['atlas.loop.multi_file_refactor_via_normal_lane' => false]);
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        if ($this->evidencePath !== '') {
            @File::delete($this->evidencePath);
        }
        parent::tearDown();
    }

    /** A temp repo with a hub + caller + their sibling tests so the synthesizer produces a payload. */
    private function synthesizedPayload(): array
    {
        $d = sys_get_temp_dir().'/atlas-mfp-'.bin2hex(random_bytes(4));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/app/Services');
        File::ensureDirectoryExists($d.'/app/Callers');
        File::ensureDirectoryExists($d.'/tests/Unit/Services');
        File::ensureDirectoryExists($d.'/tests/Unit/Callers');
        File::put($d.'/app/Services/Hub.php', "<?php\nnamespace App\\Services;\nfinal class Hub { public function x(int \$n): int { return \$n > 0 ? \$n : 0; } }\n");
        File::put($d.'/tests/Unit/Services/HubTest.php', "<?php\nnamespace Tests\\Unit\\Services;\nfinal class HubTest { public function t(): void {} }\n");
        File::put($d.'/app/Callers/CallerA.php', "<?php\nnamespace App\\Callers;\nuse App\\Services\\Hub;\nfinal class CallerA { public function go(Hub \$h): int { return \$h->x(1); } }\n");
        File::put($d.'/tests/Unit/Callers/CallerATest.php', "<?php\nnamespace Tests\\Unit\\Callers;\nfinal class CallerATest { public function t(): void {} }\n");

        $cluster = AtlasLoopObraClusterCandidate::fromHub(
            'app/Services/Hub.php', ['app/Callers/CallerA.php'],
            ['cyclomatic' => 14, 'cyclomatic_total' => 40, 'refactor_leverage' => 0.8, 'measured_caller_count' => 1],
            ['measured_impact_callers' => 1],
        );
        $out = (new AtlasLoopMultiFileRefactorSynthesizer())->synthesizeMultiFileRefactor($cluster, $d);
        $this->assertIsArray($out, 'the synthesizer produced a real multi-file payload');
        $this->assertGreaterThanOrEqual(2, count((array) $out['payload']['allowed_files']));

        return $out;
    }

    private function writeRealL410Evidence(): void
    {
        $files = [
            'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
            'app/Console/Commands/AtlasLoopMorningDigestCommand.php',
            'tests/Feature/Loop/AtlasLoopMorningDigestTest.php',
        ];
        $executorReceipt = (new \App\Services\Ai\Obra\AtlasObraReceiptStamp)->stamp([
            'obra_id' => 'obra-mf-produce-park', 'status' => 'done', 'certified' => true,
            'node_count' => 6, 'delivered_nodes' => 6, 'provider' => 'hermes_cli', 'model' => 'gpt-5.5',
            'resumed' => true, 'resume_count' => 1, 'main_untouched' => true, 'never_merged' => true,
            'delivered_item_id' => 'L4-6', 'delivered_files' => $files,
        ]);
        File::ensureDirectoryExists(dirname($this->evidencePath));
        File::put($this->evidencePath, json_encode([
            'schema_version' => AtlasForgeMultiNodeL410ProofService::REAL_RECEIPT_SCHEMA_VERSION,
            'status' => 'done', 'certified' => true, 'execution_mode' => 'real_provider_obra_run',
            'obra_id' => 'obra-mf-produce-park', 'node_count' => 6, 'provider_calls_made' => true,
            'external_provider_call' => true, 'provider' => ['name' => 'hermes_cli', 'model' => 'gpt-5.5'],
            'delivered_item_id' => 'L4-6', 'delivered_files' => $files, 'resumed' => true, 'resume_count' => 1,
            'kill_resume' => ['kill_exercised' => true, 'resume_exercised' => true, 'evidence_refs' => ['ledger:kill-real', 'ledger:resume-real']],
            'command_results' => [['command' => 'php artisan atlas:loop:morning-digest --json', 'exit_code' => 0]],
            'executor_receipt' => $executorReceipt,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function claimedTask(string $campaignId, array $synth): AtlasLoopTask
    {
        return AtlasLoopTask::create([
            'campaign_id' => $campaignId,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_CLAIMED,
            'source' => 'discovery',
            'self_contained' => true,
            'target_path' => (string) $synth['payload']['target_repo_path'],
            'objective' => (string) $synth['objective'],
            'payload' => $synth['payload'],
            'priority' => 90,
            'attempts' => 1,
            'max_attempts' => 2,
            'dedupe_key' => 'mfp-'.bin2hex(random_bytes(4)),
            'claimed_by' => 'w',
            'claimed_at' => now(),
            'lease_expires_at' => now()->addMinutes(10),
        ]);
    }

    public function test_synthesized_multi_file_payload_with_real_l4_10_parks_for_operator_never_merges(): void
    {
        config(['atlas.loop.refactor_multi_file_via_obra' => true]);
        $synth = $this->synthesizedPayload();
        $this->writeRealL410Evidence();
        $synth['payload']['l4_10_evidence_path'] = $this->evidencePath;

        $campaign = AtlasLoopCampaign::create(['schema_version' => 'atlas.loop.campaign.v1', 'status' => 'running', 'goal' => 'mfp', 'config' => [], 'max_seconds' => 60]);
        $task = $this->claimedTask($campaign->id, $synth);

        $result = $this->app->make(AtlasLoopTaskGrinder::class)->grind($task, 'w', null, sys_get_temp_dir());

        $this->assertSame('proposal_created_obra_multi_file_refactor', $result['status'], json_encode($result['obra_bridge'] ?? []));
        $this->assertFalse($result['has_winner']);
        $this->assertSame(0, $result['proposals'], 'the obra route never produces a single-file proposal');
        $this->assertTrue((bool) data_get($result, 'obra_bridge.operator_approval_required'), 'operator approval REQUIRED — never auto-merges');
        $this->assertFalse((bool) data_get($result, 'obra_bridge.provider_dispatches_now'));
        $this->assertSame(0, AtlasLoopProposal::query()->count(), 'never merged, never materialized');
        $this->assertSame(AtlasLoopTask::STATUS_DONE, $task->fresh()->status);
    }

    public function test_synthesized_multi_file_payload_without_l4_10_blocks_never_single_file_materializes(): void
    {
        config(['atlas.loop.refactor_multi_file_via_obra' => true]);
        $synth = $this->synthesizedPayload(); // no l4_10_evidence_path

        $campaign = AtlasLoopCampaign::create(['schema_version' => 'atlas.loop.campaign.v1', 'status' => 'running', 'goal' => 'mfp', 'config' => [], 'max_seconds' => 60]);
        $task = $this->claimedTask($campaign->id, $synth);

        $result = $this->app->make(AtlasLoopTaskGrinder::class)->grind($task, 'w', null, sys_get_temp_dir());

        $this->assertSame('no_winner', $result['status']);
        $this->assertStringContainsString('obra_bridge_blocked_by_l4_10', (string) $result['reason']);
        $this->assertSame(0, AtlasLoopProposal::query()->count(), 'a blocked multi-file refactor must NEVER single-file materialize');
        // TERMINAL, not re-queued — a permanent structural block must not spin claim->release and
        // zombie at attempts==max (which starves the queue and blocks the clean stop).
        $this->assertSame(AtlasLoopTask::STATUS_FAILED, $task->fresh()->status, 'blocked obra task must be terminal');
    }
}
