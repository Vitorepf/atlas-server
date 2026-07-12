<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Forge;

use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\EngineeringOutcome;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final readonly class ForgeEliteKernelExecutionAdapter implements ForgeWorkPacketExecutionPort
{
    public function __construct(private EliteExecutorKernel $kernel) {}

    public function execute(ExecutionOrder $order): EngineeringOutcome
    {
        try {
            // A cycle's idempotency key prevents duplicate replay of itself;
            // this second workspace lock serializes distinct cycles that would
            // otherwise integrate into the same checkout concurrently.
            return Cache::lock(self::workspaceLockKey($order->workspace), 3600)
                ->block(1, fn (): EngineeringOutcome => $this->kernel->execute($order));
        } catch (LockTimeoutException $exception) {
            throw new \RuntimeException('forge_workspace_integration_serial_lock_unavailable', 0, $exception);
        }
    }

    public static function workspaceLockKey(string $workspace): string
    {
        return 'atlas:forge:integration:workspace:'.hash('sha256', trim($workspace));
    }
}
