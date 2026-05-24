<?php

declare(strict_types=1);

final class Ceiling360051Industrial055LargeRefactorV2Fixture
{
    public const CASE_ID = 'ceiling-360-051-industrial-055-large_refactor-v2';
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