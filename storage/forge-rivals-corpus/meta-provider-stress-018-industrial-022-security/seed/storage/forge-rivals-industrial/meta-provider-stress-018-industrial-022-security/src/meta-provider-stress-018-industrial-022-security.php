<?php

declare(strict_types=1);

final class MetaProviderStress018Industrial022SecurityFixture
{
    public const CASE_ID = 'meta-provider-stress-018-industrial-022-security';
    public const TASK_TYPE = 'security';
    public const DIFFICULTY_LEVEL = 'L2';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Corrigir risco de seguranca 022 com fail-closed e evidencia de nao regressao.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}