<?php

declare(strict_types=1);

final class ExtremeDifferentiator002Industrial002IncompleteRequirementsFixture
{
    public const CASE_ID = 'extreme-differentiator-002-industrial-002-incomplete_requirements';
    public const TASK_TYPE = 'incomplete_requirements';
    public const DIFFICULTY_LEVEL = 'L2';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Transformar requisito incompleto 002 em plano executavel com assumptions auditaveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}