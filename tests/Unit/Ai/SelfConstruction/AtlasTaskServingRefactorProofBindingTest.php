<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AtlasTaskCommitVerificationGate;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskCommitGovernanceChain;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskGovernancePolicyPlane;
use App\Services\Ai\SelfConstruction\TaskServing\AtlasRefactorProofGate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

/**
 * Proves the refactor delta proof binding on the serving report commit path:
 * observe records the proof (envelope + receipt) and never blocks; enforce
 * refuses a non-improving refactor delivery keeping the lease, and admits a
 * genuinely improving one; a non-refactor objective is never proved. Runs
 * against a REAL throwaway git repo — before = HEAD, after = worker delivery.
 */
final class AtlasTaskServingRefactorProofBindingTest extends TestCase
{
    use MakesAgentControlPlaneTaskQueueOrchestrator;
    private string $envFile = '';

    private string $repo = '';

    /** A before-state with a duplicated 6-line pipeline in two functions. */
    private const BEFORE_DUPLICATED = <<<'PHP'
    <?php
    class Report {
        function daily($id) {
            $a = load($id);
            if ($a === null) { throw new RuntimeException('missing'); }
            $a->normalize();
            $a->validate();
            $a->stamp();
            save($a);
            return 'daily';
        }
        function weekly($id) {
            $a = load($id);
            if ($a === null) { throw new RuntimeException('missing'); }
            $a->normalize();
            $a->validate();
            $a->stamp();
            save($a);
            return 'weekly';
        }
    }
    PHP;

    /** The worker's genuine simplification: pipeline extracted, callers shrink. */
    private const AFTER_IMPROVED = <<<'PHP'
    <?php
    class Report {
        function pipeline($id) {
            $a = load($id);
            if ($a === null) { throw new RuntimeException('missing'); }
            $a->normalize();
            $a->validate();
            $a->stamp();
            save($a);
        }
        function daily($id) { $this->pipeline($id); return 'daily'; }
        function weekly($id) { $this->pipeline($id); return 'weekly'; }
    }
    PHP;

    /** A fake refactor: everything kept, plus a wrapper function bolted on. */
    private const AFTER_FAKE = self::BEFORE_DUPLICATED."\nfunction dailyWrapper(\$id) { return daily(\$id); }\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-rfp-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
        AtlasTaskServingSwitch::on();

