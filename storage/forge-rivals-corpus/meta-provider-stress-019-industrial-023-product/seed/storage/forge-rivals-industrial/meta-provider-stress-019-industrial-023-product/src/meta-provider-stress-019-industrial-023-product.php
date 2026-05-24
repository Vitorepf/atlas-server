<?php

declare(strict_types=1);

final class MetaProviderStress019Industrial023ProductFixture
{
    public const CASE_ID = 'meta-provider-stress-019-industrial-023-product';
    public const TASK_TYPE = 'product';
    public const DIFFICULTY_LEVEL = 'L3';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Implementar ajuste de produto 023 equilibrando UX, regra de negocio e metricas.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}