<?php

declare(strict_types=1);

final class Ceiling360087Industrial035SecurityV3Fixture
{
    public const CASE_ID = 'ceiling-360-087-industrial-035-security-v3';
    public const TASK_TYPE = 'security';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Corrigir risco de seguranca 035 com fail-closed e evidencia de nao regressao.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}