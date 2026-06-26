<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves atlas:task:repair-blocked now self-heals repeated-give-back quarantine
 * packets (blocked→cancelled) via the self-healing queue repair plan, while
 * preserving recoverable packets. --dry-run plans without mutating.
 */
final class AtlasTaskRepairBlockedSelfHealQuarantineTest extends TestCase
{
    private const TEST_DISK = 'atlas_serving_selfheal_test';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.task_serving.queue_disk', self::TEST_DISK);
        Storage::fake(self::TEST_DISK);
        Storage::fake('local');
    }

    public function test_quarantine_packet_is_cancelled_by_self_heal(): void
    {
        $queue = AtlasTaskServingStack::queueRepo();

        // Seed a repeated-give-back quarantine blocked packet.
        $this->enqueue('selfheal-quarantine-1');
        $queue->updateStatus('selfheal-quarantine-1', 'blocked', [
            'reason' => 'packet_not_self_sufficient',
            'give_back_count' => 8,
            'blocking_deficiencies' => ['repeated_give_back_8'],
        ]);

        // Sanity: it is blocked.
        self::assertSame('blocked', $queue->get('selfheal-quarantine-1')['status']);

        // Run repair-blocked.
        $exit = Artisan::call('atlas:task:repair-blocked', ['--json' => true]);
        self::assertSame(0, $exit);

        // The quarantine packet is now cancelled.
        $record = $queue->get('selfheal-quarantine-1');
        self::assertSame('cancelled', $record['status']);
    }

    public function test_recoverable_packet_is_not_cancelled_by_self_heal(): void
    {
        $queue = AtlasTaskServingStack::queueRepo();

        // Seed a recoverable blocked packet (low give-back count, different reason).
        $this->enqueue('selfheal-recoverable-1');
        $queue->updateStatus('selfheal-recoverable-1', 'blocked', [
            'reason' => 'scope_repair_failed',
            'give_back_count' => 0,
        ]);

        self::assertSame('blocked', $queue->get('selfheal-recoverable-1')['status']);

        Artisan::call('atlas:task:repair-blocked', ['--json' => true]);

        // The recoverable packet is NOT cancelled by self-heal — stays blocked (or reopened by scope repair).
        $record = $queue->get('selfheal-recoverable-1');
        self::assertNotSame('cancelled', $record['status']);
    }

    public function test_dry_run_does_not_cancel_quarantine_packet(): void
    {
        $queue = AtlasTaskServingStack::queueRepo();

        $this->enqueue('selfheal-quarantine-2');
        $queue->updateStatus('selfheal-quarantine-2', 'blocked', [
            'reason' => 'packet_not_self_sufficient',
            'give_back_count' => 8,
            'blocking_deficiencies' => ['repeated_give_back_8'],
        ]);

        $exit = Artisan::call('atlas:task:repair-blocked', ['--dry-run' => true, '--json' => true]);
        self::assertSame(0, $exit);

        // Still blocked — dry-run must not cancel.
        $record = $queue->get('selfheal-quarantine-2');
        self::assertSame('blocked', $record['status']);
    }

    public function test_both_quarantine_and_recoverable_in_same_run(): void
    {
        $queue = AtlasTaskServingStack::queueRepo();

        // Quarantine packet — should be cancelled.
        $this->enqueue('mixed-quarantine');
        $queue->updateStatus('mixed-quarantine', 'blocked', [
            'reason' => 'packet_not_self_sufficient',
            'give_back_count' => 7,
            'blocking_deficiencies' => ['repeated_give_back_7'],
        ]);

        // Recoverable packet — should NOT be cancelled.
        $this->enqueue('mixed-recoverable');
        $queue->updateStatus('mixed-recoverable', 'blocked', [
            'reason' => 'scope_repair_failed',
            'give_back_count' => 1,
        ]);

        Artisan::call('atlas:task:repair-blocked', ['--json' => true]);

        // Quarantine → cancelled.
        self::assertSame('cancelled', $queue->get('mixed-quarantine')['status']);

        // Recoverable → NOT cancelled.
        self::assertNotSame('cancelled', $queue->get('mixed-recoverable')['status']);
    }

    private function enqueue(string $id): void
    {
        $orchestrator = new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            AtlasTaskServingStack::queueRepo(),
            AtlasTaskServingStack::leaseRepo(),
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );

        $orchestrator->prepareAndEnqueue(['task_packet' => [
            'task_packet_id' => $id,
            'objective' => 'self-heal test '.$id,
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ]]);
    }
}
