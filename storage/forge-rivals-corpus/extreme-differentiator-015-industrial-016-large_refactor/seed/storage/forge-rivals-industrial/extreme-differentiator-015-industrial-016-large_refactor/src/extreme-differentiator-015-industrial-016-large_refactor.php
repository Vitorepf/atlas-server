<?php

declare(strict_types=1);

final class ExtremeDifferentiator015Industrial016LargeRefactorFixture
{
    public const CASE_ID = 'extreme-differentiator-015-industrial-016-large_refactor';
    public const TASK_TYPE = 'large_refactor';
    public const DIFFICULTY_LEVEL = 'L1';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Refatorar modulo legado 016 sem regressao funcional e com passos reversiveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}