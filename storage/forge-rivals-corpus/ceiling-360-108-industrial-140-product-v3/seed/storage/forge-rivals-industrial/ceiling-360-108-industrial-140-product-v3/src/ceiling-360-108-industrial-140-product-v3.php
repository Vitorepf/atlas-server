<?php

declare(strict_types=1);

final class Ceiling360108Industrial140ProductV3Fixture
{
    public const CASE_ID = 'ceiling-360-108-industrial-140-product-v3';
    public const TASK_TYPE = 'product';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Implementar ajuste de produto 140 equilibrando UX, regra de negocio e metricas.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}