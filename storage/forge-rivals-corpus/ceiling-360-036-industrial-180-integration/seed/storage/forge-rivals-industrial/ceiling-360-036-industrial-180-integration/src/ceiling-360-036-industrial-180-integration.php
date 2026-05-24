<?php

declare(strict_types=1);

final class Ceiling360036Industrial180IntegrationFixture
{
    public const CASE_ID = 'ceiling-360-036-industrial-180-integration';
    public const TASK_TYPE = 'integration';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Estabilizar integracao 180 com contrato externo, retries e observabilidade.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}