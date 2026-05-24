<?php

declare(strict_types=1);

final class Ceiling360011Industrial055LargeRefactorFixture
{
    public const CASE_ID = 'ceiling-360-011-industrial-055-large_refactor';
    public const TASK_TYPE = 'large_refactor';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Refatorar modulo legado 055 sem regressao funcional e com passos reversiveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}