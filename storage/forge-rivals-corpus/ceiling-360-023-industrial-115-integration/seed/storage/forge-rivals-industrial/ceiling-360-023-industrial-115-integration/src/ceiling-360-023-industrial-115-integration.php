<?php

declare(strict_types=1);

final class Ceiling360023Industrial115IntegrationFixture
{
    public const CASE_ID = 'ceiling-360-023-industrial-115-integration';
    public const TASK_TYPE = 'integration';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Estabilizar integracao 115 com contrato externo, retries e observabilidade.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}