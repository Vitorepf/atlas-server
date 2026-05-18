<?php

namespace App\Services\Ai\Programming\Kernel;

use RuntimeException;

class ProgrammingAdapterException extends RuntimeException
{
    public static function manifestMissing(): self
    {
        return new self('Programming domain manifest not registered. Run ProgrammingDomainManifestSeeder::seed() or atlas:ai:domain-runtime --action=seed-defaults first.');
    }

    public static function policyBlocked(string $action, string $reason): self
    {
        return new self("Programming policy bridge blocked action [{$action}]: {$reason}");
    }

    public static function handoffFailed(string $detail): self
    {
        return new self("Forge handoff failed: {$detail}");
    }

    public static function evidenceBridgeUnavailable(): self
    {
        return new self('Evidence runtime bridge unavailable; Programming adapter cannot attach evidence refs.');
    }

    public static function missionRequired(): self
    {
        return new self('Programming adapter requires an AiMission (Meta 1) to translate prompt into work_order.');
    }
}
