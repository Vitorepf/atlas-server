<?php

declare(strict_types=1);

final class ExtremeDifferentiator063Industrial159LargeRefactorFixture
{
    public const CASE_ID = 'extreme-differentiator-063-industrial-159-large_refactor';
    public const TASK_TYPE = 'large_refactor';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Refatorar modulo legado 159 sem regressao funcional e com passos reversiveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}