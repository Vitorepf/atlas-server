<?php

declare(strict_types=1);

final class MetaProviderStress021Industrial025PerformanceFixture
{
    public const CASE_ID = 'meta-provider-stress-021-industrial-025-performance';
    public const TASK_TYPE = 'performance';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Reduzir custo/latencia 025 sem mudar payload publico nem esconder tradeoffs.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}