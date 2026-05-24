<?php

declare(strict_types=1);

final class Ceiling360085Industrial025PerformanceV3Fixture
{
    public const CASE_ID = 'ceiling-360-085-industrial-025-performance-v3';
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