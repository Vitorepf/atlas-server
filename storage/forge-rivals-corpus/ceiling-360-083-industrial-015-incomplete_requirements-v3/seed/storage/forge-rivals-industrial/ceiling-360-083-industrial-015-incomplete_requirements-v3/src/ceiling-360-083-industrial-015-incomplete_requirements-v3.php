<?php

declare(strict_types=1);

final class Ceiling360083Industrial015IncompleteRequirementsV3Fixture
{
    public const CASE_ID = 'ceiling-360-083-industrial-015-incomplete_requirements-v3';
    public const TASK_TYPE = 'incomplete_requirements';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Transformar requisito incompleto 015 em plano executavel com assumptions auditaveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}