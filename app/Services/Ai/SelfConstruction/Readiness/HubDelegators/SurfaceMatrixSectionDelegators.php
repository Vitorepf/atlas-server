<?php

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

trait SurfaceMatrixSectionDelegators
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function surfaceMatrix(array $options = []): array
    {
        return $this->surfaceMatrixSection()->surfaceMatrix($options);
    }
}
