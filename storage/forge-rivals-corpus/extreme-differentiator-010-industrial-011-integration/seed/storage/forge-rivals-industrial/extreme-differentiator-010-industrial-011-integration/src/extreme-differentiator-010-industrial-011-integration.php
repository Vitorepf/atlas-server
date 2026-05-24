<?php

declare(strict_types=1);

final class ExtremeDifferentiator010Industrial011IntegrationFixture
{
    public const CASE_ID = 'extreme-differentiator-010-industrial-011-integration';
    public const TASK_TYPE = 'integration';
    public const DIFFICULTY_LEVEL = 'L1';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Estabilizar integracao 011 com contrato externo, retries e observabilidade.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}