<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Consolidation;

use App\Models\AtlasLoopCampaign;

/**
 * Narrow port the supervisor (and any other root-adjacent consumer) depends on, so the root layer never
 * imports the Discovery concrete. Implemented by AtlasLoopQueueRefiller in the Discovery layer; the
 * dependency arrow now points Root → Consolidation ← Discovery, breaking the previous root↔Discovery cycle.
 */
interface AtlasLoopRefillerPort
{
    /**
     * Refill the campaign queue up to $want tasks. Returns the refill report (minted/quarantined/etc).
     *
     * @return array<string,mixed>
     */
    public function refill(AtlasLoopCampaign $campaign, int $want): array;
}
