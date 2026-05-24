<?php

declare(strict_types=1);

final class Ceiling360069Industrial145IncompleteRequirementsV2Fixture
{
    public const CASE_ID = 'ceiling-360-069-industrial-145-incomplete_requirements-v2';
    public const TASK_TYPE = 'incomplete_requirements';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Transformar requisito incompleto 145 em plano executavel com assumptions auditaveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}