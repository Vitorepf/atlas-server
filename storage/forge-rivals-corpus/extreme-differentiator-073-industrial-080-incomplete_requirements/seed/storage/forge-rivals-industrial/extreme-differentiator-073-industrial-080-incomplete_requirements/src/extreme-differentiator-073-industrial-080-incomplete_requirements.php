<?php

declare(strict_types=1);

final class ExtremeDifferentiator073Industrial080IncompleteRequirementsFixture
{
    public const CASE_ID = 'extreme-differentiator-073-industrial-080-incomplete_requirements';
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