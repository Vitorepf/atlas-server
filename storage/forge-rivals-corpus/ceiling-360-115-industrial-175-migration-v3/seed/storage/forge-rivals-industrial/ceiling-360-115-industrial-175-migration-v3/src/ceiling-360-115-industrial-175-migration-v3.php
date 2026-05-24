<?php

declare(strict_types=1);

final class Ceiling360115Industrial175MigrationV3Fixture
{
    public const CASE_ID = 'ceiling-360-115-industrial-175-migration-v3';
    public const TASK_TYPE = 'migration';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Executar migration 175 com compatibilidade, plano de rollback e validacao de dados.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}