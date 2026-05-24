<?php

declare(strict_types=1);

final class ExtremeDifferentiator043Industrial048SecurityFixture
{
    public const CASE_ID = 'extreme-differentiator-043-industrial-048-security';
    public const TASK_TYPE = 'security';
    public const DIFFICULTY_LEVEL = 'L3';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Corrigir risco de seguranca 048 com fail-closed e evidencia de nao regressao.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}