<?php

declare(strict_types=1);

final class MetaProviderStress041Industrial049ProductFixture
{
    public const CASE_ID = 'meta-provider-stress-041-industrial-049-product';
    public const TASK_TYPE = 'product';
    public const DIFFICULTY_LEVEL = 'L4';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Implementar ajuste de produto 049 equilibrando UX, regra de negocio e metricas.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}