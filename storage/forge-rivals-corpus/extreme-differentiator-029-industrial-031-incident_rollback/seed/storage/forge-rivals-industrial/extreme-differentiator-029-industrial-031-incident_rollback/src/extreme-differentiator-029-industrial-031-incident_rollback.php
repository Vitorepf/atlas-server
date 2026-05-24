<?php

declare(strict_types=1);

final class ExtremeDifferentiator029Industrial031IncidentRollbackFixture
{
    public const CASE_ID = 'extreme-differentiator-029-industrial-031-incident_rollback';
    public const TASK_TYPE = 'incident_rollback';
    public const DIFFICULTY_LEVEL = 'L1';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Responder incidente 031 com mitigacao, rollback seguro e postmortem tecnico.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}