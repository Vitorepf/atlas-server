<?php

declare(strict_types=1);

final class Ceiling360037Industrial185LargeRefactorFixture
{
    public const CASE_ID = 'ceiling-360-037-industrial-185-large_refactor';
    public const TASK_TYPE = 'large_refactor';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Refatorar modulo legado 185 sem regressao funcional e com passos reversiveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}