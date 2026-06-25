<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift;

/**
 * Persistence contract for the ambition-faculty target. Production binds a real store (file/DB); the drift CLI
 * only calls `save()` on apply — never on inspect — and only when ALL gates are open.
 */
interface AtlasLoopAmbitionFacultyStore
{
    public function current(): AtlasLoopAmbitionFacultyTarget;

    public function save(AtlasLoopAmbitionFacultyTarget $target): void;
}
