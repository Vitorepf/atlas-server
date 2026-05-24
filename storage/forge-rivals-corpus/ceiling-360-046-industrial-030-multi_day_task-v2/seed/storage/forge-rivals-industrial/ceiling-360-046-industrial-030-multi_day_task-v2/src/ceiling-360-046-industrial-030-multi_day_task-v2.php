<?php

declare(strict_types=1);

final class Ceiling360046Industrial030MultiDayTaskV2Fixture
{
    public const CASE_ID = 'ceiling-360-046-industrial-030-multi_day_task-v2';
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