<?php

declare(strict_types=1);

final class ExtremeDifferentiator058Industrial064PerformanceFixture
{
    public const CASE_ID = 'extreme-differentiator-058-industrial-064-performance';
    public const TASK_TYPE = 'performance';
    public const DIFFICULTY_LEVEL = 'L4';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Reduzir custo/latencia 064 sem mudar payload publico nem esconder tradeoffs.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}