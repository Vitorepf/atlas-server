<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ClosedLoop;

/**
 * Routes blocked backlog patterns into learning, repair or retirement
 * recommendations that the originator can turn into tasks.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasMaestroBlockedBacklogLearningRouter
{
    public const SCHEMA = 'atlas.self_construction.maestro_blocked_backlog_learning_router.v1';

    public const ROUTE_RETIRE = 'retire';
    public const ROUTE_REPAIR = 'repair';
    public const ROUTE_GIVE_BACK = 'give_back';
    public const ROUTE_LEARNING = 'learning';

    /**
     * @param  array<int, array<string, mixed>>  $blockedTasks
     * @return array<string, mixed>
     */
    public function route(array $blockedTasks): array
    {
        $routes = [];

        foreach ($blockedTasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $id = (string) ($task['task_id'] ?? '');
            $reason = (string) ($task['block_reason'] ?? '');
            $occurrenceCount = (int) ($task['occurrence_count'] ?? 1);

            $route = $this->classifyBlock($reason, $occurrenceCount);

            $routes[] = [
                'task_id' => $id,
                'block_reason' => $reason,
                'route' => $route,
                'occurrence_count' => $occurrenceCount,
                'recommendation' => $this->recommendation($route, $reason),
            ];
        }

        $counts = [
            self::ROUTE_RETIRE => 0,
            self::ROUTE_REPAIR => 0,
            self::ROUTE_GIVE_BACK => 0,
            self::ROUTE_LEARNING => 0,
        ];
        foreach ($routes as $r) {
            $counts[$r['route']]++;
        }

        return [
            'schema' => self::SCHEMA,
            'routes' => $routes,
            'route_counts' => $counts,
            'total_blocked' => count($blockedTasks),
        ];
    }

    private function classifyBlock(string $reason, int $occurrenceCount): string
    {
        // Repeated forbidden targets retire
        if (str_contains($reason, 'forbidden') && $occurrenceCount >= 3) {
            return self::ROUTE_RETIRE;
        }

        // Missing scope repairs
        if (str_contains($reason, 'missing_scope') || str_contains($reason, 'scope_repair')) {
            return self::ROUTE_REPAIR;
        }

        // Duplicate satisfied packets give_back
        if (str_contains($reason, 'duplicate') || str_contains($reason, 'already_satisfied')) {
            return self::ROUTE_GIVE_BACK;
        }

        // Unknown blockers request learning
        return self::ROUTE_LEARNING;
    }

    private function recommendation(string $route, string $reason): string
    {
        return match ($route) {
            self::ROUTE_RETIRE => 'retire the task — forbidden target repeatedly blocked',
            self::ROUTE_REPAIR => 'repair the scope or acceptance criteria',
            self::ROUTE_GIVE_BACK => 'give_back — duplicate or already satisfied',
            self::ROUTE_LEARNING => 'request learning investigation for unknown blocker',
            default => 'investigate',
        };
    }
}
