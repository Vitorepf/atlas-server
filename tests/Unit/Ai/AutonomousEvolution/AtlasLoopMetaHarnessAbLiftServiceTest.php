<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMetaHarnessAbLiftService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasLoopMetaHarnessAbLiftServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_loop_proposals');
        Schema::dropIfExists('atlas_loop_tasks');

        Schema::create('atlas_loop_tasks', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('status')->nullable();
            $table->string('source')->nullable();
            $table->string('target_path')->nullable();
            $table->longText('result')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('atlas_loop_proposals', function (Blueprint $table): void {
            $table->id();
            $table->string('task_id')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_loop_proposals');
        Schema::dropIfExists('atlas_loop_tasks');

        parent::tearDown();
    }

    public function test_positive_lift_is_allowed_when_the_delta_matches_the_floor_exactly(): void
    {
        $this->insertLoopTask(
            taskId: 'meta-certified',
            targetPath: 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php',
            proposalCount: 1,
        );
        $this->insertLoopTask(
            taskId: 'meta-failed',
            targetPath: 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php',
            proposalCount: 0,
        );
        $this->insertLoopTask(
            taskId: 'ordinary-failed-1',
            targetPath: 'app/Support/PlainHelper.php',
            proposalCount: 0,
        );
        $this->insertLoopTask(
            taskId: 'ordinary-failed-2',
            targetPath: 'app/Support/PlainHelper.php',
            proposalCount: 0,
        );

        $payload = $this->service()->measure([
            'enabled' => true,
            'min_cases_per_arm' => 2,
            'min_lift' => 0.5,
        ]);

        $this->assertSame('positive_lift', $payload['status']);
        $this->assertSame([], $payload['blockers']);
        $this->assertSame(0.5, data_get($payload, 'arms.meta_harness.certification_rate'));
        $this->assertSame(0.0, data_get($payload, 'arms.ordinary.certification_rate'));
        $this->assertSame(0.5, data_get($payload, 'lift.certification_rate_delta'));
        $this->assertTrue((bool) data_get($payload, 'lift.positive'));
        $this->assertTrue((bool) $payload['completion_claim_allowed']);
    }

    public function test_blockers_keep_the_measurement_in_insufficient_evidence_mode(): void
    {
        $this->insertLoopTask(
            taskId: 'meta-certified',
            targetPath: 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php',
            proposalCount: 1,
        );
        $this->insertLoopTask(
            taskId: 'ordinary-certified',
            targetPath: 'app/Support/PlainHelper.php',
            proposalCount: 1,
        );
        $this->insertLoopTask(
            taskId: 'forbidden-target',
            targetPath: 'app/Services/Ai/AutonomousEvolution/AtlasLoopHarnessGuard.php',
            proposalCount: 0,
        );

        $payload = $this->service()->measure([
            'enabled' => true,
            'min_cases_per_arm' => 1,
            'min_lift' => 0.01,
        ]);

        $this->assertSame('insufficient_live_ab_evidence', $payload['status']);
        $this->assertSame(['forbidden_self_targets_seen_in_window'], $payload['blockers']);
        $this->assertSame(1, data_get($payload, 'forbidden_self_targets.count'));
        $this->assertSame(
            ['app/Services/Ai/AutonomousEvolution/AtlasLoopHarnessGuard.php'],
            data_get($payload, 'forbidden_self_targets.targets'),
        );
        $this->assertNull(data_get($payload, 'lift.certification_rate_delta'));
        $this->assertFalse((bool) data_get($payload, 'lift.positive'));
        $this->assertFalse((bool) $payload['completion_claim_allowed']);
    }

    private function service(): AtlasLoopMetaHarnessAbLiftService
    {
        return new AtlasLoopMetaHarnessAbLiftService(new AtlasLoopHarnessGuard());
    }

    private function insertLoopTask(string $taskId, string $targetPath, int $proposalCount): void
    {
        DB::table('atlas_loop_tasks')->insert([
            'id' => $taskId,
            'status' => 'done',
            'source' => 'discovery',
            'target_path' => $targetPath,
            'result' => json_encode(['proposals_certified_for_review' => $proposalCount]),
            'updated_at' => Carbon::now(),
        ]);

        for ($proposalIndex = 0; $proposalIndex < $proposalCount; $proposalIndex++) {
            DB::table('atlas_loop_proposals')->insert([
                'task_id' => $taskId,
            ]);
        }
    }
}
