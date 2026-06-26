<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves the scheduled `atlas:acp:reap-leases` reaper hits the OPERATOR SERVING disk and not the
 * container-default 'local'. With the disk gotcha (the version that auto-resolved
 * AgentControlPlaneTaskLeaseRecoveryService via the container), seeding a TTL-expired lease on the
 * serving disk would yield recovered_count=0 because the reaper would read the default disk.
 */
final class AtlasAgentControlPlaneReapLeasesServingDiskTest extends TestCase
{
    private const TEST_DISK = 'atlas_serving_reap_test';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.task_serving.queue_disk', self::TEST_DISK);
        Storage::fake(self::TEST_DISK);
        Storage::fake('local');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15T10:00:00Z'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_reaper_recovers_a_ttl_expired_lease_on_the_serving_disk(): void
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
            'task_packet_id' => 'serving-expired-1',
            'objective' => 'serving disk reap recovery test',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/serving-expired-1.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/serving-expired-1.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ]]);

        $claim = $orchestrator->claimNext('agent-serving', ['ttl_seconds' => 60]);
        $this->assertSame('claimed', $claim['event']);

        // Move past the TTL so the lease is expired by the time the reaper fires.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15T10:05:00Z'));

        $exit = Artisan::call('atlas:acp:reap-leases', ['--json' => true]);
        $this->assertSame(0, $exit);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertGreaterThanOrEqual(1, (int) $payload['expired_recovered']);

        // Final state on the SERVING disk: the seeded packet is back to claimable.
        $record = AtlasTaskServingStack::queueRepo()->get('serving-expired-1');
        $this->assertIsArray($record);
        $this->assertSame('claimable', $record['status']);

        // Sanity: the default-disk repo (the buggy reaper target) has no record of this packet.
        $defaultRecord = (new \App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository)->get('serving-expired-1');
        $this->assertNull($defaultRecord, 'seeded packet must live on the serving disk only');
    }
}
