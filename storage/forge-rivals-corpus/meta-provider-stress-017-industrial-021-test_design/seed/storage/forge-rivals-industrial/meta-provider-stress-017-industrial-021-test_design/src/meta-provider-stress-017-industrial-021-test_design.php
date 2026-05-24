<?php

declare(strict_types=1);

final class MetaProviderStress017Industrial021TestDesignFixture
{
    public const CASE_ID = 'meta-provider-stress-017-industrial-021-test_design';
    public const TASK_TYPE = 'test_design';
    public const DIFFICULTY_LEVEL = 'L1';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Projetar testes 021 que capturem regressao, edge cases e comportamento esperado.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}