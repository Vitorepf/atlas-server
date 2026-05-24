<?php

declare(strict_types=1);

final class ExtremeDifferentiator011Industrial012PerformanceFixture
{
    public const CASE_ID = 'extreme-differentiator-011-industrial-012-performance';
    public const TASK_TYPE = 'performance';
    public const DIFFICULTY_LEVEL = 'L2';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Reduzir custo/latencia 012 sem mudar payload publico nem esconder tradeoffs.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}