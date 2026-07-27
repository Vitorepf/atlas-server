<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AtlasTaskCommitVerificationGate;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskCommitGovernanceChain;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskGovernancePolicyPlane;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtEvidenceContract;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

/**
 * Binds worker-supplied evidence to the Verification Court contract on the commit path:
 * observe records without blocking, enforce refuses a failing verdict (lease stays open),
 * off skips evaluation entirely, observe records evaluator failures, and enforce
 * refuses a commit when the Verification Court evaluator is unavailable.
 */
final class AtlasTaskServingEvidenceContractBindingTest extends TestCase
{
    use MakesAgentControlPlaneTaskQueueOrchestrator;
    private string $envFile = '';

    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-evctr-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
        AtlasTaskServingSwitch::on();

        $this->repo = sys_get_temp_dir().'/atlas-evctr-repo-'.bin2hex(random_bytes(5));
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

    /** @return array{client:string, task_packet_id:string, lease_id:string} */
    private function servedTask(string $id, string|array $files = 'app/Services/Foo/Bar.php'): array
    {
        $allowedFiles = is_array($files) ? array_values($files) : [$files];
        $exit = Artisan::call('atlas:task:enqueue', [
            '--objective' => 'wire the FooBar into the registry',
            '--allow' => $allowedFiles,
            '--accept' => ['the FooBar resolves from the container'],
            '--evidence' => ['tests_or_gates_result'],
            '--id' => $id,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $serving = new AtlasTaskServingService($this->orchestrator());
        $res = $serving->next('client-'.$id);
        $this->assertSame('served', $res['status']);

        foreach ($allowedFiles as $file) {
            @mkdir(\dirname($this->repo.'/'.$file), 0775, true);
            @file_put_contents($this->repo.'/'.$file, "<?php // {$id}\n");
        }

        return [
            'client' => 'client-'.$id,
            'task_packet_id' => (string) $res['task']['task_packet_id'],
            'lease_id' => (string) $res['task']['lease_id'],
        ];
    }

    /** Real committer against a throwaway repo, fake verifier runner (always ok), inert governance. */
    private function servingService(
        \Closure $evidenceContractEvaluator,
        ?AtlasTaskGovernancePolicyPlane $policyPlane = null,
        bool $bindSettlementRepository = true,
    ): AtlasTaskServingService
    {
        $verifier = new AtlasTaskCommitVerificationGate(
            $this->repo,
            fn (array $cmd, string $cwd, float $timeout): array => ['ran' => true, 'ok' => true, 'out' => ''],
        );

        return new AtlasTaskServingService(
            orchestrator: $this->orchestrator($bindSettlementRepository ? $this->repo : null),
            sentinel: null,
            inspector: null,
            committer: new AtlasTaskScopedCommitter(null, $this->repo),
            verifier: $verifier,
            governance: new AtlasTaskCommitGovernanceChain(modeOverride: AtlasTaskCommitGovernanceChain::MODE_OFF),
            canarySentinel: null,
            policyPlane: $policyPlane ?? new AtlasTaskGovernancePolicyPlane(['evidence_contract_mode' => 'observe']),
            evidenceContractEvaluator: $evidenceContractEvaluator,
        );
    }

    // ── (a) observe mode records the verdict and the commit proceeds ─────────

    public function test_observe_mode_records_evidence_contract_verdict_and_commit_proceeds(): void
    {
        $served = $this->servedTask('evctr-observe');
        $failingVerdict = ['schema' => AtlasVerificationCourtEvidenceContract::SCHEMA, 'accepted' => false, 'blockers' => ['missing:receipt_chain']];
        $serving = $this->servingService(
            static fn (array $allegation, array $evidence): array => $failingVerdict,
            new AtlasTaskGovernancePolicyPlane(['evidence_contract_mode' => 'observe']),
        );

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
            'commit' => true,
        ]);

        $this->assertSame('resolved', $result['status']);
        $this->assertTrue($result['lease_closed']);
        $this->assertSame($failingVerdict, $result['evidence_contract']);
    }

