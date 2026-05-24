<?php

declare(strict_types=1);

final class MetaProviderStress016Industrial018IncidentRollbackFixture
{
    public const CASE_ID = 'meta-provider-stress-016-industrial-018-incident_rollback';
    public const TASK_TYPE = 'incident_rollback';
    public const DIFFICULTY_LEVEL = 'L3';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Responder incidente 018 com mitigacao, rollback seguro e postmortem tecnico.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}