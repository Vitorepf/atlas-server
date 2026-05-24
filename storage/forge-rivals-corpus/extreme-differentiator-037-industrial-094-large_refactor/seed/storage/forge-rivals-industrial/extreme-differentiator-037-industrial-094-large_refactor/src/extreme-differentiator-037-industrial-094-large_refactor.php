<?php

declare(strict_types=1);

final class ExtremeDifferentiator037Industrial094LargeRefactorFixture
{
    public const CASE_ID = 'extreme-differentiator-037-industrial-094-large_refactor';
    public const TASK_TYPE = 'large_refactor';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Refatorar modulo legado 094 sem regressao funcional e com passos reversiveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}