<?php

declare(strict_types=1);

final class ExtremeDifferentiator047Industrial119IncompleteRequirementsFixture
{
    public const CASE_ID = 'extreme-differentiator-047-industrial-119-incomplete_requirements';
    public const TASK_TYPE = 'incomplete_requirements';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Transformar requisito incompleto 119 em plano executavel com assumptions auditaveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}