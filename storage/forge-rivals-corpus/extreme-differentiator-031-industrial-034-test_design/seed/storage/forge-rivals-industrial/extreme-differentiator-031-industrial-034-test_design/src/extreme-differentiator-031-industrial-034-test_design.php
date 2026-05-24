<?php

declare(strict_types=1);

final class ExtremeDifferentiator031Industrial034TestDesignFixture
{
    public const CASE_ID = 'extreme-differentiator-031-industrial-034-test_design';
    public const TASK_TYPE = 'test_design';
    public const DIFFICULTY_LEVEL = 'L4';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Projetar testes 034 que capturem regressao, edge cases e comportamento esperado.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}