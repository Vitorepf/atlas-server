<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasForge;

use App\Services\Ai\AtlasForge\AtlasForgeParallelDurableCoordinatorService;
use PHPUnit\Framework\TestCase;

final class AtlasForgeParallelDurableCoordinatorServiceTest extends TestCase
{
    public function test_assigns_disjoint_tickets_to_all_available_agents(): void
    {
        $svc = new AtlasForgeParallelDurableCoordinatorService();

        $result = $svc->propose(
            tickets: [
                ['ticket_id' => 't1', 'locked_paths' => ['app/A.php']],
                ['ticket_id' => 't2', 'locked_paths' => ['app/B.php']],
                ['ticket_id' => 't3', 'locked_paths' => ['app/C.php']],
            ],
            agents: [
                ['agent_id' => 'a1'],
                ['agent_id' => 'a2'],
                ['agent_id' => 'a3'],
            ],
            existingReservations: [],
        );

        self::assertSame(AtlasForgeParallelDurableCoordinatorService::SCHEMA_VERSION, $result['schema']);
        self::assertCount(3, $result['assignments']);
        self::assertSame([], $result['unassigned']);
        self::assertSame(3, $result['counts']['assignments']);
    }

    public function test_detects_path_collision_in_same_batch(): void
    {
        $svc = new AtlasForgeParallelDurableCoordinatorService();

        $result = $svc->propose(
            tickets: [
                ['ticket_id' => 't1', 'locked_paths' => ['app/Foo.php'], 'priority' => 10],
                ['ticket_id' => 't2', 'locked_paths' => ['app/Foo.php'], 'priority' => 5],
            ],
            agents: [
                ['agent_id' => 'a1'],
                ['agent_id' => 'a2'],
            ],
            existingReservations: [],
        );

        self::assertCount(1, $result['assignments']);
        self::assertSame('t1', $result['assignments'][0]['ticket_id']);
        self::assertCount(1, $result['unassigned']);
        self::assertSame('t2', $result['unassigned'][0]['ticket_id']);
        self::assertSame(
            AtlasForgeParallelDurableCoordinatorService::REASON_PATH_COLLISION,
            $result['unassigned'][0]['reason'],
        );
    }

    public function test_respects_existing_reservation_collision(): void
    {
        $svc = new AtlasForgeParallelDurableCoordinatorService();

        $result = $svc->propose(
            tickets: [
                ['ticket_id' => 't_new', 'locked_paths' => ['app/Foo.php']],
            ],
            agents: [
                ['agent_id' => 'a_free'],
            ],
            existingReservations: [
                ['agent_id' => 'a_busy', 'ticket_id' => 't_existing', 'locked_paths' => ['app/Foo.php']],
            ],
        );

        self::assertSame([], $result['assignments']);
        self::assertCount(1, $result['unassigned']);
        self::assertSame(
            AtlasForgeParallelDurableCoordinatorService::REASON_PATH_COLLISION,
            $result['unassigned'][0]['reason'],
        );
    }

    public function test_skips_when_no_agent_available(): void
    {
        $svc = new AtlasForgeParallelDurableCoordinatorService();

        $result = $svc->propose(
            tickets: [
                ['ticket_id' => 't1', 'locked_paths' => ['app/A.php']],
                ['ticket_id' => 't2', 'locked_paths' => ['app/B.php']],
            ],
            agents: [['agent_id' => 'a1']],
            existingReservations: [],
        );

        self::assertCount(1, $result['assignments']);
        self::assertCount(1, $result['unassigned']);
        self::assertSame(
            AtlasForgeParallelDurableCoordinatorService::REASON_NO_AGENT,
            $result['unassigned'][0]['reason'],
        );
    }

    public function test_detects_stale_reservations(): void
    {
        $svc = new AtlasForgeParallelDurableCoordinatorService();

        $result = $svc->propose(
            tickets: [['ticket_id' => 't_active', 'locked_paths' => ['app/Active.php']]],
            agents: [['agent_id' => 'a1']],
            existingReservations: [
                ['agent_id' => 'a_stale', 'ticket_id' => 't_gone', 'locked_paths' => ['app/Gone.php']],
            ],
        );

        self::assertCount(1, $result['released']);
        self::assertSame('t_gone', $result['released'][0]['ticket_id']);
    }

    public function test_proposal_hash_is_deterministic(): void
    {
        $svc = new AtlasForgeParallelDurableCoordinatorService();
        $tickets = [['ticket_id' => 't1', 'locked_paths' => ['app/A.php']]];
        $agents = [['agent_id' => 'a1']];

        $a = $svc->propose($tickets, $agents, [])['proposal_hash'];
        $b = $svc->propose($tickets, $agents, [])['proposal_hash'];

        self::assertSame($a, $b);
    }

    public function test_prefix_collision_is_detected(): void
    {
        $svc = new AtlasForgeParallelDurableCoordinatorService();

        $result = $svc->propose(
            tickets: [
                ['ticket_id' => 't1', 'locked_paths' => ['app/Domain'], 'priority' => 10],
                ['ticket_id' => 't2', 'locked_paths' => ['app/Domain/Sub/X.php'], 'priority' => 5],
            ],
            agents: [['agent_id' => 'a1'], ['agent_id' => 'a2']],
            existingReservations: [],
        );

        self::assertCount(1, $result['assignments']);
        self::assertSame(
            AtlasForgeParallelDurableCoordinatorService::REASON_PATH_COLLISION,
            $result['unassigned'][0]['reason'],
        );
    }

    public function test_unavailable_agents_are_skipped(): void
    {
        $svc = new AtlasForgeParallelDurableCoordinatorService();

        $result = $svc->propose(
            tickets: [['ticket_id' => 't1', 'locked_paths' => ['app/A.php']]],
            agents: [
                ['agent_id' => 'a1', 'available' => false],
                ['agent_id' => 'a2', 'available' => true],
            ],
            existingReservations: [],
        );

        self::assertCount(1, $result['assignments']);
        self::assertSame('a2', $result['assignments'][0]['agent_id']);
    }
}
