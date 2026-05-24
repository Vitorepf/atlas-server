<?php

declare(strict_types=1);

final class ExtremeDifferentiator058Industrial145IncompleteRequirementsFixture
{
    public const CASE_ID = 'extreme-differentiator-058-industrial-145-incomplete_requirements';
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