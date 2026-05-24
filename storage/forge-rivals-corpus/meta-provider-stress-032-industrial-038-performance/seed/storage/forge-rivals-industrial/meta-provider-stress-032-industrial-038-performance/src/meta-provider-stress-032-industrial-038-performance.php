<?php

declare(strict_types=1);

final class MetaProviderStress032Industrial038PerformanceFixture
{
    public const CASE_ID = 'meta-provider-stress-032-industrial-038-performance';
    public const TASK_TYPE = 'performance';
    public const DIFFICULTY_LEVEL = 'L3';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Reduzir custo/latencia 038 sem mudar payload publico nem esconder tradeoffs.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}