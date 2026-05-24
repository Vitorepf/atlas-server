<?php

declare(strict_types=1);

final class ExtremeDifferentiator045Industrial114ProductFixture
{
    public const CASE_ID = 'extreme-differentiator-045-industrial-114-product';
    public const TASK_TYPE = 'product';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Implementar ajuste de produto 114 equilibrando UX, regra de negocio e metricas.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}