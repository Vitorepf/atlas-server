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
 * Proves atlas:task:retire cancels ONLY repeated-give-back-quarantined blocked packets
 * (give_back_count>=7 + reason/defficiency match) and leaves other blocked packets alone.
 * --dry-run plans without mutating.
 */
final class AtlasTaskRetireCommandTest extends TestCase
{
    private const TEST_DISK = 'atlas_serving_retire_test';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.task_serving.queue_disk', self::TEST_DISK);
        Storage::fake(self::TEST_DISK);
        Storage::fake('local');
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
            'objective' => 'retire test '.$id,
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ]]);
    }

    public function test_retire_cancels_doomed_packet_and_leaves_other_blocked_packet_intact(): void
    {
        $queue = AtlasTaskServingStack::queueRepo();

        // Seed a DOOMED packet (repeated-give-back quarantine: give_back_count>=7 + matching reason).
        $this->enqueue('retire-doomed-1');
        $queue->updateStatus('retire-doomed-1', 'blocked', [
            'reason' => 'packet_not_self_sufficient',
            'give_back_count' => 8,
            'blocking_deficiencies' => ['repeated_give_back_8'],
        ]);

        // Seed a NON-doomed blocked packet (blocked for a different reason, low give-back count).
        $this->enqueue('retire-other-1');
        $queue->updateStatus('retire-other-1', 'blocked', [
            'reason' => 'scope_repair_failed',
            'give_back_count' => 2,
        ]);

        // Sanity: both are blocked before the command runs.
        $doomedBefore = $queue->get('retire-doomed-1');
        $otherBefore = $queue->get('retire-other-1');
        self::assertSame('blocked', $doomedBefore['status']);
        self::assertSame('blocked', $otherBefore['status']);

        // Run the retire command.
        $exit = Artisan::call('atlas:task:retire', ['--json' => true]);
        self::assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        self::assertIsArray($payload);
        self::assertFalse((bool) ($payload['dry_run'] ?? true));
        self::assertGreaterThanOrEqual(1, (int) ($payload['retired_count'] ?? 0));

        // The DOOMED packet is now cancelled.
        $doomedAfter = $queue->get('retire-doomed-1');
        self::assertSame('cancelled', $doomedAfter['status']);

        // The OTHER blocked packet is STILL blocked — not touched.
        $otherAfter = $queue->get('retire-other-1');
        self::assertSame('blocked', $otherAfter['status']);

        // The doomed packet has an audited receipt.
        $receipts = array_values(array_filter(
            (array) ($doomedAfter['receipts'] ?? []),
            fn ($r): bool => (string) ($r['receipt_kind'] ?? '') === 'blocked_packet_retired_repeated_give_back',
        ));
        self::assertNotEmpty($receipts, 'doomed packet must have a retired receipt');
    }

    public function test_dry_run_does_not_mutate(): void
    {
        $queue = AtlasTaskServingStack::queueRepo();

        $this->enqueue('retire-doomed-2');
        $queue->updateStatus('retire-doomed-2', 'blocked', [
            'reason' => 'packet_not_self_sufficient',
            'give_back_count' => 7,
            'blocking_deficiencies' => ['repeated_give_back_8'],
        ]);

        $exit = Artisan::call('atlas:task:retire', ['--dry-run' => true, '--json' => true]);
        self::assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        self::assertIsArray($payload);
        self::assertTrue((bool) ($payload['dry_run'] ?? false));
        self::assertGreaterThanOrEqual(1, (int) ($payload['doomed_count'] ?? 0));

        // The packet is STILL blocked — dry-run must not mutate.
        $record = $queue->get('retire-doomed-2');
        self::assertSame('blocked', $record['status']);
    }

    // --- dormant_cli_arm_proxy quarantine tests --------------------------------

    public function test_dormant_cli_arm_proxy_dry_run_reports_packet_as_doomed(): void
    {
        $queue = AtlasTaskServingStack::queueRepo();

        $this->enqueue('retire-proxy-dry-1');
        $queue->updateStatus('retire-proxy-dry-1', 'blocked', [
            'reason' => 'dormant_cli_arm_proxy',
            'blocking_deficiencies' => ['dormant_cli_arm_proxy'],
        ]);

        // Unrelated blocked packet — must remain blocked.
        $this->enqueue('retire-proxy-other-1');
        $queue->updateStatus('retire-proxy-other-1', 'blocked', [
            'reason' => 'scope_repair_failed',
            'blocking_deficiencies' => ['some_other_deficiency'],
        ]);

        $exit = Artisan::call('atlas:task:retire', ['--dry-run' => true, '--json' => true]);
        self::assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        self::assertIsArray($payload);
        self::assertTrue((bool) ($payload['dry_run'] ?? false));
        self::assertGreaterThanOrEqual(1, (int) ($payload['doomed_count'] ?? 0));

        // dry-run must not mutate.
        self::assertSame('blocked', $queue->get('retire-proxy-dry-1')['status']);
        self::assertSame('blocked', $queue->get('retire-proxy-other-1')['status']);
    }

    public function test_dormant_cli_arm_proxy_packet_is_cancelled_with_distinct_receipt(): void
    {
        $queue = AtlasTaskServingStack::queueRepo();

        $this->enqueue('retire-proxy-1');
        $queue->updateStatus('retire-proxy-1', 'blocked', [
            'reason' => 'dormant_cli_arm_proxy',
            'blocking_deficiencies' => ['dormant_cli_arm_proxy'],
        ]);

        // Unrelated blocked packet — must remain blocked.
        $this->enqueue('retire-proxy-other-2');
        $queue->updateStatus('retire-proxy-other-2', 'blocked', [
            'reason' => 'scope_repair_failed',
            'blocking_deficiencies' => ['some_other_deficiency'],
        ]);

        $exit = Artisan::call('atlas:task:retire', ['--json' => true]);
        self::assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        self::assertIsArray($payload);
        self::assertGreaterThanOrEqual(1, (int) ($payload['retired_count'] ?? 0));

        // Proxy packet is cancelled.
        $proxyAfter = $queue->get('retire-proxy-1');
        self::assertSame('cancelled', $proxyAfter['status']);

        // It carries the DISTINCT receipt kind.
        $receipts = array_values(array_filter(
            (array) ($proxyAfter['receipts'] ?? []),
            fn ($r): bool => (string) ($r['receipt_kind'] ?? '') === 'blocked_packet_retired_dormant_cli_arm_proxy',
        ));
        self::assertNotEmpty($receipts, 'dormant_cli_arm_proxy packet must have the distinct retirement receipt');

        // Unrelated blocked packet is still blocked.
        self::assertSame('blocked', $queue->get('retire-proxy-other-2')['status']);
    }
}
