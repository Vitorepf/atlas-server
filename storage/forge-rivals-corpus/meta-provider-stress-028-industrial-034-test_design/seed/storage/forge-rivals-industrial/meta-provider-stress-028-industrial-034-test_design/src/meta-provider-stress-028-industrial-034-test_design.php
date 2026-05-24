<?php

declare(strict_types=1);

final class MetaProviderStress028Industrial034TestDesignFixture
{
    public const CASE_ID = 'meta-provider-stress-028-industrial-034-test_design';
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