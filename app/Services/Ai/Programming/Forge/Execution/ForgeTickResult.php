<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Forge\Execution;

final readonly class ForgeTickResult
{
    private function __construct(
        public string $status,
        public ForgeObraSnapshot $snapshot,
        public ?string $packetId,
        public ?string $cycleId,
        public ?string $reason,
        public ?array $kernelOutcome = null,
    ) {}

    public static function planned(ForgeObraSnapshot $snapshot, string $packetId, string $cycleId, ?array $kernelOutcome = null): self
    {
        return new self($kernelOutcome === null ? 'planned' : 'executed', $snapshot, $packetId, $cycleId, null, $kernelOutcome);
    }

    public static function blocked(ForgeObraSnapshot $snapshot, string $packetId, string $cycleId, string $reason, ?array $kernelOutcome = null): self
    {
        return new self('blocked', $snapshot, $packetId, $cycleId, $reason, $kernelOutcome);
    }

    public static function idle(ForgeObraSnapshot $snapshot, string $reason): self
    {
        return new self('idle', $snapshot, null, null, $reason);
    }
}
