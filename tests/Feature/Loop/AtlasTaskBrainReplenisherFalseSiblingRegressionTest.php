<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskBrainReplenisher;
use Tests\TestCase;

/**
 * End-to-end regression pinning the role-token semantic disambiguator wiring: an orphan whose only role-token
 * match is a SEMANTICALLY DIFFERENT class (TransferGate vs ResourceGate) is NEVER wired to that false twin's
 * caller sites. The orphan either defers, or (when a real signal-sharing sibling exists) wires to THAT one.
 */
final class AtlasTaskBrainReplenisherFalseSiblingRegressionTest extends TestCase
{
    private function replenisher(): AtlasTaskBrainReplenisher
    {
        return new AtlasTaskBrainReplenisher(app(AgentControlPlaneTaskQueueOrchestrator::class));
    }

    /** @param list<array<string,mixed>> $inventory */
    private function model(array $inventory, array $edges, array $orphans): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: $inventory,
            edges: $edges,
            orphans: $orphans,
            cloneClusters: [],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: [],
            snapshotId: 'false-sibling-snap',
        );
    }

    /** @return array<string,mixed> */
    private function item(string $rel, string $fqcn, bool $orphan): array
    {
        return ['rel_path' => $rel, 'fqcn' => $fqcn, 'public_methods' => ['run'], 'is_orphan' => $orphan, 'is_forbidden' => false, 'clone_cluster_id' => null];
    }

    public function test_false_twin_orphan_is_never_wired_to_the_other_subsystems_callers(): void
    {
        // TransferGate (App\Pay) orphan; its only role-'gate' match is ResourceGate (App\Quota), wired by a
        // caller in a DIFFERENT subsystem. No App\Pay neighbour ⇒ disambiguator rejects, orphan DEFERS.
        $model = $this->model(
            inventory: [
                $this->item('app/Pay/TransferGate.php', 'App\\Pay\\TransferGate', true),
                $this->item('app/Quota/ResourceGate.php', 'App\\Quota\\ResourceGate', false),
                $this->item('app/Quota/QuotaCaller.php', 'App\\Quota\\QuotaCaller', false),
            ],
            edges: ['app/Quota/ResourceGate.php' => ['app/Quota/QuotaCaller.php']],
            orphans: ['App\\Pay\\TransferGate'],
        );

        $packets = $this->replenisher()->structureTasks($model, includeOrphans: true);

        foreach ($packets as $packet) {
            $this->assertNotContains('app/Quota/QuotaCaller.php', (array) ($packet['allowed_files'] ?? []), 'no packet may point at the false twin’s caller site');
        }
        $transferId = 'brain-orphan-'.substr(md5('App\\Pay\\TransferGate'), 0, 12);
        $this->assertNotContains($transferId, array_column($packets, 'task_packet_id'), 'the false-twin orphan defers');
    }

    public function test_emitted_orphan_packet_points_only_at_a_signal_sharing_sibling(): void
    {
        // TransferGate (App\Pay) with a REAL sibling TransferAuditGate (App\Pay, wired by app/Pay/PayCaller) AND
        // a false twin ResourceGate (App\Quota). The orphan wires to the real one's site, never the false twin's.
        $model = $this->model(
            inventory: [
                $this->item('app/Pay/TransferGate.php', 'App\\Pay\\TransferGate', true),
                $this->item('app/Pay/TransferAuditGate.php', 'App\\Pay\\TransferAuditGate', false),
                $this->item('app/Pay/PayCaller.php', 'App\\Pay\\PayCaller', false),
                $this->item('app/Quota/ResourceGate.php', 'App\\Quota\\ResourceGate', false),
                $this->item('app/Quota/QuotaCaller.php', 'App\\Quota\\QuotaCaller', false),
            ],
            edges: [
                'app/Pay/TransferAuditGate.php' => ['app/Pay/PayCaller.php'],
                'app/Quota/ResourceGate.php' => ['app/Quota/QuotaCaller.php'],
            ],
            orphans: ['App\\Pay\\TransferGate'],
        );

        $packets = $this->replenisher()->structureTasks($model, includeOrphans: true);
        $transferId = 'brain-orphan-'.substr(md5('App\\Pay\\TransferGate'), 0, 12);

        $byId = [];
        foreach ($packets as $p) {
            $byId[(string) $p['task_packet_id']] = (array) ($p['allowed_files'] ?? []);
        }

        $this->assertArrayHasKey($transferId, $byId, 'a real signal-sharing sibling exists ⇒ the orphan is wired');
        $this->assertContains('app/Pay/PayCaller.php', $byId[$transferId], 'wired to the true sibling’s caller');
        $this->assertNotContains('app/Quota/QuotaCaller.php', $byId[$transferId], 'never the false twin’s caller');
    }
}
