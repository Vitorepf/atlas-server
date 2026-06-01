<?php

namespace App\Services\Ai\Autonomy;

/**
 * Wraps the ladder's automatic demote rule (the runbook: "Demote nunca exige
 * signature; e automatico via metrica") and turns a demote decision into an
 * auto-generated demote receipt the operator is notified of. No signature is
 * required — that is the point of an automatic safety demote.
 *
 * @see docs/engineering-knowledge-base/atlas-autonomy-ladder-promotion-runbook.md
 */
class AtlasAutonomyDemoteWatchdog
{
    public function __construct(
        private readonly AtlasAutonomyLadderRuntimeService $ladder,
    ) {}

    /**
     * @param  array<int,array<string,float|int>>  $recentCycles  oldest..newest
     * @return array<string,mixed>
     */
    public function watch(string $currentLevel, array $recentCycles, int $consecutiveBreachTrigger = 2): array
    {
        $decision = $this->ladder->evaluateDemote($currentLevel, $recentCycles, $consecutiveBreachTrigger);

        $receipt = null;
        if (($decision['demote'] ?? false) === true) {
            $receipt = [
                'schema_version' => 'atlas.autonomy.demote_receipt.v1',
                'kind' => 'automatic_demote',
                'from_level' => $decision['from_level'],
                'to_level' => $decision['to_level'],
                'signature_required' => false,
                'operator_notification' => true,
                'reason' => $decision['reason'],
                'breaching_cycles' => $decision['breaching_cycles'] ?? [],
            ];
        }

        return [
            'decision' => $decision,
            'demote_receipt' => $receipt,
        ];
    }
}
