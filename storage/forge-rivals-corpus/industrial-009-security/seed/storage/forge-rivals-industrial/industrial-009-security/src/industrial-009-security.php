<?php

declare(strict_types=1);

final class Industrial009SecurityFixture
{
    public const CASE_ID = 'industrial-009-security';
    public const TASK_TYPE = 'security';
    public const DIFFICULTY_LEVEL = 'L4';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Corrigir risco de seguranca 009 com fail-closed e evidencia de nao regressao.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}