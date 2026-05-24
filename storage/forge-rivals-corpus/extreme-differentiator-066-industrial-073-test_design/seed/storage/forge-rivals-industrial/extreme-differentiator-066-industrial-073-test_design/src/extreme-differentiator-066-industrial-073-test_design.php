<?php

declare(strict_types=1);

final class ExtremeDifferentiator066Industrial073TestDesignFixture
{
    public const CASE_ID = 'extreme-differentiator-066-industrial-073-test_design';
    public const TASK_TYPE = 'test_design';
    public const DIFFICULTY_LEVEL = 'L3';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Projetar testes 073 que capturem regressao, edge cases e comportamento esperado.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}