<?php

declare(strict_types=1);

final class ExtremeDifferentiator036Industrial090PerformanceFixture
{
    public const CASE_ID = 'extreme-differentiator-036-industrial-090-performance';
    public const TASK_TYPE = 'performance';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Reduzir custo/latencia 090 sem mudar payload publico nem esconder tradeoffs.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}