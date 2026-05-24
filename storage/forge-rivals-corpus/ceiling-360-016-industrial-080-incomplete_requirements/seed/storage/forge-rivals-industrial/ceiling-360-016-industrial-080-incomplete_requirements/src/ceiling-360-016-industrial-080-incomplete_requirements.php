<?php

declare(strict_types=1);

final class Ceiling360016Industrial080IncompleteRequirementsFixture
{
    public const CASE_ID = 'ceiling-360-016-industrial-080-incomplete_requirements';
    public const TASK_TYPE = 'incomplete_requirements';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Transformar requisito incompleto 080 em plano executavel com assumptions auditaveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}