<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Forge\Execution;

final readonly class ForgeTickResult
{
    private function __construct(public string $status, public ForgeObraSnapshot $snapshot, public ?string $packetId, public ?string $cycleId, public ?string $reason) {}

    public static function planned(ForgeObraSnapshot $snapshot, string $packetId, string $cycleId): self
    {
        return new self('planned', $snapshot, $packetId, $cycleId, null);
    }

    public static function idle(ForgeObraSnapshot $snapshot, string $reason): self
    {
        return new self('idle', $snapshot, null, null, $reason);
    }
}
