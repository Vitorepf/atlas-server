<?php

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Rivals\Adapters\External\Tau2BenchAdapter;
use App\Services\Ai\Rivals\Core\Adjudicator;
use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\EvidencePackBuilder;
use App\Services\Ai\Rivals\Core\FrozenUnitManifest;
use App\Services\Ai\Rivals\Core\ReplayVerifier;
use App\Services\Ai\Rivals\Core\ReportBuilder;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Core\RunStateMachine;
use App\Services\Ai\Rivals\Support\RunPaths;
use Tests\TestCase;

/**
 * plan → import fixture → evidence → verify → adjudicate → report
 * for an external suite with native→canonical arm binding.
 */
class ExternalLoopPipelineTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_external_loop_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
        config()->set('atlas_rivals.enabled', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storage)) {
            exec('rm -rf '.escapeshellarg($this->storage));
        }
        parent::tearDown();
    }

    public function test_tau2_bench_plan_import_verify_adjudicate_report_with_bindings(): void
    {
        $adapter = new Tau2BenchAdapter;
        $suiteId = $adapter->suiteId();
        $caseDir = RunPaths::root()."/external/{$suiteId}/cases";
        RunPaths::ensureDir($caseDir);
        file_put_contents($caseDir.'/airline_task_012.json', json_encode([
            'case_id' => 'airline_task_012',
            'task_type' => 'tool_use_function_calling',
            'title' => 'airline_task_012',
            'suite_id' => $suiteId,
            'source_repo' => $suiteId,
        ]));

        $arm = (new ArmRegistry)->parse('claude_sonnet_5@bare', $suiteId);
        $this->assertSame('claude-sonnet-5', $arm['cli_model']);
        $this->assertSame('tau2', $arm['native_agent']);

        $plan = RunPlan::make(
            $suiteId,
            ['airline_task_012'],
            [$arm],
            2, // fixture has 2 trials; claim will block on min_reps but pipeline must complete
            ['max_usd' => 0.0, 'max_minutes' => 5],
            1,
        );
        $runId = $plan->persist();
        FrozenUnitManifest::fromPlan(RunPlan::load($runId), [['case_id' => 'airline_task_012']], true)->persist();
        (new RunStateMachine)->mark($runId, RunStateMachine::PLANNED);
        (new RunStateMachine)->mark($runId, RunStateMachine::NATIVE_RUNNING);

        $commands = $adapter->planCommands($plan);
        $this->assertNotEmpty($commands);
        $this->assertStringContainsString('claude-sonnet-5', $commands[0]['command']);
        $this->assertStringNotContainsString('{', $commands[0]['command']);

        $dest = RunPaths::runDir($runId).'/external_results';
        RunPaths::ensureDir($dest);
        copy(base_path('tests/Fixtures/Rivals/tau2_bench_results.json'), $dest.'/tau2_bench.json');

        $receipts = $adapter->ingestResults(RunPaths::runDir($runId));
        $this->assertCount(2, $receipts);
        foreach ($receipts as $receipt) {
            $this->assertSame('claude_sonnet_5@bare', $receipt->data['arm_id']);
            $receipt->append();
        }
        (new RunStateMachine)->mark($runId, RunStateMachine::RESULTS_IMPORTED);

        $pack = (new EvidencePackBuilder)->build($runId);
        $this->assertTrue($pack['receipts_hash']['present'] ?? false);
        (new RunStateMachine)->mark($runId, RunStateMachine::EVIDENCE_BUILT);

        $verify = (new ReplayVerifier)->verify($runId);
        $this->assertTrue($verify['verified'], implode(',', $verify['failures'] ?? []));
        (new RunStateMachine)->mark($runId, RunStateMachine::VERIFIED);

        $decision = (new Adjudicator)->adjudicate($runId);
        // fixture has only 2 reps → claim blocked honestly
        $this->assertFalse($decision['claim_allowed']);
        $this->assertNotEmpty($decision['claim_blockers']);
        $this->assertSame($suiteId, $decision['claim_scope']['suite']);
        $this->assertContains('claude_sonnet_5', $decision['claim_scope']['models']);
        (new RunStateMachine)->mark($runId, RunStateMachine::ADJUDICATED);

        $report = (new ReportBuilder)->build($runId);
        $this->assertFalse($report['claim_allowed']);
        $this->assertNotEmpty($report['rows']);
        $this->assertArrayHasKey('cost_per_task', $report['rows'][0]);
        (new RunStateMachine)->mark($runId, RunStateMachine::REPORTED);

        $state = (new RunStateMachine)->current($runId);
        $this->assertSame(RunStateMachine::REPORTED, $state['state']);
    }
}
