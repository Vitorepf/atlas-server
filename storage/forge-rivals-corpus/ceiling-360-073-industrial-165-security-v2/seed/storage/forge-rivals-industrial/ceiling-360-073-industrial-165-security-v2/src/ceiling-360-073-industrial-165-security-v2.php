<?php

declare(strict_types=1);

final class Ceiling360073Industrial165SecurityV2Fixture
{
    public const CASE_ID = 'ceiling-360-073-industrial-165-security-v2';
    public const TASK_TYPE = 'security';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Corrigir risco de seguranca 165 com fail-closed e evidencia de nao regressao.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}