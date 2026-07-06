<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Services\Ai\AutonomousEvolution\AtlasLoopTierPromotionChainService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\ProductiveExecutionModeGateEvaluator;

/**
 * Obra #14 H3.2 · S50 preflight (implement-only, off the default invoke path).
 *
 * Pure decision envelope for the Forge productive-execution lane: consumes the
 * six confirm-gates ({@see ProductiveExecutionModeGateEvaluator}) PLUS the
 * operator-signed autonomy tier ({@see AtlasLoopTierPromotionChainService}).
 * Tier 0 ⇒ ALWAYS block, no matter how green the gates are. NEVER invokes a
 * provider; it only says whether a real invocation WOULD be allowed.
 */
class AtlasForgeConfirmGatePreflightService
{
    public const SCHEMA_VERSION = 'atlas.forge.confirm_gate_preflight.v1';

    public function __construct(
        private readonly ProductiveExecutionModeGateEvaluator $gateEvaluator,
        private readonly AtlasLoopTierPromotionChainService $tierChain,
    ) {}

    /**
     * @param  array<string,mixed>  $confirmGates
     * @param  array<string,mixed>  $providerAuthorization
     * @param  array<string,mixed>  $work
     * @return array<string,mixed>
     */
    public function preflight(array $confirmGates, array $providerAuthorization, array $work, string $areaId): array
    {
        $gate = $this->gateEvaluator->evaluate($confirmGates, $providerAuthorization, $work);
        $tier = $this->tierChain->activeTier($areaId);

        $reasons = (array) $gate['blocking_reasons'];
        if ($tier < 1) {
            $reasons[] = 'autonomy_tier_zero';
        }

        $allowed = ($gate['execute_allowed'] ?? false) === true && $tier >= 1;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision' => $allowed ? 'allow' : 'block',
            'execute_allowed' => $allowed,
            'area_id' => $areaId,
            'autonomy_tier_active' => $tier,
            'mode_gate' => $gate,
            'blocking_reasons' => $allowed ? [] : $reasons,
        ];
    }
}
