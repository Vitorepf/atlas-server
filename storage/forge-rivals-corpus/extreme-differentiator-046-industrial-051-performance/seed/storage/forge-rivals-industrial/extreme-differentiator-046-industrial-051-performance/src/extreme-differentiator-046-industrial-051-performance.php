<?php

declare(strict_types=1);

final class ExtremeDifferentiator046Industrial051PerformanceFixture
{
    public const CASE_ID = 'extreme-differentiator-046-industrial-051-performance';
    public const TASK_TYPE = 'performance';
    public const DIFFICULTY_LEVEL = 'L1';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Reduzir custo/latencia 051 sem mudar payload publico nem esconder tradeoffs.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}