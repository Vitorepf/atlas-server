<?php

declare(strict_types=1);

final class MetaProviderStress036Industrial042LargeRefactorFixture
{
    public const CASE_ID = 'meta-provider-stress-036-industrial-042-large_refactor';
    public const TASK_TYPE = 'large_refactor';
    public const DIFFICULTY_LEVEL = 'L2';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Refatorar modulo legado 042 sem regressao funcional e com passos reversiveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}