<?php

declare(strict_types=1);

final class ExtremeDifferentiator065Industrial071MigrationFixture
{
    public const CASE_ID = 'extreme-differentiator-065-industrial-071-migration';
    public const TASK_TYPE = 'migration';
    public const DIFFICULTY_LEVEL = 'L1';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Executar migration 071 com compatibilidade, plano de rollback e validacao de dados.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}