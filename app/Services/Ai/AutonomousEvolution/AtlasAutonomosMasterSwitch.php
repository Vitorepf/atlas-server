<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * Preferred Autônomos name for the master switch (Obra 1).
 * Thin facade over {@see AtlasLoopMasterSwitch} (keep-list; class stays final).
 */
final class AtlasAutonomosMasterSwitch
{
    public const KEY_AUTONOMOS = AtlasLoopMasterSwitch::KEY_AUTONOMOS;

    public const KEY = AtlasLoopMasterSwitch::KEY;

    public static function enabled(): bool
    {
        return AtlasLoopMasterSwitch::enabled();
    }

    public static function state(): string
    {
        return AtlasLoopMasterSwitch::state();
    }

    public static function on(): bool
    {
        return AtlasLoopMasterSwitch::on();
    }

    public static function off(): bool
    {
        return AtlasLoopMasterSwitch::off();
    }
}
