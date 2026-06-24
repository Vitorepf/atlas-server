<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAmbitionLeapProposer;
use App\Services\Ai\AutonomousEvolution\AtlasLoopBacklogAutoFeederService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopFrontierGapModel;
use App\Services\Ai\AutonomousEvolution\AtlasLoopLeapDecompositionSeeder;
use App\Services\Ai\AutonomousEvolution\AtlasLoopLeapReceiptLedger;
use App\Services\Ai\AutonomousEvolution\AtlasLoopLeapRiskAuditor;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopAmbitionFacultyCommandTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envPath = sys_get_temp_dir().'/atlas-loop-ambition-faculty-'.bin2hex(random_bytes(6)).'.env';
        AtlasLoopMasterSwitch::$envPathOverride = $this->envPath;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->envPath);
        parent::tearDown();
    }

    public function test_master_off_returns_typed_disabled_payload_and_zero_writes(): void
    {
        file_put_contents($this->envPath, "ATLAS_LOOP_MASTER_ENABLED=false\n");
        $ledger = new AmbitionFacultyLedgerSpy;
        $feeder = new AmbitionFacultyBacklogFeederSpy;
        app()->instance(AtlasLoopLeapReceiptLedger::class, $ledger);
        app()->instance(AtlasLoopBacklogAutoFeederService::class, $feeder);

        $exit = Artisan::call('atlas:loop:ambition-faculty', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('master_disabled', $payload['status']);
        $this->assertSame(0, $payload['writes']['ledger_records']);
        $this->assertSame(0, $payload['writes']['backlog_forwards']);
        $this->assertSame(0, $ledger->recordCalls);
        $this->assertSame(0, $feeder->acceptCalls);
    }

    public function test_dry_run_outputs_structured_plan_without_ledger_or_backlog_writes(): void
    {
        file_put_contents($this->envPath, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        $ledger = new AmbitionFacultyLedgerSpy;
        $feeder = new AmbitionFacultyBacklogFeederSpy;
        app()->instance(AtlasLoopLeapReceiptLedger::class, $ledger);
        app()->instance(AtlasLoopBacklogAutoFeederService::class, $feeder);
        $this->bindPipelineDoubles();

        $exit = Artisan::call('atlas:loop:ambition-faculty', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertTrue($payload['dry_run']);
        $this->assertArrayHasKey('gaps', $payload);
        $this->assertArrayHasKey('leaps', $payload);
        $this->assertArrayHasKey('verdicts', $payload);
        $this->assertArrayHasKey('seeded_packets', $payload);
        $this->assertCount(1, $payload['seeded_packets']);
        $this->assertSame(0, $ledger->recordCalls);
        $this->assertSame(0, $feeder->acceptCalls);
    }

    public function test_write_records_one_receipt_per_surviving_leap_and_forwards_packets_once(): void
    {
        file_put_contents($this->envPath, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        $ledger = new AmbitionFacultyLedgerSpy;
        $feeder = new AmbitionFacultyBacklogFeederSpy;
        app()->instance(AtlasLoopLeapReceiptLedger::class, $ledger);
        app()->instance(AtlasLoopBacklogAutoFeederService::class, $feeder);
        $this->bindPipelineDoubles();

        $exit = Artisan::call('atlas:loop:ambition-faculty', [
            '--write' => true,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertFalse($payload['dry_run']);
        $this->assertSame(1, $payload['writes']['ledger_records']);
        $this->assertSame(1, $payload['writes']['backlog_forwards']);
        $this->assertSame(1, $ledger->recordCalls);
        $this->assertSame(1, $feeder->acceptCalls);
        $this->assertSame(['packet-1'], array_column($feeder->acceptedPackets[0], 'task_packet_id'));
    }

    public function test_forbidden_path_leaps_are_filtered_before_seed_and_record(): void
    {
        file_put_contents($this->envPath, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        $ledger = new AmbitionFacultyLedgerSpy;
        $seeder = new AmbitionFacultySeederSpy;
        app()->instance(AtlasLoopLeapReceiptLedger::class, $ledger);
        app()->instance(AtlasLoopBacklogAutoFeederService::class, new AmbitionFacultyBacklogFeederSpy);
        app()->instance(AtlasLoopFrontierGapModel::class, new AmbitionFacultyGapModelFake);
        app()->instance(AtlasLoopAmbitionLeapProposer::class, new AmbitionFacultyLeapProposerFake);
        app()->instance(AtlasLoopLeapRiskAuditor::class, new AmbitionFacultyRejectingRiskAuditorFake);
        app()->instance(AtlasLoopLeapDecompositionSeeder::class, $seeder);

        $exit = Artisan::call('atlas:loop:ambition-faculty', [
            '--write' => true,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('reject', $payload['verdicts'][0]['status']);
        $this->assertSame(['forbidden_scope'], $payload['verdicts'][0]['reasons']);
        $this->assertSame([], $payload['seeded_packets']);
        $this->assertSame(0, $seeder->seedCalls);
        $this->assertSame(0, $ledger->recordCalls);
    }

    private function bindPipelineDoubles(): void
    {
        app()->instance(AtlasLoopFrontierGapModel::class, new AmbitionFacultyGapModelFake);
        app()->instance(AtlasLoopAmbitionLeapProposer::class, new AmbitionFacultyLeapProposerFake);
        app()->instance(AtlasLoopLeapRiskAuditor::class, new AmbitionFacultyPassingRiskAuditorFake);
        app()->instance(AtlasLoopLeapDecompositionSeeder::class, new AmbitionFacultySeederSpy);
    }
}

final class AmbitionFacultyGapModelFake
{
    /** @return list<array<string,mixed>> */
    public function compute(array $runtimeFacts = [], int $window = 3): array
    {
        return [[
            'record_type' => 'FrontierGap',
            'gap_id' => 'gap-1',
            'scope' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopFrontierGapModel.php',
            'evidence_refs' => ['ev-1', 'ev-2', 'ev-3'],
            'plateau_signal' => true,
            'last_movement_at' => null,
        ]];
    }
}

final class AmbitionFacultyLeapProposerFake
{
    /** @return list<array<string,mixed>> */
    public function propose(array $frontierGaps): array
    {
        return [[
            'record_type' => 'AmbitionLeap',
            'leap_id' => 'leap-1',
            'gap_id' => 'gap-1',
            'hypothesis' => 'Grounded leap.',
            'target_capability_delta' => 'Real loop capability delta.',
            'grounded_evidence_refs' => ['ev-1', 'ev-2', 'ev-3'],
            'target_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopFrontierGapModel.php',
            'anti_farm_evidence' => ['runtime_signal' => true],
        ]];
    }
}

final class AmbitionFacultyPassingRiskAuditorFake
{
    /** @return list<array<string,mixed>> */
    public function audit(array $ambitionLeaps, array $liveEvidenceRefs = []): array
    {
        return [[
            'record_type' => 'RiskVerdict',
            'leap_id' => 'leap-1',
            'status' => 'pass',
            'reasons' => [],
        ]];
    }
}

final class AmbitionFacultyRejectingRiskAuditorFake
{
    /** @return list<array<string,mixed>> */
    public function audit(array $ambitionLeaps, array $liveEvidenceRefs = []): array
    {
        return [[
            'record_type' => 'RiskVerdict',
            'leap_id' => 'leap-1',
            'status' => 'reject',
            'reasons' => ['forbidden_scope'],
        ]];
    }
}

final class AmbitionFacultySeederSpy
{
    public int $seedCalls = 0;

    /** @return array{task_packets:list<array<string,mixed>>,refuse_reason:null,shape_prior:array<string,mixed>} */
    public function seed(array $ambitionLeap, array $riskVerdict): array
    {
        $this->seedCalls++;

        return [
            'task_packets' => [[
                'task_packet_id' => 'packet-1',
                'objective' => 'Implement real slice.',
                'allowed_files' => ['app/Services/Ai/AutonomousEvolution/AtlasLoopFrontierGapModel.php'],
                'acceptance_criteria' => ['covered'],
                'required_evidence' => ['unit_test'],
            ]],
            'refuse_reason' => null,
            'shape_prior' => ['verdict' => 'ok'],
        ];
    }
}

final class AmbitionFacultyLedgerSpy
{
    public int $recordCalls = 0;

    /** @var list<array<string,mixed>> */
    public array $receipts = [];

    /** @return array{recorded:bool,outcome:string,reason:null,receipt:array<string,mixed>} */
    public function record(array $receipt, mixed $attemptLedger = null): array
    {
        $this->recordCalls++;
        $this->receipts[] = $receipt;

        return [
            'recorded' => true,
            'outcome' => 'recorded',
            'reason' => null,
            'receipt' => $receipt,
        ];
    }
}

final class AmbitionFacultyBacklogFeederSpy
{
    public int $acceptCalls = 0;

    /** @var list<list<array<string,mixed>>> */
    public array $acceptedPackets = [];

    /** @param list<array<string,mixed>> $packets */
    public function acceptSeededPackets(array $packets): void
    {
        $this->acceptCalls++;
        $this->acceptedPackets[] = $packets;
    }
}
