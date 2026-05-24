<?php

declare(strict_types=1);

final class MetaProviderStress035Industrial041IncompleteRequirementsFixture
{
    public const CASE_ID = 'meta-provider-stress-035-industrial-041-incomplete_requirements';
    public const TASK_TYPE = 'incomplete_requirements';
    public const DIFFICULTY_LEVEL = 'L1';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Transformar requisito incompleto 041 em plano executavel com assumptions auditaveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}