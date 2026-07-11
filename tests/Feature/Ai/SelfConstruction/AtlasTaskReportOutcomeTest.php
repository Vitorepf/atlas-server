<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AtlasTaskCommitVerificationGate;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskCommitGovernanceChain;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesAemorTables;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

/**
 * OUTC-01(b): Autônomos report() writes server-side verified outcomes, not worker claims.
 */
final class AtlasTaskReportOutcomeTest extends TestCase
{
    use CreatesAemorTables;
    use MakesAgentControlPlaneTaskQueueOrchestrator;

    private string $envFile = '';

    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->createAemorTables();
        (require database_path('migrations/2026_07_09_153500_repair_missing_ai_memory_deltas_table.php'))->up();
        (require database_path('migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php'))->up();

        $this->envFile = sys_get_temp_dir().'/atlas-report-outcome-'.bin2hex(random_bytes(4)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
        AtlasTaskServingSwitch::on();
        config()->set('atlas.aemor.engineering_outcome_enabled', true);

        $this->repo = sys_get_temp_dir().'/atlas-report-outcome-repo-'.bin2hex(random_bytes(4));
        @mkdir($this->repo, 0o755, true);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'test@atlas.local']);
        $this->git(['config', 'user.name', 'Atlas Test']);
        @file_put_contents($this->repo.'/README.md', "seed\n");
        $this->git(['add', 'README.md']);
        $this->git(['commit', '-q', '-m', 'seed']);
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        AtlasTaskServingSwitch::$envPathOverride = null;
        @unlink($this->envFile);
        Schema::dropIfExists('ai_run_outcomes');
        Schema::dropIfExists('ai_memory_deltas');
        $this->dropAemorTables();
        File::deleteDirectory($this->repo);
        parent::tearDown();
    }

    /** @return array{client:string, task_packet_id:string, lease_id:string} */
    private function servedTask(string $id, string $file = 'docs/engineering-knowledge-base/outcome-spine-test.md'): array
    {
        $exit = Artisan::call('atlas:task:enqueue', [
            '--objective' => 'wire outcome spine test',
            '--allow' => [$file],
            '--accept' => ['outcome spine doc'],
            '--evidence' => ['tests_or_gates_result'],
            '--id' => $id,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $serving = new AtlasTaskServingService($this->orchestrator());
        $res = $serving->next('client-'.$id);
        $this->assertSame('served', $res['status']);

        @mkdir(dirname($this->repo.'/'.$file), 0o755, true);
        @file_put_contents($this->repo.'/'.$file, "# Outcome spine\n\n{$id}\n");

        return [
            'client' => 'client-'.$id,
            'task_packet_id' => (string) $res['task']['task_packet_id'],
            'lease_id' => (string) $res['task']['lease_id'],
        ];
    }

    public function test_landed_commit_creates_verified_ai_run_outcome_and_aemor_episode(): void
    {
        $served = $this->servedTask('outc-landed');
        $serving = new AtlasTaskServingService(
            $this->orchestrator(),
            committer: new AtlasTaskScopedCommitter(null, $this->repo),
            verifier: new AtlasTaskCommitVerificationGate(
                $this->repo,
                static fn (array $cmd, string $cwd, float $timeout): array => ['ran' => true, 'ok' => true, 'out' => 'env ok'],
            ),
            governance: new AtlasTaskCommitGovernanceChain(modeOverride: AtlasTaskCommitGovernanceChain::MODE_OFF),
        );

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
            'commit' => true,
        ]);

        $this->assertSame('resolved', $result['status'], json_encode($result));
        $this->assertDatabaseCount('atlas_aemor_execution_episodes', 1);
        $this->assertDatabaseCount('ai_run_outcomes', 1);
        $this->assertSame('atlas_autonomos', DB::table('ai_run_outcomes')->value('flow_id'));
        $this->assertSame('passed', DB::table('ai_run_outcomes')->value('outcome_status'));
        $this->assertSame('recorded', data_get($result, 'outcome_spine.status'));
    }

    public function test_unconfirmed_dry_run_report_records_verified_false(): void
    {
        $served = $this->servedTask('outc-dry-run');
        $serving = new AtlasTaskServingService($this->orchestrator());

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
        ]);

        $this->assertSame('reported', $result['status']);
        $this->assertFalse((bool) ($result['verified'] ?? true));
        $this->assertDatabaseCount('atlas_aemor_execution_episodes', 1);
        $this->assertSame('blocked', DB::table('atlas_aemor_outcomes')->value('status'));
        $this->assertSame('blocked', DB::table('ai_run_outcomes')->value('outcome_status'));
    }

    /** @param list<string> $argv */
    private function git(array $argv): void
    {
        (new Process(array_merge(['git'], $argv), $this->repo))->run();
    }
}
