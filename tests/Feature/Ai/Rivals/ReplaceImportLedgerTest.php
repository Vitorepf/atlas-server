<?php

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Rivals\Adapters\External\Tau2BenchAdapter;
use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\ClaimTier;
use App\Services\Ai\Rivals\Core\FrozenUnitManifest;
use App\Services\Ai\Rivals\Core\NativeExecutionManifest;
use App\Services\Ai\Rivals\Core\ResultLedger;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Core\RunStateMachine;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ReplaceImportLedgerTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_replace_import_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
        config()->set('atlas_rivals.enabled', true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_replace_import_supersedes_old_verdict_and_starts_new_revision(): void
    {
        $adapter = new Tau2BenchAdapter;
        $plan = RunPlan::make(
            $adapter->suiteId(),
            ['airline_task_012'],
            [(new ArmRegistry)->parse('claude_sonnet_5@bare', $adapter->suiteId())],
            2,
            ['max_usd' => 1.0, 'max_minutes' => 5],
            1,
            null,
            ClaimTier::DIAGNOSTIC,
        );
        $runId = $plan->persist();
        FrozenUnitManifest::fromPlan(RunPlan::load($runId), [['case_id' => 'airline_task_012']], true)->persist();
        NativeExecutionManifest::fromPlan($plan, $adapter, $adapter->planCommands($plan))->persist();
        (new RunStateMachine)->mark($runId, RunStateMachine::PLANNED);
        $source = base_path('tests/Fixtures/Rivals/tau2_bench_results.json');

        $this->artisan("atlas:rivals import-results --run={$runId} --file={$source} --json")
            ->assertExitCode(0);
        $this->artisan("atlas:rivals verify --run={$runId} --json")->assertExitCode(0);
        $this->artisan("atlas:rivals adjudicate --run={$runId} --json")->assertExitCode(0);
        $this->artisan("atlas:rivals report --run={$runId} --json")->assertExitCode(0);

        $this->artisan("atlas:rivals import-results --run={$runId} --file={$source} --json")
            ->expectsOutputToContain('import_already_exists')
            ->assertExitCode(1);
        $this->artisan("atlas:rivals import-results --run={$runId} --file={$source} --replace-import --json")
            ->expectsOutputToContain('"replaced": true')
            ->assertExitCode(0);

        $state = (new RunStateMachine)->current($runId);
        $this->assertSame(2, $state['revision']);
        $this->assertSame(RunStateMachine::EVIDENCE_BUILT, $state['state']);
        $entries = (new ResultLedger)->entries();
        $this->assertContains('supersede', array_column($entries, 'entry_type'));
        $this->assertTrue((new ResultLedger)->verifySemantic()['verified']);
        $this->assertFileDoesNotExist(RunPaths::adjudicationPath($runId));
        $this->assertFileDoesNotExist(RunPaths::reportPath($runId));
    }
}
