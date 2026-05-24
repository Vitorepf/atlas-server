<?php

declare(strict_types=1);

final class Ceiling360043Industrial015IncompleteRequirementsV2Fixture
{
    public const CASE_ID = 'ceiling-360-043-industrial-015-incomplete_requirements-v2';
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