<?php

declare(strict_types=1);

final class Ceiling360097Industrial085DocumentationV3Fixture
{
    public const CASE_ID = 'ceiling-360-097-industrial-085-documentation-v3';
    public const TASK_TYPE = 'documentation';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Atualizar documentacao operacional 085 mantendo exemplos, riscos e runbook testaveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}