<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\SoftwareCompanyLoopRunJob;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCandidateQuarantineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class SoftwareCompanyLoopRunJobTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_loop_job_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_source_job_enqueues_same_mission_and_target_claims_only_after_its_lock_is_acquired(): void
    {
        Bus::fake();
        $runner = app(Reliable24hLoopRunnerService::class);
        $runner->setStorageRootForTesting($this->tmp);
        $runner->setSleeperForTesting(static fn (int $seconds): null => null);
        app(AreaFocusCandidateQuarantineService::class)->setStorageRootForTesting($this->tmp.'/quarantine');

        $handoffId = '';
        $requested = false;
        $runner->setSessionRunnerForTesting(function () use ($runner, &$handoffId, &$requested): array {
            if (! $requested) {
                $requested = true;
                $lock = $runner->lockStatus('agentic_engineering_os', 'dev_forge');
                $record = $runner->handoff()->request(
                    'agentic_engineering_os',
                    'dev_forge',
                    (array) ($lock['holder'] ?? []),
                    'operator-test',
                    'trocar para o proximo worker da fila',
                );
                $handoffId = (string) $record['handoff_id'];
            }

            return [
                'schema_version' => AutonomousEvolutionSessionService::REPORT_SCHEMA,
                'status' => 'completed',
                'cycles' => [[
                    'cycle_id' => 'cycle-1',
                    'final_status' => 'cycle_completed_waiting_review_or_merge',
                    'selected_finding' => ['finding_id' => 'finding-1'],
                    'merge_performed' => false,
                    'blockers' => [],
                ]],
            ];
        });

        $input = [
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'execute' => false,
            'dry_run' => true,
            'max_cycles' => 3,
        ];
        (new SoftwareCompanyLoopRunJob($input, 'agentic_engineering_os', 'dev_forge'))->handle($runner);

        $this->assertNotSame('', $handoffId);
        $this->assertSame('successor_enqueued', $runner->handoff()->publicRecord($handoffId)['status']);

        $successorInput = null;
        Bus::assertDispatched(SoftwareCompanyLoopRunJob::class, function (SoftwareCompanyLoopRunJob $job) use (&$successorInput, $handoffId): bool {
            $successorInput = $job->input;

            return $job->areaId === 'agentic_engineering_os'
                && $job->focus === 'dev_forge'
                && ($job->input['handoff_id'] ?? null) === $handoffId;
        });
        $this->assertIsArray($successorInput);

        (new SoftwareCompanyLoopRunJob($successorInput, 'agentic_engineering_os', 'dev_forge'))->handle($runner);

        $record = $runner->handoff()->publicRecord($handoffId);
        $this->assertSame('target_claimed', $record['status']);
        $this->assertSame('claimed', $record['target']['status']);
        $this->assertNotSame('', $record['target']['run_id']);
    }
}
