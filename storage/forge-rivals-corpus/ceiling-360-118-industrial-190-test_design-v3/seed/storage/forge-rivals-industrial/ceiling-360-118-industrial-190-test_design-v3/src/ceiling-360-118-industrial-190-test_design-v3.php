<?php

declare(strict_types=1);

final class Ceiling360118Industrial190TestDesignV3Fixture
{
    public const CASE_ID = 'ceiling-360-118-industrial-190-test_design-v3';
    public const TASK_TYPE = 'test_design';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Projetar testes 190 que capturem regressao, edge cases e comportamento esperado.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}