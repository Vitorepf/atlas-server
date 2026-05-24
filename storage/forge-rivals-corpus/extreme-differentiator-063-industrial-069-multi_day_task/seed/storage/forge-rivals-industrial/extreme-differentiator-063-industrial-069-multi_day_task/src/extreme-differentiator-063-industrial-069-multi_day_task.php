<?php

declare(strict_types=1);

final class ExtremeDifferentiator063Industrial069MultiDayTaskFixture
{
    public const CASE_ID = 'extreme-differentiator-063-industrial-069-multi_day_task';
    public const TASK_TYPE = 'multi_day_task';
    public const DIFFICULTY_LEVEL = 'L4';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Decompor tarefa multi-dia 069 em slices, checkpoints e evidencia incremental.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}