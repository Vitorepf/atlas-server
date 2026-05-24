<?php

declare(strict_types=1);

final class MetaProviderStress046Industrial054IncompleteRequirementsFixture
{
    public const CASE_ID = 'meta-provider-stress-046-industrial-054-incomplete_requirements';
    public const TASK_TYPE = 'incomplete_requirements';
    public const DIFFICULTY_LEVEL = 'L4';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Transformar requisito incompleto 054 em plano executavel com assumptions auditaveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}