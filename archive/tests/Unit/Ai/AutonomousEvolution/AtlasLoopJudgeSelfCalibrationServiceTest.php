<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopJudgeSelfCalibrationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasLoopJudgeSelfCalibrationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_loop_tasks');
        Schema::create('atlas_loop_tasks', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('campaign_id')->nullable();
            $table->string('source')->nullable();
            $table->string('target_path')->nullable();
            $table->text('objective')->nullable();
            $table->longText('payload')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_loop_tasks');

        parent::tearDown();
    }

    public function test_returns_no_fix_forward_cases_when_the_historical_case_set_is_empty(): void
    {
        $payload = app(AtlasLoopJudgeSelfCalibrationService::class)->calibrate([
            'write' => false,
        ]);

        $this->assertSame('no_fix_forward_cases', $payload['status']);
        $this->assertSame(['no_fix_forward_canary_red_tasks_in_window'], $payload['blockers']);
        $this->assertSame(0, data_get($payload, 'counts.historical_fix_forward_cases'));
        $this->assertSame([], $payload['candidates']);
    }

    public function test_non_empty_historical_cases_do_not_take_the_empty_case_branch(): void
    {
        DB::table('atlas_loop_tasks')->insert([
            'id' => 'task-1',
            'campaign_id' => 'campaign-1',
            'source' => 'fix_forward',
            'target_path' => 'app/Services/Ai/AutonomousEvolution/MissingTarget.php',
            'objective' => 'Pin the current verifier behavior.',
            'payload' => json_encode([
                'origin' => 'fix_forward_canary_red',
                'canary_target' => 'tests/Unit/Ai/AutonomousEvolution/MissingCanaryTest.php',
                'snapshot_tag' => 'snapshot-1',
                'merged_proposal_hash' => 'proposal-1',
            ], JSON_THROW_ON_ERROR),
            'created_at' => Carbon::now()->subMinute(),
            'updated_at' => Carbon::now(),
        ]);

        $payload = app(AtlasLoopJudgeSelfCalibrationService::class)->calibrate([
            'write' => false,
        ]);

        $this->assertSame('calibration_unproven', $payload['status']);
        $this->assertSame(1, data_get($payload, 'counts.historical_fix_forward_cases'));
        $this->assertSame(0, data_get($payload, 'counts.ready_verifier_candidates'));
        $this->assertSame(1, data_get($payload, 'counts.blocked_candidates'));
        $this->assertSame('blocked', data_get($payload, 'candidates.0.status'));
        $this->assertFalse((bool) data_get($payload, 'candidates.0.would_have_caught_case'));
        $this->assertSame(
            ['target_file_missing', 'canary_test_file_missing'],
            data_get($payload, 'candidates.0.blockers'),
        );
        $this->assertSame(
            ['target_file_missing', 'canary_test_file_missing'],
            $payload['blockers'],
        );
    }

    public function test_empty_target_paths_are_blocked_before_packet_compilation(): void
    {
        $this->insertFixForwardTask(
            targetPath: '',
            canaryTarget: 'tests/Unit/Ai/AutonomousEvolution/AtlasLoopJudgeSelfCalibrationServiceTest.php',
            snapshotTag: 'snapshot-empty-target',
        );

        $payload = app(AtlasLoopJudgeSelfCalibrationService::class)->calibrate([
            'write' => false,
        ]);

        $this->assertSame('blocked', data_get($payload, 'candidates.0.status'));
        $this->assertContains('target_file_missing', data_get($payload, 'candidates.0.blockers', []));
    }

    public function test_empty_canary_paths_are_blocked_before_packet_compilation(): void
    {
        $this->insertFixForwardTask(
            targetPath: 'app/Providers/AppServiceProvider.php',
            canaryTarget: '',
            snapshotTag: 'snapshot-empty-canary',
        );

        $payload = app(AtlasLoopJudgeSelfCalibrationService::class)->calibrate([
            'write' => false,
        ]);

        $this->assertSame('blocked', data_get($payload, 'candidates.0.status'));
        $this->assertContains('canary_test_file_missing', data_get($payload, 'candidates.0.blockers', []));
        $this->assertSame('', data_get($payload, 'candidates.0.canary_target'));
    }

    public function test_non_test_canary_paths_are_blocked_before_packet_compilation(): void
    {
        $this->insertFixForwardTask(
            targetPath: 'app/Providers/AppServiceProvider.php',
            canaryTarget: 'app/Providers/AppServiceProvider.php',
            snapshotTag: 'snapshot-non-test-canary',
        );

        $payload = app(AtlasLoopJudgeSelfCalibrationService::class)->calibrate([
            'write' => false,
        ]);

        $this->assertSame('blocked', data_get($payload, 'candidates.0.status'));
        $this->assertContains('canary_target_not_a_test_path', data_get($payload, 'candidates.0.blockers', []));
        $this->assertNotContains('canary_test_file_missing', data_get($payload, 'candidates.0.blockers', []));
        $this->assertSame('app/Providers/AppServiceProvider.php', data_get($payload, 'candidates.0.canary_target'));
    }

    public function test_forbidden_self_targets_are_blocked_even_when_the_file_exists(): void
    {
        $this->insertFixForwardTask(
            targetPath: 'app/Services/Ai/AutonomousEvolution/AtlasLoopHarnessGuard.php',
            canaryTarget: 'tests/Unit/Ai/AutonomousEvolution/AtlasLoopJudgeSelfCalibrationServiceTest.php',
            snapshotTag: 'snapshot-forbidden-target',
        );

        $payload = app(AtlasLoopJudgeSelfCalibrationService::class)->calibrate([
            'write' => false,
        ]);

        $this->assertSame('blocked', data_get($payload, 'candidates.0.status'));
        $this->assertContains('forbidden_self_target', data_get($payload, 'candidates.0.blockers', []));
        $this->assertNotContains('target_file_missing', data_get($payload, 'candidates.0.blockers', []));
        $this->assertSame('app/Services/Ai/AutonomousEvolution/AtlasLoopHarnessGuard.php', data_get($payload, 'candidates.0.target_path'));
    }

    public function test_existing_target_and_canary_paths_do_not_take_the_empty_path_branches(): void
    {
        $this->insertFixForwardTask(
            targetPath: 'app/Providers/AppServiceProvider.php',
            canaryTarget: 'tests/Unit/Ai/AutonomousEvolution/AtlasLoopJudgeSelfCalibrationServiceTest.php',
            snapshotTag: 'snapshot-real-paths',
        );

        $payload = app(AtlasLoopJudgeSelfCalibrationService::class)->calibrate([
            'write' => false,
        ]);

        $this->assertSame('verifier_candidates_ready', $payload['status']);
        $this->assertSame('app/Providers/AppServiceProvider.php', data_get($payload, 'candidates.0.target_path'));
        $this->assertSame('tests/Unit/Ai/AutonomousEvolution/AtlasLoopJudgeSelfCalibrationServiceTest.php', data_get($payload, 'candidates.0.canary_target'));
        $this->assertSame('frozen_verifier_ready', data_get($payload, 'candidates.0.status'));
        $this->assertTrue((bool) data_get($payload, 'candidates.0.would_have_caught_case'));
        $this->assertNotContains('target_file_missing', data_get($payload, 'candidates.0.blockers', []));
        $this->assertNotContains('canary_test_file_missing', data_get($payload, 'candidates.0.blockers', []));
        $this->assertNotContains('canary_target_not_a_test_path', data_get($payload, 'candidates.0.blockers', []));
    }

    private function insertFixForwardTask(string $targetPath, string $canaryTarget, string $snapshotTag): void
    {
        DB::table('atlas_loop_tasks')->insert([
            'id' => 'task-'.substr(hash('sha256', $targetPath.'|'.$canaryTarget.'|'.$snapshotTag), 0, 12),
            'campaign_id' => 'campaign-characterization',
            'source' => 'fix_forward',
            'target_path' => $targetPath,
            'objective' => 'Pin the current verifier behavior.',
            'payload' => json_encode([
                'origin' => 'fix_forward_canary_red',
                'canary_target' => $canaryTarget,
                'snapshot_tag' => $snapshotTag,
                'merged_proposal_hash' => 'proposal-'.substr(hash('sha256', $snapshotTag), 0, 12),
            ], JSON_THROW_ON_ERROR),
            'created_at' => Carbon::now()->subMinute(),
            'updated_at' => Carbon::now(),
        ]);
    }
}
