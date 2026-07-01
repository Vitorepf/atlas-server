<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskClaimableFarmAuditor;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves AtlasTaskClaimableFarmAuditor composes the existing similarity gates (never
 * reimplements them) to retroactively cluster template-farm duplicates already sitting in the
 * claimable pool: (a) two near-identical claimable packets cluster and exactly one is kept;
 * (b) dry mode mutates nothing; (c) apply mode blocks the retired candidate with
 * reason=template_farm_cluster; (d) a genuinely distinct packet stays outside every cluster.
 */
final class AtlasTaskClaimableFarmAuditorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function queue(): AgentControlPlaneTaskPacketQueueRepository
    {
        return new AgentControlPlaneTaskPacketQueueRepository;
    }

    private function seedClaimable(string $id, string $objective, array $allowedFiles, array $acceptanceCriteria): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build([
            'task_packet_id' => $id,
            'objective' => $objective,
            'operator_id' => 'tester',
            'allowed_files' => $allowedFiles,
            'scope_in' => $allowedFiles,
            'acceptance_criteria' => $acceptanceCriteria,
            'required_evidence' => ['tests_or_gates_result'],
        ]);

        $result = $this->queue()->enqueue($packet);
        // Sanity: fixture must actually land claimable, or the whole test proves nothing.
        self::assertSame('claimable', (string) ($result['record']['status'] ?? $result['status'] ?? ''), "seed fixture for {$id} did not land claimable: ".json_encode($result));
    }

    private function seedTemplateFarmPair(): void
    {
        $this->seedClaimable(
            'farm-a',
            'Implement AtlasFooWorker to process orphan tasks reliably and consistently for the queue.',
            ['app/Services/Farm/AtlasFooWorker.php', 'tests/Unit/Services/Farm/AtlasFooWorkerTest.php'],
            ['php artisan test --filter=AtlasFooWorkerTest exits 0', 'must handle the orphan lifecycle correctly'],
        );
        $this->seedClaimable(
            'farm-b',
            'Implement AtlasBarWorker to process orphan tasks reliably and consistently for the queue.',
            ['app/Services/Farm/AtlasBarWorker.php', 'tests/Unit/Services/Farm/AtlasBarWorkerTest.php'],
            ['php artisan test --filter=AtlasBarWorkerTest exits 0', 'must handle the orphan lifecycle correctly'],
        );
    }

    private function seedDistinctPacket(): void
    {
        $this->seedClaimable(
            'farm-c',
            'Rewrite AtlasBillingSyncJob so subscription webhooks reconcile against the ledger accurately.',
            ['app/Services/Billing/AtlasBillingSyncJob.php'],
            ['php artisan test --filter=AtlasBillingSyncJobTest exits 0'],
        );
    }

    // ── (a) clusters two near-identical packets, keeps exactly one ───────────

    public function test_clusters_two_near_identical_packets_and_keeps_exactly_one(): void
    {
        $this->seedTemplateFarmPair();

        $result = (new AtlasTaskClaimableFarmAuditor)->audit();

        $this->assertSame(AtlasTaskClaimableFarmAuditor::SCHEMA, $result['schema']);
        $this->assertCount(1, $result['clusters']);
        $cluster = $result['clusters'][0];
        $this->assertSame('farm-a', $cluster['kept']);
        $this->assertSame(['farm-b'], $cluster['retired_candidates']);
        $this->assertGreaterThan(0.0, $cluster['similarity_evidence']['max_similarity_score']);
    }

    // ── (b) dry mode leaves every packet status untouched ────────────────────

    public function test_dry_mode_leaves_every_packet_status_untouched(): void
    {
        $this->seedTemplateFarmPair();
        $this->seedDistinctPacket();

        $result = (new AtlasTaskClaimableFarmAuditor)->audit(false);

        $this->assertTrue($result['dry_run']);
        foreach (['farm-a', 'farm-b', 'farm-c'] as $id) {
            $record = $this->queue()->get($id);
            $this->assertSame('claimable', $record['status'], "{$id} must remain claimable in dry mode");
        }
        $this->assertNotEmpty($result['decisions']);
        $this->assertSame('retire_plan', $result['decisions'][0]['action']);
    }

    // ── (c) apply mode moves the retired candidate to blocked with reason ────

    public function test_apply_mode_moves_retired_candidate_to_blocked_with_reason(): void
    {
        $this->seedTemplateFarmPair();

        $result = (new AtlasTaskClaimableFarmAuditor)->audit(true);

        $this->assertFalse($result['dry_run']);
        $blockedRecord = $this->queue()->get('farm-b');
        $this->assertSame('blocked', $blockedRecord['status']);
        $this->assertSame('template_farm_cluster', (string) data_get($blockedRecord, 'metadata.reason'));

        // kept member must stay claimable — only the retired candidate is touched.
        $keptRecord = $this->queue()->get('farm-a');
        $this->assertSame('claimable', $keptRecord['status']);

        $decision = $result['decisions'][0];
        $this->assertSame('farm-b', $decision['task_packet_id']);
        $this->assertSame('blocked', $decision['action']);
        $this->assertSame('template_farm_cluster', $decision['reason']);
    }

    // ── (d) a distinct packet stays outside every cluster ────────────────────

    public function test_distinct_packet_stays_outside_every_cluster(): void
    {
        $this->seedTemplateFarmPair();
        $this->seedDistinctPacket();

        $result = (new AtlasTaskClaimableFarmAuditor)->audit(true);

        foreach ($result['clusters'] as $cluster) {
            $this->assertNotSame('farm-c', $cluster['kept']);
            $this->assertNotContains('farm-c', $cluster['retired_candidates']);
        }

        $record = $this->queue()->get('farm-c');
        $this->assertSame('claimable', $record['status'], 'distinct packet must never be touched');
    }

    // ── no clusters when queue is empty ───────────────────────────────────────

    public function test_empty_claimable_queue_yields_no_clusters(): void
    {
        $result = (new AtlasTaskClaimableFarmAuditor)->audit();

        $this->assertSame([], $result['clusters']);
        $this->assertSame([], $result['decisions']);
    }
}
