<?php

declare(strict_types=1);

final class ExtremeDifferentiator064Industrial070IncidentRollbackFixture
{
    public const CASE_ID = 'extreme-differentiator-064-industrial-070-incident_rollback';
    public const TASK_TYPE = 'incident_rollback';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Responder incidente 070 com mitigacao, rollback seguro e postmortem tecnico.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}