<?php

declare(strict_types=1);

final class ExtremeDifferentiator026Industrial028IncompleteRequirementsFixture
{
    public const CASE_ID = 'extreme-differentiator-026-industrial-028-incomplete_requirements';
    public const TASK_TYPE = 'incomplete_requirements';
    public const DIFFICULTY_LEVEL = 'L3';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Transformar requisito incompleto 028 em plano executavel com assumptions auditaveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}