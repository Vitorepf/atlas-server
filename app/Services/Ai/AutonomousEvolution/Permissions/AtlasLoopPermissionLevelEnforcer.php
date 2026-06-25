<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Permissions;

use InvalidArgumentException;

/**
 * SINGLE chokepoint that every Loop write/merge path must call before touching the workspace,
 * the FrozenJudge, or main. Sits ABOVE the pétreo sandbox floor — never replaces nor weakens it.
 *
 * Fail-closed contract:
 *   - master OFF (atlas.loop.master_enabled=false OR atlas.loop.permission_gradient.enabled=false)
 *     pins the allowed level to READ, denying every PROPOSE/WRITE/MERGE attempt
 *   - unknown phase → throw AtlasLoopPermissionDeniedException
 *   - operationLevel > registry.requiredLevelFor(phase) → throw AtlasLoopPermissionDeniedException
 *   - operationLevel <= required → silent pass
 *
 * Pure: no I/O, no DB, no logger emission on construct.
 */
final class AtlasLoopPermissionLevelEnforcer
{
    /** @var callable():bool */
    private $masterEnabledReader;

    public function __construct(
        private readonly AtlasLoopPermissionLevelRegistry $registry,
        ?callable $masterEnabledReader = null,
    ) {
        $this->masterEnabledReader = $masterEnabledReader ?? static function (): bool {
            if (! function_exists('config')) {
                return false;
            }
            $master = (bool) config('atlas.loop.master_enabled', false);
            $gradient = (bool) config('atlas.loop.permission_gradient.enabled', true);

            return $master && $gradient;
        };
    }

    public function assert(string $phase, string $operationLevel): void
    {
        $level = $this->parseLevel($operationLevel);

        if (! ($this->masterEnabledReader)()) {
            if ($level->isAtLeast(AtlasLoopPermissionLevel::PROPOSE)) {
                throw new AtlasLoopPermissionDeniedException(
                    phase: $phase,
                    attemptedLevel: $level->label(),
                    maxAllowedLevel: AtlasLoopPermissionLevel::READ->label(),
                    reason: 'master_or_gradient_disabled_fail_closed_at_read',
                );
            }

            return;
        }

        try {
            $required = $this->registry->requiredLevelFor($phase);
        } catch (InvalidArgumentException $e) {
            throw new AtlasLoopPermissionDeniedException(
                phase: $phase,
                attemptedLevel: $level->label(),
                maxAllowedLevel: AtlasLoopPermissionLevel::READ->label(),
                reason: 'unknown_phase',
            );
        }

        if ($level->compareTo($required) > 0) {
            throw new AtlasLoopPermissionDeniedException(
                phase: $phase,
                attemptedLevel: $level->label(),
                maxAllowedLevel: $required->label(),
                reason: 'operation_level_exceeds_phase_authority',
            );
        }
    }

    private function parseLevel(string $operationLevel): AtlasLoopPermissionLevel
    {
        $upper = strtoupper($operationLevel);

        return match ($upper) {
            'READ' => AtlasLoopPermissionLevel::READ,
            'PROPOSE' => AtlasLoopPermissionLevel::PROPOSE,
            'WRITE' => AtlasLoopPermissionLevel::WRITE,
            'MERGE' => AtlasLoopPermissionLevel::MERGE,
            default => throw new AtlasLoopPermissionDeniedException(
                phase: 'n/a',
                attemptedLevel: $operationLevel,
                maxAllowedLevel: AtlasLoopPermissionLevel::READ->label(),
                reason: 'unknown_operation_level',
            ),
        };
    }
}
