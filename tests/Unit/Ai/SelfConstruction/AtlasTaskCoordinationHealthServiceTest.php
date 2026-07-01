<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AtlasTaskCoordinationHealthService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasTaskCoordinationHealthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('serving_alt');
    }

    public function test_snapshot_includes_serving_disk_health_with_ok_disk_and_reason(): void
    {
        $snapshot = (new AtlasTaskCoordinationHealthService)->snapshot();

        $this->assertArrayHasKey('serving_disk_health', $snapshot);
        foreach (['ok', 'disk', 'reason'] as $key) {
            $this->assertArrayHasKey($key, $snapshot['serving_disk_health'], "serving_disk_health missing key: {$key}");
        }
    }

    public function test_serving_disk_health_reflects_atlas_task_serving_stack(): void
    {
        Config::set('atlas.task_serving.queue_disk', 'serving_alt');

        $snapshot = (new AtlasTaskCoordinationHealthService)->snapshot();

        $this->assertTrue($snapshot['serving_disk_health']['ok']);
        $this->assertSame('serving_alt', $snapshot['serving_disk_health']['disk']);
        $this->assertSame('dedicated_disk_configured', $snapshot['serving_disk_health']['reason']);
    }

    public function test_serving_disk_health_reports_unsafe_when_disk_is_unset(): void
    {
        Config::set('atlas.task_serving.queue_disk', '');

        $snapshot = (new AtlasTaskCoordinationHealthService)->snapshot();

        $this->assertFalse($snapshot['serving_disk_health']['ok']);
        $this->assertSame('serving_disk_unset', $snapshot['serving_disk_health']['reason']);
    }

    public function test_serving_disk_health_reports_unsafe_when_disk_is_forbidden_local(): void
    {
        Config::set('atlas.task_serving.queue_disk', 'local');

        $snapshot = (new AtlasTaskCoordinationHealthService)->snapshot();

        $this->assertFalse($snapshot['serving_disk_health']['ok']);
        $this->assertSame('serving_disk_default_local_forbidden', $snapshot['serving_disk_health']['reason']);
    }

    public function test_queue_disk_mismatch_detected_behavior_remains_intact(): void
    {
        Config::set('atlas.task_serving.queue_disk', 'serving_alt');

        $snapshot = (new AtlasTaskCoordinationHealthService)->snapshot();

        $this->assertArrayHasKey('queue_disk_mismatch_detected', $snapshot['health_flags']);
        $this->assertFalse($snapshot['health_flags']['queue_disk_mismatch_detected']);
        $this->assertTrue($snapshot['healthy']);
    }

    // ── AC: lease_mismatch_leak vs recoverable_backlog + self_healing_action ────────

    public function test_unexplained_lease_mismatch_with_zero_recoverable_is_lease_mismatch_leak(): void
    {
        // Claim a lease with no corresponding queue record at all: active_leases=1,
        // claimed=0, and nothing for inspectRecoverability to classify -> recoverable_total=0.
        (new AgentControlPlaneClaimLeaseRepository)->claim('orphan-task', 'agent-1', ['allowed_files' => ['app/X.php']]);

        $snapshot = (new AtlasTaskCoordinationHealthService)->snapshot();

        $this->assertFalse($snapshot['leases_match_claimed']);
        $this->assertSame(0, $snapshot['recoverable']['total']);
        $this->assertTrue($snapshot['health_flags']['lease_leak_detected']);
        $this->assertFalse($snapshot['health_flags']['recoverable_backlog']);
        $this->assertFalse($snapshot['healthy']);
        $this->assertSame('reap_orphan_leases_and_requeue_claimed_records', $snapshot['self_healing_action']);
    }

    public function test_lease_mismatch_does_not_mark_queue_dry_or_jammed_when_claimable_supply_high(): void
    {
        (new AgentControlPlaneClaimLeaseRepository)->claim('orphan-task-2', 'agent-1', ['allowed_files' => ['app/X.php']]);

        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $builder = new AgentControlPlaneTaskPacketBuilder;
        for ($i = 0; $i < 5; $i++) {
            $queue->enqueue($builder->build($this->fixture("supply-{$i}")));
        }

        $snapshot = (new AtlasTaskCoordinationHealthService)->snapshot();

        $this->assertTrue($snapshot['health_flags']['lease_leak_detected']);
        $this->assertFalse($snapshot['health_flags']['dry_queue']);
        $this->assertFalse($snapshot['health_flags']['serving_jammed']);
        $this->assertGreaterThan(0, $snapshot['claimable_depth']);
    }

    public function test_recoverable_backlog_gets_await_next_reap_self_healing_action(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $queue->enqueue((new AgentControlPlaneTaskPacketBuilder)->build($this->fixture('orphan-claimed')));
        $queue->updateStatus('orphan-claimed', 'claimed', ['agent_id' => 'agent-1', 'lease_id' => 'lease-does-not-exist']);

        $snapshot = (new AtlasTaskCoordinationHealthService)->snapshot();

        $this->assertGreaterThan(0, $snapshot['recoverable']['total']);
        $this->assertTrue($snapshot['health_flags']['recoverable_backlog']);
        $this->assertFalse($snapshot['health_flags']['lease_leak_detected']);
        $this->assertSame('await_next_claim_next_reap_cycle', $snapshot['self_healing_action']);
    }

    /** @return array<string, mixed> */
    private function fixture(string $id): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'Fixture '.$id.': implement app/Services/Ai/SelfConstruction/'.$id.'.php deterministically and prove it.',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => ['php artisan test asserts '.$id.' behaves correctly'],
            'required_evidence' => ['task_packet_created'],
        ];
    }
}
