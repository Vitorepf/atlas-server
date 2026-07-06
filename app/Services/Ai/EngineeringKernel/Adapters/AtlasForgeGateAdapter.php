<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Adapters;

use App\Services\Ai\EngineeringKernel\AcceptanceBundle;
use App\Services\Ai\EngineeringKernel\CertVerdict;
use App\Services\Ai\EngineeringKernel\TrustLevel;

/**
 * @deprecated Consolidated into AtlasAutonomosGateAdapter (parametrised by TrustLevel).
 *             This class is a thin backward-compatible shell that delegates to the parent
 *             with TrustLevel::Forge. Use AtlasAutonomosGateAdapter directly.
 */
final class AtlasForgeGateAdapter extends AtlasAutonomosGateAdapter
{
    public function __construct()
    {
        parent::__construct(trust: TrustLevel::Forge);
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    public function certifyForgeDelivery(array $evidence): CertVerdict
    {
        return $this->certifyDelivery($evidence);
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    public function bundleFromForgeEvidence(array $evidence): AcceptanceBundle
    {
        return $this->bundleFromEvidence($evidence);
    }
}
