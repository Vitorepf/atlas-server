<?php

namespace Tests\Feature\Ai\AtlasDecide;

use App\Models\AiForgeIntake;
use App\Models\AiForgeLongHorizonState;
use App\Models\AiForgeWorkPacket;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\Programming\Forge\ForgeIntakeCanon;
use App\Services\Ai\Programming\Forge\ForgeIntakeService;
use App\Services\Ai\Programming\Forge\ForgeLongHorizonStateCanon;
use App\Services\Ai\Programming\Forge\ForgeLongHorizonStateService;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionCycleCanon;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionCycleService;
use App\Services\Ai\SelfConstruction\AtlasTaskCommitVerificationGate;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesForgeLongHorizonStateTable;
use Tests\TestCase;

final class LiveOutcomeProvenRealWritersTest extends TestCase
{
    use CreatesForgeLongHorizonStateTable;

    private string $repo = '';

    private string $log = '';

    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = sys_get_temp_dir().'/atlas-live-outcome-writers-'.bin2hex(random_bytes(5)).'.jsonl';
        $svc = new AtlasDecideLiveOutcomeFeedbackService;
        $svc->setLogPathForTesting($this->log);
        $this->app->instance(AtlasDecideLiveOutcomeFeedbackService::class, $svc);

        $this->repo = sys_get_temp_dir().'/atlas-live-outcome-repo-'.bin2hex(random_bytes(5));
        $this->dirs[] = $this->repo;
        @mkdir($this->repo, 0775, true);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'test@atlas.local']);
        $this->git(['config', 'user.name', 'Atlas Test']);
        @file_put_contents($this->repo.'/README.md', "seed\n");
        $this->git(['add', 'README.md']);
        $this->git(['commit', '-q', '-m', 'seed']);

        $this->createForgeLongHorizonStateTable();
        $this->reservationMigration()->up();
    }

    protected function tearDown(): void
    {
        $this->reservationMigration()->down();
        $this->dropForgeLongHorizonStateTable();
        foreach ($this->dirs as $dir) {
            File::deleteDirectory($dir);
        }
        @unlink($this->log);

        parent::tearDown();
    }

    public function test_autonomos_landing_writes_proven_real_live_feedback(): void
    {
        $files = ['app/Services/Ai/SelfConstruction/LiveOutcomeFoo.php', 'tests/Unit/Ai/SelfConstruction/LiveOutcomeFooTest.php'];
        $this->writeFile($files[0], "<?php\nclass LiveOutcomeFoo {}\n");
        $this->writeFile($files[1], "<?php\nclass LiveOutcomeFooTest {}\n");
        $verification = $this->verifyWithRunner($files, [
            'lint' => ['ran' => true, 'ok' => true, 'out' => ''],
            'boot' => ['ran' => true, 'ok' => true, 'out' => ''],
            'test' => ['ran' => true, 'ok' => true, 'out' => "OK (4 tests, 8 assertions)\n"],
        ]);

        $result = (new AtlasTaskScopedCommitter(null, $this->repo))
            ->commitScope($files, 'task-live-outcome', 'autonomos_worker', 'live outcome', $verification);

        $this->assertTrue($result['committed'], json_encode($result));
        $entry = $this->lastOutcome();
        $this->assertSame('programming', $entry['task_category']);
        $this->assertSame('autonomos_landing', $entry['role']);
        $this->assertSame('autonomos_worker', $entry['provider']);
        $this->assertSame('atlas_autonomos_landing', $entry['actor']);
        $this->assertTrue($entry['proven_real']);
        $this->assertSame(AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_SERVER_VERIFIED, $entry['verified_basis']);
        $this->assertSame('task-live-outcome', $entry['certified_receipt_id']);
        $this->assertSame(AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS, $entry['result']);
    }

    public function test_forge_completion_writes_proven_real_live_feedback(): void
    {
        [$intake, $packet, $state] = $this->forgeBootstrap();
        $cycles = app(ForgeWorkPacketExecutionCycleService::class);
        $cycle = $cycles->startCycle(
            $intake,
            $packet,
            $cycles->planExecution($packet, ['execution_mode' => ForgeWorkPacketExecutionCycleCanon::MODE_REAL]),
            $state,
        );

        $completed = $cycles->complete($cycle, [
            ['kind' => 'work_packet_receipts', 'ref' => 'wpr://live'],
            ['kind' => 'verification_receipt', 'ref' => 'vr://live'],
        ], $this->gateWithHarnessCapturedPhpunitEvidence(), $state);

        $this->assertSame(ForgeWorkPacketExecutionCycleCanon::STATUS_SUCCESS, $completed->status);
        $entry = $this->lastOutcome();
        $this->assertSame('programming', $entry['task_category']);
        $this->assertSame('forge_complete', $entry['role']);
        $this->assertSame('atlas_forge', $entry['provider']);
        $this->assertSame('atlas_forge_work_packet_complete', $entry['actor']);
        $this->assertTrue($entry['proven_real']);
        $this->assertSame(AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS, $entry['result']);
    }

    public function test_live_feedback_write_failure_is_fail_open_for_landing_and_forge_completion(): void
    {
        $badLog = sys_get_temp_dir().'/atlas-live-outcome-unwritable-'.bin2hex(random_bytes(5));
        @mkdir($badLog, 0775, true);
        $this->dirs[] = $badLog;
        $svc = new AtlasDecideLiveOutcomeFeedbackService;
        $svc->setLogPathForTesting($badLog);
        $this->app->instance(AtlasDecideLiveOutcomeFeedbackService::class, $svc);

        $files = ['docs/live-outcome.md'];
        $this->writeFile($files[0], "# live outcome\n");
        $verification = $this->verifyWithRunner($files, [
            'lint' => ['ran' => true, 'ok' => true, 'out' => ''],
            'boot' => ['ran' => true, 'ok' => true, 'out' => 'env ok'],
        ]);

        $landing = (new AtlasTaskScopedCommitter(null, $this->repo))
            ->commitScope($files, 'task-live-outcome-fail-open', 'autonomos_worker', 'live outcome fail open', $verification);
        $this->assertTrue($landing['committed'], json_encode($landing));

        [$intake, $packet, $state] = $this->forgeBootstrap();
        $cycles = app(ForgeWorkPacketExecutionCycleService::class);
        $cycle = $cycles->startCycle(
            $intake,
            $packet,
            $cycles->planExecution($packet, ['execution_mode' => ForgeWorkPacketExecutionCycleCanon::MODE_REAL]),
            $state,
        );
        $completed = $cycles->complete($cycle, [
            ['kind' => 'work_packet_receipts', 'ref' => 'wpr://fail-open'],
            ['kind' => 'verification_receipt', 'ref' => 'vr://fail-open'],
        ], $this->gateWithHarnessCapturedPhpunitEvidence(), $state);

        $this->assertSame(ForgeWorkPacketExecutionCycleCanon::STATUS_SUCCESS, $completed->status);
    }

    /**
     * @param  list<string>  $files
     * @param  array<string,array{ran:bool,ok:bool,out:string}>  $map
     * @return array<string,mixed>
     */
    private function verifyWithRunner(array $files, array $map): array
    {
        $runner = function (array $cmd, string $_cwd, float $_t) use ($map): array {
            $kind = in_array('-l', $cmd, true) ? 'lint' : (in_array('about', $cmd, true) ? 'boot' : (in_array('test', $cmd, true) ? 'test' : 'other'));

            return $map[$kind] ?? ['ran' => true, 'ok' => true, 'out' => ''];
        };

        return (new AtlasTaskCommitVerificationGate($this->repo, $runner))->verify($files, 'verify-live-outcome');
    }

    /**
     * @return array{0:AiForgeIntake,1:AiForgeWorkPacket,2:AiForgeLongHorizonState}
     */
    private function forgeBootstrap(): array
    {
        $intake = app(ForgeIntakeService::class)->intakeFromPrompt(
            'Implementar refactor multi-modulo do provider router; depois migrar billing engine.',
            [
                'workspace_slug' => 'atlas-server',
                'workspace_execution_gate' => [
                    'schema_version' => 'atlas.workspace_intelligence.execution_gate.v1',
                    'mode' => 'forge',
                    'workspace_id' => 'atlas-server',
                    'allowed' => true,
                    'status' => 'passed',
                    'blockers' => [],
                ],
            ],
        );
        $longHorizon = app(ForgeLongHorizonStateService::class);
        $state = $longHorizon->initializeForIntake($intake);
        $packet = app(ForgeWorkPacketExecutionCycleService::class)->selectPacket($intake, $state);
        $this->assertNotNull($packet);
        $state = $longHorizon->recordCycle($state, [
            'active_work_packets' => [(string) $packet->packet_id],
        ]);

        return [$intake, $packet, $state];
    }

    /**
     * @return array<string,mixed>
     */
    private function gateWithHarnessCapturedPhpunitEvidence(): array
    {
        return [
            'milestone_id' => ForgeIntakeCanon::MILESTONE_IMPLEMENTATION,
            'gates' => [
                ['gate_id' => 'work_packet_acceptance', 'status' => ForgeLongHorizonStateCanon::GATE_STATUS_PASSED, 'reason' => null],
            ],
            'evidence_present' => ['work_packet_receipts'],
            'evidence_missing' => [],
            'all_passed' => true,
            'failure_reasons' => [],
            'criteria_hash' => 'forge-live-outcome-criteria',
            'frozen_hash' => 'forge-live-outcome-criteria',
            'context_sufficiency' => 90,
            'judges' => [
                ['name' => 'judge-a', 'provider_family' => 'anthropic', 'approved' => true],
                ['name' => 'judge-b', 'provider_family' => 'openai', 'approved' => true],
            ],
            'changed_public_symbols' => [
                ['symbol' => 'ForgePacket', 'has_criterion' => true, 'has_test' => true],
            ],
            'mutation_report' => ['decision_surface_added' => false],
            'security_scan' => [
                'ran' => true,
                'secret_free' => true,
                'critical_sast' => 0,
                'critical_cve' => 0,
            ],
            'execution' => [
                'commands' => ['vendor/bin/phpunit tests/Feature/FooTest.php'],
                'output_tail' => "OK (5 tests, 15 assertions)\n",
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function lastOutcome(): array
    {
        $rows = app(AtlasDecideLiveOutcomeFeedbackService::class)->listOutcomes();
        $this->assertNotEmpty($rows);

        return $rows[array_key_last($rows)];
    }

    private function writeFile(string $rel, string $content): void
    {
        $path = $this->repo.'/'.$rel;
        @mkdir(dirname($path), 0775, true);
        @file_put_contents($path, $content);
    }

    /** @param list<string> $args @return array{code:int,out:string,err:string} */
    private function git(array $args): array
    {
        $p = new Process(array_merge(['git'], $args), $this->repo);
        $p->run();

        return ['code' => (int) $p->getExitCode(), 'out' => $p->getOutput(), 'err' => $p->getErrorOutput()];
    }

    private function reservationMigration(): object
    {
        return require database_path('migrations/2026_07_11_130000_create_atlas_task_scope_reservations_table.php');
    }
}
