<?php

declare(strict_types=1);

final class Industrial006MigrationFixture
{
    public const CASE_ID = 'industrial-006-migration';
    public const TASK_TYPE = 'migration';
    public const DIFFICULTY_LEVEL = 'L1';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Executar migration 006 com compatibilidade, plano de rollback e validacao de dados.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}