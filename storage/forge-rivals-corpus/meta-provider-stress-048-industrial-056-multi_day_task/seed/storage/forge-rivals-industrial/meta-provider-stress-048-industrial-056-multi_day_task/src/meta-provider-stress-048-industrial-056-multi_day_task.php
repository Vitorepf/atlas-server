<?php

declare(strict_types=1);

final class MetaProviderStress048Industrial056MultiDayTaskFixture
{
    public const CASE_ID = 'meta-provider-stress-048-industrial-056-multi_day_task';
    public const TASK_TYPE = 'multi_day_task';
    public const DIFFICULTY_LEVEL = 'L1';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Decompor tarefa multi-dia 056 em slices, checkpoints e evidencia incremental.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}