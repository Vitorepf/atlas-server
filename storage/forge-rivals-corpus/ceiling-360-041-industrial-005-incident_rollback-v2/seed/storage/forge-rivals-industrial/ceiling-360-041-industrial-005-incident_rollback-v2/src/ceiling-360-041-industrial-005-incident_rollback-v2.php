<?php

declare(strict_types=1);

final class Ceiling360041Industrial005IncidentRollbackV2Fixture
{
    public const CASE_ID = 'ceiling-360-041-industrial-005-incident_rollback-v2';
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