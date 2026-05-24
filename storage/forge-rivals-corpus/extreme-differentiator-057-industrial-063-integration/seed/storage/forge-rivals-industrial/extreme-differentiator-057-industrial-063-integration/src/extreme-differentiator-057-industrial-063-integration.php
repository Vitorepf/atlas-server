<?php

declare(strict_types=1);

final class ExtremeDifferentiator057Industrial063IntegrationFixture
{
    public const CASE_ID = 'extreme-differentiator-057-industrial-063-integration';
    public const TASK_TYPE = 'integration';
    public const DIFFICULTY_LEVEL = 'L3';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Estabilizar integracao 063 com contrato externo, retries e observabilidade.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}