<?php

declare(strict_types=1);

final class ExtremeDifferentiator012Industrial030MultiDayTaskFixture
{
    public const CASE_ID = 'extreme-differentiator-012-industrial-030-multi_day_task';
    public const TASK_TYPE = 'multi_day_task';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Decompor tarefa multi-dia 030 em slices, checkpoints e evidencia incremental.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}