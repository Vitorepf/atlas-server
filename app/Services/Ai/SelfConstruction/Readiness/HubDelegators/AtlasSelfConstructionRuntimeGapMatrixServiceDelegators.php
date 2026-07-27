<?php

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimeGapMatrixService;

trait AtlasSelfConstructionRuntimeGapMatrixServiceDelegators
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionRuntimeGapMatrix(array $options = []): array
    {
        return (new AtlasSelfConstructionRuntimeGapMatrixService($this))->matrix([
            'runtime_promotion_receipt' => (array) ($options['runtime_promotion_receipt'] ?? $this->decodeJsonOption($options['runtime_promotion_receipt_json'] ?? null)),
            'persist_runtime_promotion_receipt' => (bool) ($options['persist_runtime_promotion_receipt'] ?? false),
        ]);
    }
}
