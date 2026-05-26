<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasForge;

/**
 * Atlas Forge Parallel Durable Coordinator (AP-704 / T2.2).
 *
 * Pure evaluator. Given:
 *   - tickets[] ready to execute (each declares the file paths it needs
 *     to lock),
 *   - agents[] available (each declares whether it is `available`),
 *   - existingReservations[] currently held (agent_id -> ticket_id +
 *     locked_paths),
 *
 * returns a canonical `atlas.forge.parallel_durable.v1` assignment
 * envelope: which agent gets which ticket, which tickets remain
 * unassigned (with reason), and which existing reservations are stale.
 *
 * Persistence (writing the reservations to a durable store) is the
 * CALLER'S responsibility. This service stays pure for unit-test ergonomics
 * and so it can be invoked in dry-run mode by the operator.
 *
 * Collision rule: two tickets collide if they share ANY locked path.
 * Greedy first-fit by ticket priority, never preempt existing reservation.
 */
final class AtlasForgeParallelDurableCoordinatorService
{
    public const SCHEMA_VERSION = 'atlas.forge.parallel_durable.v1';

    public const REASON_NO_AGENT = 'no_available_agent';

    public const REASON_PATH_COLLISION = 'path_collision_with_existing_reservation';

    /**
     * @param  list<array{ticket_id:string, locked_paths:list<string>, priority?:int}>  $tickets
     * @param  list<array{agent_id:string, available?:bool}>  $agents
     * @param  list<array{agent_id:string, ticket_id:string, locked_paths:list<string>}>  $existingReservations
     * @return array<string,mixed>
     */
    public function propose(array $tickets, array $agents, array $existingReservations): array
    {
        $tickets = $this->sortByPriority($tickets);
        $existingLockedPaths = $this->collectLockedPaths($existingReservations);
        $busyAgents = $this->collectBusyAgents($existingReservations);
        $ticketIds = array_map(static fn (array $t): string => (string) $t['ticket_id'], $tickets);
        $released = $this->detectStaleReservations($existingReservations, $ticketIds);

        $assignments = [];
        $unassigned = [];
        $availableAgents = $this->collectAvailableAgents($agents, $busyAgents);

        foreach ($tickets as $ticket) {
            $ticketId = (string) $ticket['ticket_id'];
            $lockedPaths = $this->normalizePaths((array) ($ticket['locked_paths'] ?? []));

            if ($this->collidesWith($lockedPaths, $existingLockedPaths)) {
                $unassigned[] = ['ticket_id' => $ticketId, 'reason' => self::REASON_PATH_COLLISION];

                continue;
            }
            // Check collision with already-proposed assignments in this batch.
            $batchCollision = false;
            foreach ($assignments as $assigned) {
                if ($this->pathSetsOverlap($lockedPaths, $assigned['locked_paths'])) {
                    $batchCollision = true;
                    break;
                }
            }
            if ($batchCollision) {
                $unassigned[] = ['ticket_id' => $ticketId, 'reason' => self::REASON_PATH_COLLISION];

                continue;
            }

            $agentId = $this->takeNextAvailableAgent($availableAgents);
            if ($agentId === null) {
                $unassigned[] = ['ticket_id' => $ticketId, 'reason' => self::REASON_NO_AGENT];

                continue;
            }

            $assignments[] = [
                'agent_id' => $agentId,
                'ticket_id' => $ticketId,
                'locked_paths' => $lockedPaths,
            ];
        }

        $envelope = [
            'schema' => self::SCHEMA_VERSION,
            'assignments' => $assignments,
            'unassigned' => $unassigned,
            'released' => $released,
            'counts' => [
                'tickets_in' => count($tickets),
                'agents_in' => count($agents),
                'assignments' => count($assignments),
                'unassigned' => count($unassigned),
                'released' => count($released),
            ],
        ];
        $envelope['proposal_hash'] = 'sha256:'.hash('sha256', json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return $envelope;
    }

    /**
     * @param  list<array<string,mixed>>  $tickets
     * @return list<array<string,mixed>>
     */
    private function sortByPriority(array $tickets): array
    {
        usort($tickets, static function (array $a, array $b): int {
            $pa = (int) ($a['priority'] ?? 0);
            $pb = (int) ($b['priority'] ?? 0);
            if ($pa === $pb) {
                return strcmp((string) ($a['ticket_id'] ?? ''), (string) ($b['ticket_id'] ?? ''));
            }

            return $pb <=> $pa; // higher priority first
        });

        return array_values($tickets);
    }

    /**
     * @param  list<array{agent_id:string, ticket_id:string, locked_paths:list<string>}>  $existingReservations
     * @return list<string>
     */
    private function collectLockedPaths(array $existingReservations): array
    {
        $paths = [];
        foreach ($existingReservations as $r) {
            foreach ($this->normalizePaths((array) ($r['locked_paths'] ?? [])) as $p) {
                $paths[] = $p;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  list<array{agent_id:string, ticket_id:string, locked_paths:list<string>}>  $existingReservations
     * @return list<string>
     */
    private function collectBusyAgents(array $existingReservations): array
    {
        $busy = [];
        foreach ($existingReservations as $r) {
            $id = (string) ($r['agent_id'] ?? '');
            if ($id !== '') {
                $busy[] = $id;
            }
        }

        return array_values(array_unique($busy));
    }

    /**
     * @param  list<array{agent_id:string, available?:bool}>  $agents
     * @param  list<string>  $busyAgents
     * @return list<string>
     */
    private function collectAvailableAgents(array $agents, array $busyAgents): array
    {
        $available = [];
        foreach ($agents as $a) {
            $id = (string) ($a['agent_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $isAvailable = ($a['available'] ?? true) === true;
            if (! $isAvailable) {
                continue;
            }
            if (in_array($id, $busyAgents, true)) {
                continue;
            }
            $available[] = $id;
        }

        return array_values(array_unique($available));
    }

    /**
     * @param  list<string>  $available
     */
    private function takeNextAvailableAgent(array &$available): ?string
    {
        if ($available === []) {
            return null;
        }

        return array_shift($available);
    }

    /**
     * @param  list<array{agent_id:string, ticket_id:string, locked_paths:list<string>}>  $existingReservations
     * @param  list<string>  $activeTicketIds
     * @return list<array{agent_id:string, ticket_id:string}>
     */
    private function detectStaleReservations(array $existingReservations, array $activeTicketIds): array
    {
        $stale = [];
        foreach ($existingReservations as $r) {
            $ticketId = (string) ($r['ticket_id'] ?? '');
            if ($ticketId === '' || ! in_array($ticketId, $activeTicketIds, true)) {
                $stale[] = [
                    'agent_id' => (string) ($r['agent_id'] ?? ''),
                    'ticket_id' => $ticketId,
                ];
            }
        }

        return $stale;
    }

    /**
     * @param  list<string>  $proposed
     * @param  list<string>  $existing
     */
    private function collidesWith(array $proposed, array $existing): bool
    {
        return $this->pathSetsOverlap($proposed, $existing);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function pathSetsOverlap(array $a, array $b): bool
    {
        foreach ($a as $pathA) {
            foreach ($b as $pathB) {
                if ($pathA === $pathB) {
                    return true;
                }
                // Prefix overlap: `app/Foo/` collides with `app/Foo/Bar.php`.
                if (str_starts_with($pathA, rtrim($pathB, '/').'/')) {
                    return true;
                }
                if (str_starts_with($pathB, rtrim($pathA, '/').'/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function normalizePaths(array $paths): array
    {
        $clean = [];
        foreach ($paths as $p) {
            if (! is_string($p)) {
                continue;
            }
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            $clean[] = $p;
        }

        return array_values(array_unique($clean));
    }
}
