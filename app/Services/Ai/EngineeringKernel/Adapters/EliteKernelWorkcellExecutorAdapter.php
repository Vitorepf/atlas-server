<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Adapters;

use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\EngineeringKernel\WorkcellExecutor;
use InvalidArgumentException;

/**
 * The shared workcell-to-kernel seam.
 *
 * A workcell may describe how to execute, but it cannot invent a second
 * execution contract. Admission therefore requires a canonical ExecutionOrder
 * and the result is the Kernel's typed EngineeringOutcome envelope.
 */
final readonly class EliteKernelWorkcellExecutorAdapter implements WorkcellExecutor
{
    public function __construct(private EliteExecutorKernel $kernel) {}

    /** @param array<string,mixed> $workcell @return array<string,mixed> */
    public function execute(array $workcell): array
    {
        $order = $workcell['execution_order'] ?? null;
        if (! is_array($order)) {
            throw new InvalidArgumentException('workcell_execution_order_required');
        }

        return [
            'schema' => 'atlas.engineering_kernel.workcell_executor.elite_kernel.v1',
            'executed' => true,
            'order_hash' => ExecutionOrder::fromArray($order)->canonicalHash(),
            'engineering_outcome' => $this->kernel->execute(ExecutionOrder::fromArray($order))->toArray(),
        ];
    }
}
