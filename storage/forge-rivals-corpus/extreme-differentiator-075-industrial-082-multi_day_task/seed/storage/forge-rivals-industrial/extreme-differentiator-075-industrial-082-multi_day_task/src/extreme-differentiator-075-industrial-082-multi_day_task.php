<?php

declare(strict_types=1);

final class ExtremeDifferentiator075Industrial082MultiDayTaskFixture
{
    public const CASE_ID = 'extreme-differentiator-075-industrial-082-multi_day_task';
    public const TASK_TYPE = 'multi_day_task';
    public const DIFFICULTY_LEVEL = 'L2';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Decompor tarefa multi-dia 082 em slices, checkpoints e evidencia incremental.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}