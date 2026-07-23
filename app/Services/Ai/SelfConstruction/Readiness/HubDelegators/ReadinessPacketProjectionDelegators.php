<?php

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimeGapMatrixService;
use App\Services\Ai\SelfConstruction\Readiness\CertificationWorkbenchEvaluator;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessCertificationChainQuartetProjector;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessPacketProjection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessStatusProjection;

trait ReadinessPacketProjectionDelegators
{
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null}  $options
     * @return array<string, mixed>
     */
    public function metaSddPacket(array $options = []): array
    {
        return app(ReadinessPacketProjection::class)->metaSddPacket(
            $options,
            $this->snapshot($options),
        );
    }
}
