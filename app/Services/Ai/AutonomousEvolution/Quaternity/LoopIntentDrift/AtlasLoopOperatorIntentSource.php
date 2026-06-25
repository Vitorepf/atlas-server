<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift;

/**
 * Read-only source of operator-intent records the drift CLI reads from. Production binds a real source; tests
 * bind a fake.
 */
interface AtlasLoopOperatorIntentSource
{
    /**
     * @return list<array<string,mixed>>  the last N records, oldest → newest
     */
    public function recent(int $limit): array;
}
