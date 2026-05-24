<?php

declare(strict_types=1);

final class ExtremeDifferentiator040Industrial100SecurityFixture
{
    public const CASE_ID = 'extreme-differentiator-040-industrial-100-security';
    public const TASK_TYPE = 'security';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Corrigir risco de seguranca 100 com fail-closed e evidencia de nao regressao.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}