<?php

declare(strict_types=1);

final class MetaProviderStress030Industrial036ProductFixture
{
    public const CASE_ID = 'meta-provider-stress-030-industrial-036-product';
    public const TASK_TYPE = 'product';
    public const DIFFICULTY_LEVEL = 'L1';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Implementar ajuste de produto 036 equilibrando UX, regra de negocio e metricas.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}