        $this->repo = sys_get_temp_dir().'/atlas-rfp-repo-'.bin2hex(random_bytes(5));
        @mkdir($this->repo, 0775, true);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'test@atlas.local']);
        $this->git(['config', 'user.name', 'Atlas Test']);
        @mkdir($this->repo.'/app', 0775, true);
        @file_put_contents($this->repo.'/app/Report.php', self::BEFORE_DUPLICATED."\n");
        $this->git(['add', '-A']);
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

    // ── observe: non-improving delivery is RECORDED, never blocked ──────────────

    public function test_observe_records_proof_on_envelope_and_receipt_without_blocking(): void
    {
        $served = $this->servedRefactorTask('rfp-observe', self::AFTER_FAKE);
        $serving = $this->servingService('observe');

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success', 'commit' => true,
        ]);

        $this->assertSame('resolved', $result['status'], json_encode($result));
        $this->assertFalse($result['refactor_proof']['improved']);
        $this->assertContains('wrapper_only', $result['refactor_proof']['flags']);

        $record = (new AgentControlPlaneTaskPacketQueueRepository)->get($served['task_packet_id']);
        $kinds = array_column((array) ($record['receipts'] ?? []), 'receipt_kind');
        $this->assertContains('refactor_delta_proof', $kinds, json_encode($kinds));
    }

    // ── enforce: fake refactor refused, lease kept ──────────────────────────────

    public function test_enforce_refuses_non_improving_refactor_and_keeps_lease(): void
    {
        $served = $this->servedRefactorTask('rfp-enforce-fake', self::AFTER_FAKE);
        $serving = $this->servingService('enforce');

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success', 'commit' => true,
        ]);

        $this->assertSame('commit_failed', $result['status'], json_encode($result));
        $this->assertSame('refactor_delta_refused', $result['reason']);
        $this->assertFalse($result['lease_closed']);
        $this->assertFalse($result['refactor_proof']['improved']);
    }

    // ── enforce: genuine improvement is admitted and committed ──────────────────

    public function test_enforce_admits_a_genuinely_improving_refactor(): void
    {
        $served = $this->servedRefactorTask('rfp-enforce-real', self::AFTER_IMPROVED);
        $serving = $this->servingService('enforce');

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success', 'commit' => true,
        ]);

        $this->assertSame('resolved', $result['status'], json_encode($result));
        $this->assertTrue($result['refactor_proof']['improved']);
        $this->assertLessThan(0, $result['refactor_proof']['delta']['loc']);
    }

    // ── a non-refactor objective is never proved ────────────────────────────────

    public function test_non_refactor_objective_is_not_proved(): void
    {
        $served = $this->servedTask('rfp-nonrefactor', 'wire the Report into the registry', self::AFTER_FAKE);
        $serving = $this->servingService('enforce');

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success', 'commit' => true,
        ]);

        $this->assertSame('resolved', $result['status'], json_encode($result));
        $this->assertArrayNotHasKey('refactor_proof', $result);
    }

    /** @return array{client:string, task_packet_id:string, lease_id:string} */
    private function servedRefactorTask(string $id, string $delivery): array
    {
        return $this->servedTask($id, 'refactor the Report class to remove the duplicated pipeline', $delivery);
    }

    /** @return array{client:string, task_packet_id:string, lease_id:string} */
    private function servedTask(string $id, string $objective, string $delivery): array
    {
        $exit = Artisan::call('atlas:task:enqueue', [
            '--objective' => $objective.' ('.$id.')',
            '--allow' => ['app/Report.php'],
            '--accept' => ['the Report class behavior is preserved for '.$id],
            '--evidence' => ['tests_or_gates_result'],
            '--id' => $id,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $serving = $this->servingService('off');
        $res = $serving->next('client-'.$id);
        $this->assertSame('served', $res['status'], json_encode($res));

        // The worker's in-tree delivery on the throwaway repo.
        @file_put_contents($this->repo.'/app/Report.php', $delivery."\n");

        return [
            'client' => 'client-'.$id,
            'task_packet_id' => (string) $res['task']['task_packet_id'],
            'lease_id' => (string) $res['task']['lease_id'],
        ];
    }

    private function servingService(string $refactorProofMode): AtlasTaskServingService
    {
        $verifier = new AtlasTaskCommitVerificationGate(
            $this->repo,
            fn (array $cmd, string $cwd, float $timeout): array => ['ran' => true, 'ok' => true, 'out' => ''],
        );

        return new AtlasTaskServingService(
            orchestrator: $this->orchestrator(),
            sentinel: null,
            inspector: null,
            committer: new AtlasTaskScopedCommitter(null, $this->repo),
            verifier: $verifier,
            governance: new AtlasTaskCommitGovernanceChain(modeOverride: AtlasTaskCommitGovernanceChain::MODE_OFF),
            canarySentinel: null,
            policyPlane: new AtlasTaskGovernancePolicyPlane([
                'evidence_contract_mode' => 'off',
                'refactor_proof_mode' => $refactorProofMode,
            ]),
            refactorProofGate: new AtlasRefactorProofGate($this->repo),
        );
    }

    /** @return array{code:int, out:string, err:string} */
    private function git(array $args): array
    {
        $p = new Process(array_merge(['git'], $args), $this->repo);
        $p->run();

        return ['code' => (int) $p->getExitCode(), 'out' => $p->getOutput(), 'err' => $p->getErrorOutput()];
    }

}
