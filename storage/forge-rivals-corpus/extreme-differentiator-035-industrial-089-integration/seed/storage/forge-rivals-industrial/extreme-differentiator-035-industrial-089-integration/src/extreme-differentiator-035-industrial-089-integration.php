<?php

declare(strict_types=1);

final class ExtremeDifferentiator035Industrial089IntegrationFixture
{
    public const CASE_ID = 'extreme-differentiator-035-industrial-089-integration';
    public const TASK_TYPE = 'integration';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Estabilizar integracao 089 com contrato externo, retries e observabilidade.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}