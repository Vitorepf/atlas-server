<?php

declare(strict_types=1);

final class ExtremeDifferentiator033Industrial037IntegrationFixture
{
    public const CASE_ID = 'extreme-differentiator-033-industrial-037-integration';
    public const TASK_TYPE = 'integration';
    public const DIFFICULTY_LEVEL = 'L2';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Estabilizar integracao 037 com contrato externo, retries e observabilidade.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}