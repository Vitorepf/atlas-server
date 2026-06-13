<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopExploration;
use App\Models\AtlasLoopProposal;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * L5-2: the FROZEN proof that the once-orphaned {@see \App\Services\Ai\AutonomousEvolution\AtlasLoopObraBridgeService}
 * is now auto-invoked by the live 24h supervisor.
 *
 * It proves the contract, not a measured 24h soak: with the flag ON, a CLAIMED task whose
 * intent spans >= obra_bridge.min_files distinct files auto-escalates to a governed,
 * parked-for-operator-review Obra handoff packet (NEVER auto-merged, NEVER provider-dispatched);
 * a single-file intent does NOT escalate and grinds as usual. The supervisor's clock/sleeper/
 * storage are injected so the loop runs fast and deterministically.
 */
final class AtlasLoopObraAutoEscalationTest extends TestCase
{
    private string $storageRoot;

    private string $emptyRepo;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'atlas.loop.parallel.enabled' => false,
            'atlas.loop.taxa2_dials.enabled' => false,
            'atlas.loop.cost_governor.enabled' => false,
            'atlas.loop.universal_certification' => false,
            // The feature under test: default OFF, flipped ON per-test below.
            'atlas.loop.obra_bridge.auto_escalate' => true,
            'atlas.loop.obra_bridge.min_files' => 2,
        ]);
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach (['2026_06_02_000100_create_atlas_loop_runtime_tables.php', '2026_06_02_000200_complete_atlas_loop_runtime_schema.php'] as $f) {
                (require base_path('database/migrations/'.$f))->up();
            }
        }
        $this->storageRoot = sys_get_temp_dir().'/atlas-loop-obra-esc-storage-'.bin2hex(random_bytes(4));
        $this->emptyRepo = sys_get_temp_dir().'/atlas-loop-obra-esc-repo-'.bin2hex(random_bytes(4));
        @mkdir($this->emptyRepo, 0o755, true);

        // Deterministic provider: flips 'broken' -> 'fixed' so each seeded task yields a winner.
        $this->app->bind(LoopExecutionDriver::class, fn () => new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                foreach (glob($workspace.'/src/*.php') ?: [] as $file) {
                    file_put_contents($file, str_replace("'broken'", "'fixed'", (string) file_get_contents($file)));
                }

                return ['status' => 'completed'];
            }
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->emptyRepo) && Schema::hasTable('atlas_loop_campaigns')) {
            $campaignIds = AtlasLoopCampaign::query()
                ->where('base_workspace', $this->emptyRepo)
                ->pluck('id')
                ->all();

            if ($campaignIds !== []) {
                AtlasLoopExploration::query()->whereIn('campaign_id', $campaignIds)->delete();
                AtlasLoopProposal::query()->whereIn('campaign_id', $campaignIds)->delete();
                AtlasLoopTask::query()->whereIn('campaign_id', $campaignIds)->delete();
                AtlasLoopCampaign::query()->whereIn('id', $campaignIds)->delete();
            }
        }

        foreach ([$this->storageRoot, $this->emptyRepo] as $dir) {
            (new Process(['rm', '-rf', $dir]))->run();
        }
        parent::tearDown();
    }

    public function test_auto_escalation_fires_on_multi_file_intent_and_never_auto_executes(): void
    {
        $campaign = $this->seedCampaign();
        // A synthetic multi-file intent: the durable payload's scope spans 3 distinct files
        // (the grind target src/MultiFile.php plus two sibling files the intent also touches).
        $task = $this->seedTask($campaign->id, 'MultiFile', [
            'src/MultiFile.php',
            'app/Domain/A.php',
            'app/Domain/B.php',
        ]);

        $supervisor = $this->supervisor();
        $result = $supervisor->run(['campaign_id' => $campaign->id, 'scenarios' => 1]);

        $this->assertFalse($result['merged_to_main']); // hard invariant: the loop never merges

        $ledger = $supervisor->readLedger($campaign->id, 1000);
        $escalation = $this->firstEvent($ledger, 'obra_auto_escalation');

        $this->assertIsArray($escalation, 'auto-escalation must fire for a multi-file intent');
        $this->assertSame($task->id, $escalation['task_id']);
        $this->assertSame(3, $escalation['file_count']);
        $this->assertSame(2, $escalation['min_files']);
        $this->assertTrue((bool) $escalation['multi_file_detected']);
        $this->assertTrue((bool) $escalation['operator_approval_required']);
        // The never-auto-execute / never-merge contract is on the packet itself.
        $this->assertFalse((bool) $escalation['auto_execute_allowed']);
        $this->assertContains('app/Domain/A.php', (array) $escalation['target_files']);

        // It did NOT skip, and it did NOT refuse (the packet honoured the operator gate).
        $this->assertNull($this->firstEvent($ledger, 'obra_auto_escalation_skipped'));
        $this->assertNull($this->firstEvent($ledger, 'obra_auto_escalation_refused'));
        $this->assertNull($this->firstEvent($ledger, 'obra_auto_escalation_error'));

        // The task still ground as usual (escalation is preflight, it does not replace the grind).
        $task->refresh();
        $this->assertSame(AtlasLoopTask::STATUS_DONE, $task->status);
    }

    public function test_auto_escalation_does_not_fire_on_single_file_intent(): void
    {
        $campaign = $this->seedCampaign();
        $task = $this->seedTask($campaign->id, 'SingleFile', ['src/SingleFile.php']);

        $supervisor = $this->supervisor();
        $result = $supervisor->run(['campaign_id' => $campaign->id, 'scenarios' => 1]);

        $this->assertFalse($result['merged_to_main']);

        $ledger = $supervisor->readLedger($campaign->id, 1000);

        // The single-file intent must NOT escalate.
        $this->assertNull($this->firstEvent($ledger, 'obra_auto_escalation'));

        // It is explicitly logged as skipped with the real (single) file count.
        $skipped = $this->firstEvent($ledger, 'obra_auto_escalation_skipped');
        $this->assertIsArray($skipped, 'a single-file intent must be recorded as skipped');
        $this->assertSame($task->id, $skipped['task_id']);
        $this->assertSame(1, $skipped['file_count']);
        $this->assertSame('single_file_intent_below_min_files', $skipped['reason']);

        $task->refresh();
        $this->assertSame(AtlasLoopTask::STATUS_DONE, $task->status);
    }

    public function test_flag_off_never_escalates_even_for_multi_file_intent(): void
    {
        config(['atlas.loop.obra_bridge.auto_escalate' => false]);

        $campaign = $this->seedCampaign();
        $this->seedTask($campaign->id, 'MultiOff', ['app/X.php', 'app/Y.php', 'app/Z.php']);

        $supervisor = $this->supervisor();
        $result = $supervisor->run(['campaign_id' => $campaign->id, 'scenarios' => 1]);

        $this->assertFalse($result['merged_to_main']);

        $ledger = $supervisor->readLedger($campaign->id, 1000);
        $this->assertNull($this->firstEvent($ledger, 'obra_auto_escalation'));
        $this->assertNull($this->firstEvent($ledger, 'obra_auto_escalation_skipped'));
    }

    /**
     * @param  list<array<string,mixed>>  $ledger
     * @return array<string,mixed>|null
     */
    private function firstEvent(array $ledger, string $event): ?array
    {
        foreach ($ledger as $entry) {
            if (is_array($entry) && ($entry['event'] ?? null) === $event) {
                return $entry;
            }
        }

        return null;
    }

    private function supervisor(): AtlasLoopCampaignSupervisor
    {
        $s = $this->app->make(AtlasLoopCampaignSupervisor::class);
        $s->setStorageRootForTesting($this->storageRoot);
        $t = 1000;
        $s->setClockForTesting(function () use (&$t): int {
            $now = $t;
            $t += 50;

            return $now;
        });
        $s->setSleeperForTesting(fn (int $secs): null => null);

        return $s;
    }

    private function seedCampaign(): AtlasLoopCampaign
    {
        return $this->app->make(AtlasLoopStore::class)->openCampaign('prove obra auto-escalation', $this->emptyRepo, [
            'max_seconds' => 0,
            'max_usd_cents' => 0,
        ], ['scenarios_per_task' => 1]);
    }

    /**
     * The grind target stays a single self-contained `src/<class>.php` (so the deterministic
     * provider yields a real winner), but the durable payload's SCOPE signal (`allowed_files`)
     * carries the intent's file breadth — that is exactly what auto-escalation reads.
     *
     * @param  list<string>  $allowedFiles
     */
    private function seedTask(string $campaignId, string $class, array $allowedFiles): AtlasLoopTask
    {
        $rel = 'src/'.$class.'.php';

        return AtlasLoopTask::create([
            'campaign_id' => $campaignId,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_PENDING,
            'source' => AtlasLoopTask::SOURCE_SEED,
            'self_contained' => true,
            'target_path' => $rel,
            'objective' => "Make {$class}::v() return fixed across the intent's files. Edit {$rel} directly.",
            'payload' => [
                'target_relative_path' => $rel,
                'target_content' => "<?php\nnamespace S;\nfinal class {$class}{ public function v(): string { return 'broken'; } }\n",
                'frozen_tests' => [[
                    'path' => 'tests/'.$class.'_test.php',
                    'content' => "<?php\nrequire __DIR__.'/../{$rel}';\n\$s=new \\S\\{$class}();\nif (\$s->v() !== 'fixed') { fwrite(STDERR,'red'); exit(1); }\necho 'ok';\n",
                ]],
                'acceptance' => ['commands' => ['php tests/'.$class.'_test.php'], 'allowed_globs' => ['src/**'], 'frozen_globs' => ['tests/**', 'composer.json'], 'metric_kind' => 'gate'],
                'allowed_files' => $allowedFiles,
                'validation_commands' => ['php tests/'.$class.'_test.php'],
            ],
            'priority' => 100,
            'attempts' => 0,
            'max_attempts' => 2,
            'dedupe_key' => 'dk-'.$class,
        ]);
    }
}
