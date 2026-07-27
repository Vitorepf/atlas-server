<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\EngineeringKernel\BudgetMeter;
use App\Services\Ai\SelfConstruction\AtlasTaskCommitVerificationGate;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskCommitGovernanceChain;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

/**
 * Proves AtlasTaskServingService emits exactly one usage fact through the injected BudgetMeter
 * per resolved commit, zero facts on give_back/commit_failed paths, and never lets a throwing
 * meter break the report envelope.
 */
final class AtlasTaskServingBudgetMeterConsumerTest extends TestCase
{
    use MakesAgentControlPlaneTaskQueueOrchestrator;
    private string $envFile = '';

    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-budgetmeter-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
        AtlasTaskServingSwitch::on();

        $this->repo = sys_get_temp_dir().'/atlas-budgetmeter-repo-'.bin2hex(random_bytes(5));
        @mkdir($this->repo, 0775, true);
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
        File::deleteDirectory($this->repo);
        parent::tearDown();
    }

    private function recordingMeter(array &$calls): BudgetMeter
    {
        return new class($calls) implements BudgetMeter
        {
            public function __construct(private array &$calls) {}

            public function measure(array $usage): array
            {
                $this->calls[] = $usage;

                return $usage;
            }

            public function summarize(array $filters = []): array
            {
                return [];
            }
        };
    }

    private function throwingMeter(): BudgetMeter
    {
        return new class implements BudgetMeter
        {
            public function measure(array $usage): array
            {
                throw new \RuntimeException('meter blew up');
            }

            public function summarize(array $filters = []): array
            {
                return [];
            }
        };
    }

    /** @return array{client:string, task_packet_id:string, lease_id:string} */
    private function servedTask(string $id, string $file = 'app/Services/Foo/Bar.php'): array
    {
        $exit = Artisan::call('atlas:task:enqueue', [
            '--objective' => 'wire the FooBar into the registry',
            '--allow' => [$file],
            '--accept' => ['the FooBar resolves from the container'],
            '--evidence' => ['tests_or_gates_result'],
            '--id' => $id,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $serving = new AtlasTaskServingService($this->orchestrator());
        $res = $serving->next('client-'.$id);
        $this->assertSame('served', $res['status']);

        @mkdir(\dirname($this->repo.'/'.$file), 0775, true);
        @file_put_contents($this->repo.'/'.$file, "<?php // {$id}\n");

        return [
            'client' => 'client-'.$id,
            'task_packet_id' => (string) $res['task']['task_packet_id'],
            'lease_id' => (string) $res['task']['lease_id'],
        ];
    }

    private function servingService(BudgetMeter $meter): AtlasTaskServingService
    {
        $verifier = new AtlasTaskCommitVerificationGate(
            $this->repo,
            fn (array $cmd, string $cwd, float $timeout): array => ['ran' => true, 'ok' => true, 'out' => ''],
        );

        return new AtlasTaskServingService(
            orchestrator: $this->orchestrator($this->repo),
            sentinel: null,
            inspector: null,
            committer: new AtlasTaskScopedCommitter(null, $this->repo),
            verifier: $verifier,
            governance: new AtlasTaskCommitGovernanceChain(modeOverride: AtlasTaskCommitGovernanceChain::MODE_OFF),
            budgetMeter: $meter,
        );
    }

    // ── one fact per resolved commit ────────────────────────────────────────

    public function test_resolved_commit_emits_exactly_one_usage_fact(): void
    {
        $served = $this->servedTask('bm-resolved');
        $calls = [];
        $serving = $this->servingService($this->recordingMeter($calls));

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
            'commit' => true,
        ]);

        $this->assertSame('resolved', $result['status']);
        $this->assertCount(1, $calls);
        $fact = $calls[0];
        $this->assertSame($served['task_packet_id'], $fact['task_packet_id']);
        $this->assertSame($served['client'], $fact['agent_id']);
        $this->assertArrayHasKey('files_committed_count', $fact);
        $this->assertArrayHasKey('verification_checks_run', $fact);
        $this->assertArrayHasKey('wall_seconds', $fact);
        $this->assertGreaterThanOrEqual(1, $fact['files_committed_count']);
        $this->assertArrayNotHasKey('tokens_in', $fact);
        $this->assertArrayNotHasKey('tokens_out', $fact);
        $this->assertArrayNotHasKey('cost_cents', $fact);
        $this->assertArrayNotHasKey('provider', $fact);
        $this->assertArrayNotHasKey('model', $fact);
    }

    // ── zero facts on give_back ─────────────────────────────────────────────

    public function test_give_back_emits_zero_usage_facts(): void
    {
        $served = $this->servedTask('bm-giveback');
        $calls = [];
        $serving = $this->servingService($this->recordingMeter($calls));

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'give_back',
        ]);

        $this->assertSame('reported', $result['status']);
        $this->assertSame([], $calls);
    }

    // ── zero facts on commit_failed (server verification blocks the commit) ──

    public function test_commit_failed_emits_zero_usage_facts(): void
    {
        $served = $this->servedTask('bm-commitfailed');
        $calls = [];

        $failingVerifier = new AtlasTaskCommitVerificationGate(
            $this->repo,
            fn (array $cmd, string $cwd, float $timeout): array => ['ran' => true, 'ok' => false, 'out' => 'boom'],
        );
        $serving = new AtlasTaskServingService(
            orchestrator: $this->orchestrator($this->repo),
            sentinel: null,
            inspector: null,
            committer: new AtlasTaskScopedCommitter(null, $this->repo),
            verifier: $failingVerifier,
            governance: new AtlasTaskCommitGovernanceChain(modeOverride: AtlasTaskCommitGovernanceChain::MODE_OFF),
            budgetMeter: $this->recordingMeter($calls),
        );

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
            'commit' => true,
        ]);

        $this->assertSame('commit_failed', $result['status']);
        $this->assertSame([], $calls);
    }

    // ── a throwing meter never breaks the resolved report envelope ─────────

    public function test_throwing_meter_still_yields_intact_resolved_envelope(): void
    {
        $served = $this->servedTask('bm-throwing');
        $serving = $this->servingService($this->throwingMeter());

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
            'commit' => true,
        ]);

        $this->assertSame('resolved', $result['status']);
        $this->assertTrue($result['lease_closed']);
    }

    // ── no meter (no override) never breaks the resolved report ────────────

    public function test_report_resolves_when_no_meter_is_injected(): void
    {
        $served = $this->servedTask('bm-default');
        $verifier = new AtlasTaskCommitVerificationGate(
            $this->repo,
            fn (array $cmd, string $cwd, float $timeout): array => ['ran' => true, 'ok' => true, 'out' => ''],
        );
        $serving = new AtlasTaskServingService(
            orchestrator: $this->orchestrator($this->repo),
            sentinel: null,
            inspector: null,
            committer: new AtlasTaskScopedCommitter(null, $this->repo),
            verifier: $verifier,
            governance: new AtlasTaskCommitGovernanceChain(modeOverride: AtlasTaskCommitGovernanceChain::MODE_OFF),
        );

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
            'commit' => true,
        ]);

        $this->assertSame('resolved', $result['status']);
    }

    /** @return array{code:int,out:string,err:string} */
    private function git(array $args): array
    {
        $p = new Process(array_merge(['git'], $args), $this->repo);
        $p->run();

        return ['code' => (int) $p->getExitCode(), 'out' => $p->getOutput(), 'err' => $p->getErrorOutput()];
    }

}
