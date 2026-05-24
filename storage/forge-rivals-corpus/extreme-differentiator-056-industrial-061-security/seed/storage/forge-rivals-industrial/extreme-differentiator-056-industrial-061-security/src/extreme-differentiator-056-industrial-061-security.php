<?php

declare(strict_types=1);

final class ExtremeDifferentiator056Industrial061SecurityFixture
{
    public const CASE_ID = 'extreme-differentiator-056-industrial-061-security';
    public const TASK_TYPE = 'security';
    public const DIFFICULTY_LEVEL = 'L1';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Corrigir risco de seguranca 061 com fail-closed e evidencia de nao regressao.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}