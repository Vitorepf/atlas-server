<?php

declare(strict_types=1);

final class ExtremeDifferentiator052Industrial057IncidentRollbackFixture
{
    public const CASE_ID = 'extreme-differentiator-052-industrial-057-incident_rollback';
    public const TASK_TYPE = 'incident_rollback';
    public const DIFFICULTY_LEVEL = 'L2';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Responder incidente 057 com mitigacao, rollback seguro e postmortem tecnico.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}