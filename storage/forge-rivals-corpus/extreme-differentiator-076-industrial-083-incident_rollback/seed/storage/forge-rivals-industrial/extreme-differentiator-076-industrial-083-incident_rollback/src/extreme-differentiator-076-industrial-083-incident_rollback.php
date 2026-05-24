<?php

declare(strict_types=1);

final class ExtremeDifferentiator076Industrial083IncidentRollbackFixture
{
    public const CASE_ID = 'extreme-differentiator-076-industrial-083-incident_rollback';
    public const TASK_TYPE = 'incident_rollback';
    public const DIFFICULTY_LEVEL = 'L3';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Responder incidente 083 com mitigacao, rollback seguro e postmortem tecnico.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}