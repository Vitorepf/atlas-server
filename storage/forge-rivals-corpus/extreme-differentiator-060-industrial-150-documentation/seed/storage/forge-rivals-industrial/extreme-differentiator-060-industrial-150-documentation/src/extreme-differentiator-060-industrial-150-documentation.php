<?php

declare(strict_types=1);

final class ExtremeDifferentiator060Industrial150DocumentationFixture
{
    public const CASE_ID = 'extreme-differentiator-060-industrial-150-documentation';
    public const TASK_TYPE = 'documentation';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Atualizar documentacao operacional 150 mantendo exemplos, riscos e runbook testaveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}