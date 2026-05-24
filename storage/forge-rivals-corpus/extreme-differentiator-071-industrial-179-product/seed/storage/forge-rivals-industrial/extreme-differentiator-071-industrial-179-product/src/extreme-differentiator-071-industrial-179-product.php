<?php

declare(strict_types=1);

final class ExtremeDifferentiator071Industrial179ProductFixture
{
    public const CASE_ID = 'extreme-differentiator-071-industrial-179-product';
    public const TASK_TYPE = 'product';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Implementar ajuste de produto 179 equilibrando UX, regra de negocio e metricas.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}