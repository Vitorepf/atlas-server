<?php

declare(strict_types=1);

final class ExtremeDifferentiator053Industrial058MigrationFixture
{
    public const CASE_ID = 'extreme-differentiator-053-industrial-058-migration';
    public const TASK_TYPE = 'migration';
    public const DIFFICULTY_LEVEL = 'L3';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Executar migration 058 com compatibilidade, plano de rollback e validacao de dados.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}