<?php

declare(strict_types=1);

final class ExtremeDifferentiator039Industrial043MultiDayTaskFixture
{
    public const CASE_ID = 'extreme-differentiator-039-industrial-043-multi_day_task';
    public const TASK_TYPE = 'multi_day_task';
    public const DIFFICULTY_LEVEL = 'L3';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Decompor tarefa multi-dia 043 em slices, checkpoints e evidencia incremental.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}