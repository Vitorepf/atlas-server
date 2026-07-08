<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Governance;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskCommitVerificationGate;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskCommitGovernanceChain;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskGovernancePolicyPlane;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskPostLandCanarySentinel;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtVerdictLedger;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasTaskPostLandCanarySentinelTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->envFile = sys_get_temp_dir().'/atlas-canary-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
        AtlasTaskServingSwitch::on();
        // Isolate the serving stack: without this, enqueue writes into the LIVE queue disk and the
        // template-farm similarity guard trips on residue from prior test runs.
        config()->set('atlas.task_serving.queue_disk', 'atlas_serving_canary_test');
        Storage::fake('atlas_serving_canary_test');
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        AtlasTaskServingSwitch::$envPathOverride = null;
        @unlink($this->envFile);
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    private function tempPath(string $suffix): string
    {
        $path = rtrim(sys_get_temp_dir(), '/').'/atlas-canary-'.$suffix.'-'.bin2hex(random_bytes(5));
        $this->dirs[] = $path;

        return $path;
    }

    /** A fake runner classifying by command verb, returning canned {ran,ok,out} — mirrors AtlasTaskCommitVerificationGateTest. */
    private function runner(array $map): callable
    {
        return function (array $cmd, string $_cwd, float $_t) use ($map): array {
            $kind = in_array('-l', $cmd, true) ? 'lint' : (in_array('about', $cmd, true) ? 'boot' : (in_array('test', $cmd, true) ? 'test' : 'other'));

            return $map[$kind] ?? ['ran' => true, 'ok' => true, 'out' => ''];
        };
    }

    private function sentinel(callable $runner, ?string $ledgerPath = null): AtlasTaskPostLandCanarySentinel
    {
        $gate = new AtlasTaskCommitVerificationGate(null, $runner);
        $ledger = new AtlasMergeGovernorReleaseDecisionLedger($ledgerPath ?? $this->tempPath('ledger').'.jsonl');

        return new AtlasTaskPostLandCanarySentinel($gate, $ledger, static fn (): string => '2026-07-01T00:00:00+00:00');
    }

    // ── (a) green checks => canary_pass ─────────────────────────────────────

    public function test_green_checks_produce_canary_pass_receipt(): void
    {
        $ledgerPath = $this->tempPath('ledger').'.jsonl';
        $sentinel = $this->sentinel($this->runner([
            'boot' => ['ran' => true, 'ok' => true, 'out' => ''],
        ]), $ledgerPath);

        $result = $sentinel->observe('task-green-1', 'abc123', ['app/Services/Foo.php']);

        $this->assertSame('canary_pass', $result['verdict']);
        $this->assertSame([], $result['revert_candidate']);
        $this->assertSame('ok', $result['ledger_status']);

        $rows = array_filter(array_map('json_decode', file($ledgerPath) ?: [], array_fill(0, count(file($ledgerPath) ?: []), true)));
        $this->assertNotEmpty($rows);
        $last = end($rows);
        $this->assertSame('admitted', $last['decision']);
        $this->assertContains('canary_pass', $last['reasons']);
    }

    // ── (b) attributable red => canary_fail_attributed + revert_candidate ──

    public function test_attributable_red_produces_canary_fail_attributed_with_revert_candidate(): void
    {
        $repo = $this->tempPath('repo');
        File::ensureDirectoryExists($repo.'/app/Services/Ai/SelfConstruction');
        File::put($repo.'/app/Services/Ai/SelfConstruction/BrokenOrgan.php', "<?php\nclass BrokenOrgan {}\n");

        $gate = new AtlasTaskCommitVerificationGate($repo, $this->runner([
            'lint' => ['ran' => true, 'ok' => false, 'out' => 'PHP Parse error: syntax error in BrokenOrgan.php'],
        ]));
        $ledgerPath = $this->tempPath('ledger').'.jsonl';
        $ledger = new AtlasMergeGovernorReleaseDecisionLedger($ledgerPath);
        $sentinel = new AtlasTaskPostLandCanarySentinel($gate, $ledger, static fn (): string => '2026-07-01T00:00:00+00:00');

        $result = $sentinel->observe('task-red-attributed', 'deadbeef', ['app/Services/Ai/SelfConstruction/BrokenOrgan.php']);

        $this->assertSame('canary_fail_attributed', $result['verdict']);
        $this->assertSame('task-red-attributed', $result['revert_candidate']['task_packet_id']);
        $this->assertSame('deadbeef', $result['revert_candidate']['commit_sha']);
        $this->assertSame('php artisan atlas:task:revert --task=task-red-attributed', $result['revert_candidate']['suggested_command']);

        $rows = array_map('json_decode', file($ledgerPath), array_fill(0, count(file($ledgerPath)), true));
        $last = end($rows);
        $this->assertSame('blocked', $last['decision']);
        $this->assertContains('canary_fail_attributed', $last['reasons']);
    }

    // ── (c) unattributable red => canary_inconclusive, empty candidate ─────

    public function test_unattributable_red_produces_canary_inconclusive_with_empty_candidate(): void
    {
        $ledgerPath = $this->tempPath('ledger').'.jsonl';
        $sentinel = $this->sentinel($this->runner([
            // boot fails but output never mentions the changed file — unattributed => gate fails OPEN (passed=true, fail_open_reason set).
            'boot' => ['ran' => true, 'ok' => false, 'out' => 'Fatal error: something totally unrelated broke elsewhere.php'],
        ]), $ledgerPath);

        $result = $sentinel->observe('task-red-unattributed', 'cafef00d', ['app/Services/Ai/SelfConstruction/SomeOrgan.php']);

        $this->assertSame('canary_inconclusive', $result['verdict']);
        $this->assertSame([], $result['revert_candidate']);

        $rows = array_map('json_decode', file($ledgerPath), array_fill(0, count(file($ledgerPath)), true));
        $last = end($rows);
        $this->assertSame('repair_required', $last['decision']);
        $this->assertContains('canary_inconclusive', $last['reasons']);
    }

    // ── (d) canary_enabled=false (default) => zero canary receipts on the report path ──

    public function test_default_canary_disabled_emits_zero_receipts_on_serving_report_path(): void
    {
        $repo = $this->tempPath('report-repo');
        File::ensureDirectoryExists($repo);
        (new Process(['git', 'init'], $repo))->run();

        $relFile = 'app/Services/Foo/CanaryWireTarget.php';
        File::ensureDirectoryExists($repo.'/'.dirname($relFile));
        File::put($repo.'/'.$relFile, "<?php\nclass CanaryWireTarget {}\n");

        $taskId = 'canary-off-'.bin2hex(random_bytes(4));
        $exit = Artisan::call('atlas:task:enqueue', [
            '--objective' => 'wire the CanaryWireTarget probe fixture '.$taskId.' into the registry for the post-land canary sentinel test',
            '--allow' => [$relFile],
            '--accept' => ['the CanaryWireTarget probe fixture '.$taskId.' resolves from the container'],
            '--evidence' => ['tests_or_gates_result'],
            '--id' => $taskId,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit, Artisan::output());

        $orchestrator = new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            new AgentControlPlaneTaskPacketQueueRepository('atlas_serving_canary_test'),
            new AgentControlPlaneClaimLeaseRepository('atlas_serving_canary_test'),
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );

        $spySentinel = new class extends AtlasTaskPostLandCanarySentinel
        {
            public int $observeCalls = 0;

            public function __construct() {}

            public function observe(string $taskPacketId, string $commitSha, array $allowedFiles): array
            {
                $this->observeCalls++;

                return ['verdict' => 'canary_pass', 'revert_candidate' => [], 'checks' => [], 'ledger_status' => 'ok'];
            }
        };

        $releaseLedgerPath = $this->tempPath('release-ledger').'.jsonl';
        $verdictLedgerPath = $this->tempPath('verdict-ledger').'.jsonl';

        $serving = new AtlasTaskServingService(
            $orchestrator,
            null,
            null,
            new AtlasTaskScopedCommitter(null, $repo),
            new AtlasTaskCommitVerificationGate($repo, $this->runner([])),
            new AtlasTaskCommitGovernanceChain(
                null,
                null,
                null,
                new AtlasVerificationCourtVerdictLedger($verdictLedgerPath),
                new AtlasMergeGovernorReleaseDecisionLedger($releaseLedgerPath),
            ),
            $spySentinel,
            new AtlasTaskGovernancePolicyPlane([]), // canary_enabled absent => default false
        );

        $served = $serving->next('client-'.$taskId);
        $this->assertSame('served', $served['status']);

        $result = $serving->report('client-'.$taskId, (string) $served['task']['task_packet_id'], (string) $served['task']['lease_id'], [
            'outcome' => 'success',
            'commit' => true,
        ]);

        $this->assertSame('resolved', $result['status'], (string) json_encode($result));
        $this->assertSame(0, $spySentinel->observeCalls, 'canary sentinel must never be invoked when canary_enabled is false');
    }
}
