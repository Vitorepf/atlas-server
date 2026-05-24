<?php

declare(strict_types=1);

final class MetaProviderStress020Industrial024IntegrationFixture
{
    public const CASE_ID = 'meta-provider-stress-020-industrial-024-integration';
    public const TASK_TYPE = 'integration';
    public const DIFFICULTY_LEVEL = 'L4';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Estabilizar integracao 024 com contrato externo, retries e observabilidade.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}