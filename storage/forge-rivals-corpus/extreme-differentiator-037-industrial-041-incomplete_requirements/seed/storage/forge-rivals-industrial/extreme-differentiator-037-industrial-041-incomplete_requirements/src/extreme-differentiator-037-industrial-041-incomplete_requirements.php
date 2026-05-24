<?php

declare(strict_types=1);

final class ExtremeDifferentiator037Industrial041IncompleteRequirementsFixture
{
    public const CASE_ID = 'extreme-differentiator-037-industrial-041-incomplete_requirements';
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