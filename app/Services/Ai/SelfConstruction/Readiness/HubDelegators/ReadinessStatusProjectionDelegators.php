<?php

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimeGapMatrixService;
use App\Services\Ai\SelfConstruction\Readiness\CertificationWorkbenchEvaluator;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessCertificationChainQuartetProjector;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessPacketProjection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessStatusProjection;

trait ReadinessStatusProjectionDelegators
{
    /**
     * @param  array{workspace?: string|null}  $options
     * @return array<string, mixed>
     */
    public function snapshot(array $options = []): array
    {
        return app(ReadinessStatusProjection::class)->snapshot(
            $options,
            fn (): array => $this->requiredDocs(),
        );
    }
}
