<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\RunStateMachine;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class RunStateMachineTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_state_test_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_full_state_path_is_monotonic_and_resume_is_explicit(): void
    {
        $state = new RunStateMachine;
        $runId = '20260709_000000_abcdef12';
        foreach ([
            RunStateMachine::PLANNED,
            RunStateMachine::PREFLIGHTED,
            RunStateMachine::NATIVE_RUNNING,
            RunStateMachine::RESULTS_IMPORTED,
            RunStateMachine::EVIDENCE_BUILT,
            RunStateMachine::VERIFIED,
            RunStateMachine::ADJUDICATED,
            RunStateMachine::REPORTED,
            RunStateMachine::BUNDLED,
        ] as $next) {
            $current = $state->mark($runId, $next);
            $this->assertSame($next, $current['state']);
        }
        $this->assertSame('complete', $state->resumeAction($runId));
        $this->assertCount(9, $state->current($runId)['history']);
    }

    public function test_skipping_state_or_leaving_cancelled_run_is_rejected(): void
    {
        $state = new RunStateMachine;
        $runId = '20260709_000000_deadbeef';
        $state->mark($runId, RunStateMachine::PLANNED);

        try {
            $state->mark($runId, RunStateMachine::VERIFIED);
            $this->fail('state skip should fail');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('transition_forbidden', $e->getMessage());
        }

        $state->cancel($runId, 'operator stop');
        $this->assertSame('operator_intervention', $state->resumeAction($runId));
        $this->expectException(RuntimeException::class);
        $state->mark($runId, RunStateMachine::NATIVE_RUNNING);
    }

    public function test_replace_import_supersedes_history_and_increments_revision(): void
    {
        $state = new RunStateMachine;
        $runId = '20260709_000000_12345678';
        $state->mark($runId, RunStateMachine::PLANNED);
        $state->mark($runId, RunStateMachine::NATIVE_RUNNING);
        $state->mark($runId, RunStateMachine::RESULTS_IMPORTED);

        $reset = $state->resetForReplaceImport($runId, ['source' => 'replacement']);

        $this->assertSame(RunStateMachine::PLANNED, $reset['state']);
        $this->assertSame(2, $reset['revision']);
        $this->assertSame(
            [RunStateMachine::SUPERSEDED, RunStateMachine::PLANNED],
            array_column(array_slice($reset['history'], -2), 'state'),
        );
    }
}
