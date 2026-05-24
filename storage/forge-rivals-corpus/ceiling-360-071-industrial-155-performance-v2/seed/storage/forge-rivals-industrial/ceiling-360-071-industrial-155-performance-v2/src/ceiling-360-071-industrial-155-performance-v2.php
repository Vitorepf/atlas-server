<?php

declare(strict_types=1);

final class Ceiling360071Industrial155PerformanceV2Fixture
{
    public const CASE_ID = 'ceiling-360-071-industrial-155-performance-v2';
    public const TASK_TYPE = 'performance';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Reduzir custo/latencia 155 sem mudar payload publico nem esconder tradeoffs.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}