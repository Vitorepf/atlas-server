<?php

declare(strict_types=1);

final class MetaProviderStress038Industrial044IncidentRollbackFixture
{
    public const CASE_ID = 'meta-provider-stress-038-industrial-044-incident_rollback';
    public const TASK_TYPE = 'incident_rollback';
    public const DIFFICULTY_LEVEL = 'L4';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Responder incidente 044 com mitigacao, rollback seguro e postmortem tecnico.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}