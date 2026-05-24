<?php

declare(strict_types=1);

final class ExtremeDifferentiator018Industrial045MigrationFixture
{
    public const CASE_ID = 'extreme-differentiator-018-industrial-045-migration';
    public const TASK_TYPE = 'migration';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Executar migration 045 com compatibilidade, plano de rollback e validacao de dados.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}