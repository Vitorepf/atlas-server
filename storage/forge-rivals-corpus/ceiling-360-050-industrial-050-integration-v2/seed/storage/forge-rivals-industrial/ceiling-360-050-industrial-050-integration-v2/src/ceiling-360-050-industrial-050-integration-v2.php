<?php

declare(strict_types=1);

final class Ceiling360050Industrial050IntegrationV2Fixture
{
    public const CASE_ID = 'ceiling-360-050-industrial-050-integration-v2';
    public const TASK_TYPE = 'integration';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Estabilizar integracao 050 com contrato externo, retries e observabilidade.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}