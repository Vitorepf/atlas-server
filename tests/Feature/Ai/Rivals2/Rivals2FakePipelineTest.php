<?php

namespace Tests\Feature\Ai\Rivals2;

use App\Services\Ai\Rivals2\Adapters\LocalFakeSuiteAdapter;
use App\Services\Ai\Rivals2\Core\Adjudicator;
use App\Services\Ai\Rivals2\Core\ArmRegistry;
use App\Services\Ai\Rivals2\Core\EvidencePackBuilder;
use App\Services\Ai\Rivals2\Core\ReplayVerifier;
use App\Services\Ai\Rivals2\Core\ReportBuilder;
use App\Services\Ai\Rivals2\Core\ResultLedger;
use App\Services\Ai\Rivals2\Core\RunPlan;
use App\Services\Ai\Rivals2\Support\RunPaths;
use Tests\TestCase;

class Rivals2FakePipelineTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals2_pipeline_test_'.uniqid();
        config()->set('atlas_rivals2.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storage)) {
            exec('rm -rf '.escapeshellarg($this->storage));
        }
        parent::tearDown();
    }

    private function runPipeline(int $repetitions = 3): string
    {
        $adapter = new LocalFakeSuiteAdapter;
        $arm = (new ArmRegistry)->makeArm('local_fake_model', 'bare');
        $plan = RunPlan::make(
            $adapter->suiteId(),
            array_column($adapter->listCases(), 'case_id'),
            [$arm],
            $repetitions,
            ['max_usd' => 0.0, 'max_minutes' => 5],
            42,
        );
        $runId = $plan->persist();
        $adapter->execute($plan);
        (new EvidencePackBuilder)->build($runId);

        return $runId;
    }

    public function test_clean_pipeline_allows_scoped_claim(): void
    {
        $runId = $this->runPipeline(repetitions: 3);

        $verify = (new ReplayVerifier)->verify($runId);
        $this->assertTrue($verify['verified'], implode(',', $verify['failures']));

        $adjudication = (new Adjudicator)->adjudicate($runId);
        $this->assertSame('valid', $adjudication['verdict']);
        $this->assertTrue($adjudication['claim_allowed']);
        $this->assertSame([], $adjudication['claim_blockers']);
        $this->assertSame('local_fake', $adjudication['claim_scope']['suite']);
        $this->assertSame(3, $adjudication['claim_scope']['repetitions']);

        $entry = (new ResultLedger)->append($runId, $adjudication);
        $this->assertTrue((new ResultLedger)->verifyChain()['verified']);
        $this->assertSame($runId, $entry['run_id']);

        $report = (new ReportBuilder)->build($runId);
        $this->assertTrue($report['claim_allowed']);
        // 2 task_types × 1 arm = 2 linhas; nunca um "best overall"
        $this->assertCount(2, $report['rows']);
        $this->assertArrayNotHasKey('winner', $report);
        $this->assertArrayNotHasKey('overall_score', $report);

        $patchRow = collect($report['rows'])->firstWhere('task_type', 'coding_patch');
        // 3 cases × 3 reps: ok=3, flaky=2 (reps ímpares), fail=0 → 5/9
        $this->assertSame(9, $patchRow['n']);
        $this->assertEqualsWithDelta(5 / 9, $patchRow['success_rate'], 0.001);
    }

    public function test_tampering_one_byte_of_artifact_kills_claim(): void
    {
        $runId = $this->runPipeline();

        // adultera 1 byte de um artifact APÓS o evidence pack
        $artifacts = glob(RunPaths::artifactsDir($runId).'/*.txt');
        $target = $artifacts[0];
        $content = file_get_contents($target);
        file_put_contents($target, substr($content, 0, -2).'X');

        $verify = (new ReplayVerifier)->verify($runId);
        $this->assertFalse($verify['verified']);
        $this->assertNotEmpty(preg_grep('/artifact_hash_mismatch/', $verify['failures']));

        $adjudication = (new Adjudicator)->adjudicate($runId);
        $this->assertSame('invalid', $adjudication['verdict']);
        $this->assertFalse($adjudication['claim_allowed']);

        $report = (new ReportBuilder)->build($runId);
        $this->assertFalse($report['claim_allowed']);
        $this->assertNotEmpty($report['claim_blockers']);
    }

    public function test_insufficient_repetitions_block_claim(): void
    {
        $runId = $this->runPipeline(repetitions: 1);

        $adjudication = (new Adjudicator)->adjudicate($runId);
        $this->assertFalse($adjudication['claim_allowed']);
        $this->assertNotEmpty(preg_grep('/repetitions_below_min/', $adjudication['claim_blockers']));
    }

    public function test_missing_receipt_blocks_claim(): void
    {
        $runId = $this->runPipeline();

        // remove o último receipt (simula execução incompleta) e refaz o pack
        $path = RunPaths::receiptsPath($runId);
        $lines = array_filter(explode(PHP_EOL, file_get_contents($path)));
        array_pop($lines);
        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);
        (new EvidencePackBuilder)->build($runId);

        $adjudication = (new Adjudicator)->adjudicate($runId);
        $this->assertFalse($adjudication['claim_allowed']);
        $this->assertNotEmpty(preg_grep('/missing_receipt/', $adjudication['claim_blockers']));
    }

    public function test_timeout_and_failure_cases_are_measured_not_hidden(): void
    {
        $runId = $this->runPipeline();
        $report = (new ReportBuilder)->build($runId);

        $bugRow = collect($report['rows'])->firstWhere('task_type', 'bug_investigation');
        // ok=3, timeout=0, fail=0 → 3/9: falha é medição, não é escondida
        $this->assertEqualsWithDelta(3 / 9, $bugRow['success_rate'], 0.001);
    }
}
