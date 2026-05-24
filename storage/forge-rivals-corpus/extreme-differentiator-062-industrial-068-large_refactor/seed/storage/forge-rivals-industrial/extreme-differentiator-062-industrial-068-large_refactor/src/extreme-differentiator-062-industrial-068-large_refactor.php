<?php

declare(strict_types=1);

final class ExtremeDifferentiator062Industrial068LargeRefactorFixture
{
    public const CASE_ID = 'extreme-differentiator-062-industrial-068-large_refactor';
    public const TASK_TYPE = 'large_refactor';
    public const DIFFICULTY_LEVEL = 'L3';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Refatorar modulo legado 068 sem regressao funcional e com passos reversiveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}