<?php

declare(strict_types=1);

final class MetaProviderStress042Industrial050IntegrationFixture
{
    public const CASE_ID = 'meta-provider-stress-042-industrial-050-integration';
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