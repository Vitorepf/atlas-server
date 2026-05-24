<?php

declare(strict_types=1);

final class ExtremeDifferentiator061Industrial154IntegrationFixture
{
    public const CASE_ID = 'extreme-differentiator-061-industrial-154-integration';
    public const TASK_TYPE = 'integration';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Estabilizar integracao 154 com contrato externo, retries e observabilidade.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}