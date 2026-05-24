<?php

declare(strict_types=1);

final class Ceiling360042Industrial010ProductV2Fixture
{
    public const CASE_ID = 'ceiling-360-042-industrial-010-product-v2';
    public const TASK_TYPE = 'product';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Implementar ajuste de produto 010 equilibrando UX, regra de negocio e metricas.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}