    public function test_evidence_contract_hashes_allowed_file_sets_without_delimiter_collisions_or_order_sensitivity(): void
    {
        $allegations = [];
        $serving = $this->servingService(
            static function (array $allegation, array $evidence) use (&$allegations): array {
                $allegations[$allegation['task_packet_id']] = $allegation;

                return ['schema' => AtlasVerificationCourtEvidenceContract::SCHEMA, 'accepted' => true, 'blockers' => []];
            },
            new AtlasTaskGovernancePolicyPlane(['evidence_contract_mode' => 'enforce']),
        );

        $hashes = [];
        foreach ([
            'first' => ['app/A.php', 'app/B.php,app/C.php'],
            'collision' => ['app/A.php,app/B.php', 'app/C.php'],
            'reordered' => ['app/B.php,app/C.php', 'app/A.php'],
        ] as $label => $files) {
            $served = $this->servedTask('evctr-hash-'.$label, $files);
            $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
                'outcome' => 'success',
                'commit' => true,
            ]);
            $this->assertSame('resolved', $result['status']);
            $hashes[$label] = $allegations[$served['task_packet_id']]['allowed_files_hash'];
        }

        $this->assertNotSame($hashes['first'], $hashes['collision']);
        $this->assertSame($hashes['first'], $hashes['reordered']);
    }

    // ── (b) enforce mode with failing evidence refuses the commit, lease stays open ──

    public function test_enforce_mode_with_failing_evidence_refuses_commit_and_keeps_lease(): void
    {
        $served = $this->servedTask('evctr-enforce-fail');
        $failingVerdict = ['schema' => AtlasVerificationCourtEvidenceContract::SCHEMA, 'accepted' => false, 'blockers' => ['missing:receipt_chain']];
        $serving = $this->servingService(
            static fn (array $allegation, array $evidence): array => $failingVerdict,
            new AtlasTaskGovernancePolicyPlane(['evidence_contract_mode' => 'enforce']),
        );

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
            'commit' => true,
        ]);

        $this->assertSame('commit_failed', $result['status']);
        $this->assertSame('evidence_contract_failed', $result['reason']);
        $this->assertFalse($result['lease_closed']);

        // The lease stays open: the worker fixes evidence and re-reports successfully.
        $servingRetry = $this->servingService(
            static fn (array $allegation, array $evidence): array => ['schema' => AtlasVerificationCourtEvidenceContract::SCHEMA, 'accepted' => true, 'blockers' => []],
            new AtlasTaskGovernancePolicyPlane(['evidence_contract_mode' => 'enforce']),
        );
        $retry = $servingRetry->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
            'commit' => true,
        ]);
        $this->assertSame('resolved', $retry['status']);
    }

    public function test_enforce_mode_with_passing_evidence_commits_without_optional_elite_dependencies(): void
    {
        $served = $this->servedTask('evctr-enforce-pass');
        $passingVerdict = ['schema' => AtlasVerificationCourtEvidenceContract::SCHEMA, 'accepted' => true, 'blockers' => []];
        $serving = $this->servingService(
            static fn (array $allegation, array $evidence): array => $passingVerdict,
            new AtlasTaskGovernancePolicyPlane(['evidence_contract_mode' => 'enforce']),
        );

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
            'commit' => true,
        ]);

        $this->assertSame('resolved', $result['status']);
        $this->assertSame($passingVerdict, $result['evidence_contract']);
    }

    public function test_landed_commit_with_blocked_settlement_is_not_reported_as_resolved(): void
    {
        $served = $this->servedTask('evctr-settlement-blocked');
        $passingVerdict = ['schema' => AtlasVerificationCourtEvidenceContract::SCHEMA, 'accepted' => true, 'blockers' => []];
        $serving = $this->servingService(
            static fn (array $allegation, array $evidence): array => $passingVerdict,
            new AtlasTaskGovernancePolicyPlane(['evidence_contract_mode' => 'enforce']),
            bindSettlementRepository: false,
        );

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
            'commit' => true,
        ]);

        $this->assertSame('settlement_failed', $result['status']);
        $this->assertSame('task_settlement_failed', $result['reason']);
        $this->assertFalse($result['lease_closed']);
        $this->assertSame('resolve_blocked', $result['settlement']['event']);
        $this->assertSame('commit_not_found', $result['settlement']['reason']);
    }

    // ── (c) off mode skips evaluation entirely ────────────────────────────────

    public function test_off_mode_skips_evaluation_entirely(): void
    {
        $served = $this->servedTask('evctr-off');
        $called = 0;
        $serving = $this->servingService(
            static function (array $allegation, array $evidence) use (&$called): array {
                $called++;

                return ['schema' => AtlasVerificationCourtEvidenceContract::SCHEMA, 'accepted' => false, 'blockers' => []];
            },
            new AtlasTaskGovernancePolicyPlane(['evidence_contract_mode' => 'off']),
        );

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
            'commit' => true,
        ]);

        $this->assertSame('resolved', $result['status']);
        $this->assertSame(0, $called, 'off mode must never invoke the evaluator');
        $this->assertArrayNotHasKey('evidence_contract', $result);
    }

    // ── (d) evaluator failures are honest and enforce fails closed ───────────

    public function test_contract_evaluation_exception_is_fail_open_and_error_is_recorded(): void
    {
        $served = $this->servedTask('evctr-exception');
        $serving = $this->servingService(
            static function (array $allegation, array $evidence): array {
                throw new \RuntimeException('contract evaluator blew up');
            },
            new AtlasTaskGovernancePolicyPlane(['evidence_contract_mode' => 'observe']),
        );

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
            'commit' => true,
        ]);

        $this->assertSame('resolved', $result['status'], 'a contract-evaluation exception must never wedge the report');
        $this->assertTrue($result['lease_closed']);
        $this->assertSame('contract evaluator blew up', $result['evidence_contract']['error']);
    }

    public function test_contract_evaluation_exception_in_enforce_mode_fails_closed(): void
    {
        $served = $this->servedTask('evctr-exception-enforce');
        $serving = $this->servingService(
            static function (array $allegation, array $evidence): array {
                throw new \RuntimeException('boom');
            },
            new AtlasTaskGovernancePolicyPlane(['evidence_contract_mode' => 'enforce']),
        );

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
            'commit' => true,
        ]);

        $this->assertSame('commit_failed', $result['status']);
        $this->assertSame('evidence_contract_failed', $result['reason']);
        $this->assertFalse($result['lease_closed']);
        $this->assertFalse($result['evidence_contract']['accepted']);
        $this->assertSame(['evidence_contract_evaluator_unavailable'], $result['evidence_contract']['blockers']);
        $this->assertSame('boom', $result['evidence_contract']['error']);
    }

    // ── AtlasVerificationCourtEvidenceContract is composed, not reimplemented ──

    public function test_default_evaluator_composes_the_real_evidence_contract_class(): void
    {
        $served = $this->servedTask('evctr-default-composed');
        // No evidenceContractEvaluator override -> uses the default closure wrapping the real class.
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
            canarySentinel: null,
            policyPlane: new AtlasTaskGovernancePolicyPlane(['evidence_contract_mode' => 'observe']),
        );

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
            'commit' => true,
        ]);

        $this->assertSame('resolved', $result['status']);
        // No evidence supplied by the worker -> the real contract's own missing:receipt_chain blocker.
        $this->assertSame(AtlasVerificationCourtEvidenceContract::SCHEMA, $result['evidence_contract']['schema']);
        $this->assertFalse($result['evidence_contract']['accepted']);
        $this->assertContains('missing:receipt_chain', $result['evidence_contract']['blockers']);
    }

    /** @return array{code:int,out:string,err:string} */
    private function git(array $args): array
    {
        $p = new Process(array_merge(['git'], $args), $this->repo);
        $p->run();

        return ['code' => (int) $p->getExitCode(), 'out' => $p->getOutput(), 'err' => $p->getErrorOutput()];
    }

}
