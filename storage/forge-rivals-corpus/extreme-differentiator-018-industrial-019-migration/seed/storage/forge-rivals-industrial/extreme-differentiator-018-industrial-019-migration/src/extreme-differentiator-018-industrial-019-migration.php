<?php

declare(strict_types=1);

final class ExtremeDifferentiator018Industrial019MigrationFixture
{
    public const CASE_ID = 'extreme-differentiator-018-industrial-019-migration';
    public const TASK_TYPE = 'migration';
    public const DIFFICULTY_LEVEL = 'L4';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Executar migration 019 com compatibilidade, plano de rollback e validacao de dados.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}