<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopTierPromotionChainService;
use App\Services\Ai\NightShift\AtlasNightShiftAreaFocusContractRegistry;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Obra #14 H3.2 — read-only autonomy tier status per registered area + chain readiness.
 */
class AtlasAutonomyStatusCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:autonomy:status {--json : Emit JSON}';

    protected $description = 'Atlas Autônomos autonomy tier status: active tier per registered area + promotion chain readiness. Read-only.';

    public function handle(AtlasLoopTierPromotionChainService $chain, AtlasNightShiftAreaFocusContractRegistry $registry): int
    {
        $areas = [];
        foreach ($registry->registeredAreas() as $areaId) {
            $areas[$areaId] = ['autonomy_tier_active' => $chain->activeTier($areaId)];
        }

        $payload = [
            'schema_version' => 'atlas.loop.autonomy_tier_status.v1',
            'areas' => $areas,
            'readiness' => $chain->readiness(),
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        foreach ($areas as $areaId => $state) {
            $this->components->twoColumnDetail($areaId, 'tier '.$state['autonomy_tier_active']);
        }
        $r = $payload['readiness'];
        $this->components->twoColumnDetail('Chain readiness', sprintf(
            'implemented=%s audited=%s tier=%d operator_signed=%s',
            YesNo::format($r['implemented']),
            YesNo::format($r['audited']),
            $r['tier'],
            YesNo::format($r['operator_signed']),
        ));

        return self::SUCCESS;
    }
}
