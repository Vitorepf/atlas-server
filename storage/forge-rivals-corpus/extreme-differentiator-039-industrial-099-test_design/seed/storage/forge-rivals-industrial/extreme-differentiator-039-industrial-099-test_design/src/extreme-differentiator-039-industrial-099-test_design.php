<?php

declare(strict_types=1);

final class ExtremeDifferentiator039Industrial099TestDesignFixture
{
    public const CASE_ID = 'extreme-differentiator-039-industrial-099-test_design';
    public const TASK_TYPE = 'test_design';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Projetar testes 099 que capturem regressao, edge cases e comportamento esperado.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}