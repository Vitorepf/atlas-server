<?php

declare(strict_types=1);

final class Industrial020DocumentationFixture
{
    public const CASE_ID = 'industrial-020-documentation';
    public const TASK_TYPE = 'documentation';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Atualizar documentacao operacional 020 mantendo exemplos, riscos e runbook testaveis.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}