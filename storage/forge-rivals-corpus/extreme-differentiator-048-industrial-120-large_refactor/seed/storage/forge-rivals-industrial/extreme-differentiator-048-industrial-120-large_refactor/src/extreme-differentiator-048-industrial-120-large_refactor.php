<?php

declare(strict_types=1);

final class ExtremeDifferentiator048Industrial120LargeRefactorFixture
{
    public const CASE_ID = 'extreme-differentiator-048-industrial-120-large_refactor';
    public const TASK_TYPE = 'large_refactor';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Refatorar modulo legado 120 sem regressao funcional e com passos reversiveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}