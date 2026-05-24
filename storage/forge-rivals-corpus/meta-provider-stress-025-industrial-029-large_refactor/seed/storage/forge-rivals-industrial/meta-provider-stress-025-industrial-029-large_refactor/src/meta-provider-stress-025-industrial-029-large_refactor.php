<?php

declare(strict_types=1);

final class MetaProviderStress025Industrial029LargeRefactorFixture
{
    public const CASE_ID = 'meta-provider-stress-025-industrial-029-large_refactor';
    public const TASK_TYPE = 'large_refactor';
    public const DIFFICULTY_LEVEL = 'L4';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Refatorar modulo legado 029 sem regressao funcional e com passos reversiveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}