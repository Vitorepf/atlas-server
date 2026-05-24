<?php

declare(strict_types=1);

final class Ceiling360001Industrial005IncidentRollbackFixture
{
    public const CASE_ID = 'ceiling-360-001-industrial-005-incident_rollback';
    public const TASK_TYPE = 'incident_rollback';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Responder incidente 005 com mitigacao, rollback seguro e postmortem tecnico.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}