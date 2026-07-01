<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Adapters;

use App\Services\Ai\EngineeringKernel\WorkcellExecutor;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;

/**
 * Engineering Kernel adapter proving the WorkcellExecutor interface is real: makes the
 * existing task-serving lane {@see AtlasTaskServingService} the first WorkcellExecutor
 * implementation, so future Atlas-native workers and Forge lanes can target one kernel
 * interface instead of growing a third execution stack.
 *
 * Pure delegation, zero behavior change:
 *   action=next   → AtlasTaskServingService::next($client_id, $filters)   envelope untouched
 *   action=report → AtlasTaskServingService::report($client_id, $task_packet_id, $lease_id, $payload) envelope untouched
 *   anything else → refused with a distinct reason (no serving call is ever made)
 */
final class TaskServingWorkcellExecutorAdapter implements WorkcellExecutor
{
    public const ACTION_NEXT = 'next';

    public const ACTION_REPORT = 'report';

    public function __construct(private readonly AtlasTaskServingService $service) {}

    /**
     * @param  array<string,mixed>  $workcell
     * @return array<string,mixed>
     */
    public function execute(array $workcell): array
    {
        $action = (string) ($workcell['action'] ?? '');
        $clientId = (string) ($workcell['client_id'] ?? '');

        return match ($action) {
            self::ACTION_NEXT => $this->service->next($clientId, (array) ($workcell['filters'] ?? [])),
            self::ACTION_REPORT => $this->service->report(
                $clientId,
                (string) ($workcell['task_packet_id'] ?? ''),
                (string) ($workcell['lease_id'] ?? ''),
                (array) ($workcell['payload'] ?? []),
            ),
            default => [
                'schema' => 'atlas.engineering_kernel.workcell_executor.task_serving.v1',
                'executed' => false,
                'reason' => 'unknown_workcell_action',
                'action' => $action,
            ],
        };
    }
}
