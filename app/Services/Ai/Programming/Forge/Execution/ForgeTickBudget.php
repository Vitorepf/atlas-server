<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Forge\Execution;

use InvalidArgumentException;

final readonly class ForgeTickBudget
{
    private function __construct(public int $maxPackets, public int $leaseSeconds, public bool $allowProvider) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $packets = $data['max_packets'] ?? null;
        $lease = $data['lease_seconds'] ?? null;
        if (! is_int($packets) || $packets < 1 || $packets > 10 || ! is_int($lease) || $lease < 30 || $lease > 86400 || ! is_bool($data['allow_provider'] ?? null)) {
            throw new InvalidArgumentException('forge_tick_budget_invalid');
        }

        return new self($packets, $lease, $data['allow_provider']);
    }
}
