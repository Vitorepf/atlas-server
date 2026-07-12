<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeWorker;

use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use InvalidArgumentException;

/**
 * Validates the one shared Quality Foundry order at the Autonomos boundary.
 * The Brain/Task Fabric provides the order; this class never synthesizes hashes.
 */
final class AutonomosExecutionOrderBinding
{
    public const SCHEMA = 'atlas.autonomos.execution_order_binding.v1';

    /** @param array<string,mixed> $data @return array{schema:string,order_hash:string,execution_order:array<string,mixed>} */
    public static function fromArray(array $data): array
    {
        $order = array_key_exists('execution_order', $data) ? $data['execution_order'] : $data;
        if (! is_array($order) || $order === []) {
            throw new InvalidArgumentException('autonomos_execution_order_required');
        }

        $parsed = ExecutionOrder::fromArray($order);
        if ($parsed->mode !== 'autonomos') {
            throw new InvalidArgumentException('autonomos_execution_order_mode_invalid');
        }

        return [
            'schema' => self::SCHEMA,
            'order_hash' => $parsed->canonicalHash(),
            'execution_order' => $parsed->toArray(),
        ];
    }

    /** @param array<string,mixed> $payload */
    public static function fromPayload(array $payload): ?array
    {
        if (! array_key_exists('execution_order', $payload)) {
            return null;
        }

        return self::fromArray(['execution_order' => $payload['execution_order']]);
    }
}
