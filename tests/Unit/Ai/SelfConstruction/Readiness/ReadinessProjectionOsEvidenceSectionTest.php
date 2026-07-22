<?php

namespace Tests\Unit\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionOsEvidenceSection;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

final class ReadinessProjectionOsEvidenceSectionTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_operator_evidence_aliases_fail_closed_when_the_owner_policy_is_missing(): void
    {
        $owner = Mockery::mock('overload:App\\Services\\Ai\\SelfConstruction\\NativeImplementation\\AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService');
        $owner
            ->shouldReceive('build')
            ->once()
            ->with(Mockery::type('array'))
            ->andReturn(['status' => 'blocked']);

        $status = (new ReadinessProjectionOsEvidenceSection)
            ->setMother(app(AtlasSelfConstructionReadinessService::class))
            ->atlasSelfConstructionOperatorEvidenceSubmissionReadinessStatus();
        $summary = (array) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status', []);

        $this->assertFalse((bool) data_get($summary, 'completion_claim_external_agent_claim_accepted', true));
        $this->assertFalse((bool) data_get($summary, 'completion_claim_external_agent_claim_can_mark_os_complete', true));
        $this->assertFalse((bool) data_get($summary, 'completion_claim_external_agent_claim_can_override_audit', true));
        $this->assertFalse((bool) data_get($summary, 'external_completion_claim_policy_external_agent_claim_accepted', true));
        $this->assertFalse((bool) data_get($summary, 'external_completion_claim_policy_external_agent_claim_can_mark_os_complete', true));
        $this->assertFalse((bool) data_get($summary, 'external_completion_claim_policy_external_agent_claim_can_override_audit', true));
        $this->assertFalse((bool) data_get($summary, 'external_completion_claim_policy_schema_valid', true));
        $this->assertSame(
            'missing_or_malformed_external_completion_claim_policy',
            data_get($summary, 'external_completion_claim_policy_schema_violation'),
        );
        $this->assertSame([
            'external_agent_claim_accepted',
            'external_agent_claim_can_mark_os_complete',
            'external_agent_claim_can_override_audit',
        ], data_get($summary, 'external_completion_claim_policy_schema_violation_fields'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_final_closure_aliases_fail_closed_when_the_owner_policy_is_malformed(): void
    {
        $owner = Mockery::mock('overload:App\\Services\\Ai\\SelfConstruction\\NativeImplementation\\AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService');
        $owner
            ->shouldReceive('build')
            ->once()
            ->with([])
            ->andReturn([
                'status' => 'blocked',
                'external_completion_claim_policy' => [
                    'external_agent_claim_accepted' => 'yes',
                    'external_agent_claim_can_mark_os_complete' => 1,
                ],
            ]);

        $status = (new ReadinessProjectionOsEvidenceSection)
            ->setMother(app(AtlasSelfConstructionReadinessService::class))
            ->atlasSelfConstructionFinalOperatorEvidenceClosureCorridorStatus();
        $summary = (array) data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status', []);

        $this->assertFalse((bool) data_get($summary, 'external_completion_claim_policy_external_agent_claim_accepted', true));
        $this->assertFalse((bool) data_get($summary, 'external_completion_claim_policy_external_agent_claim_can_mark_os_complete', true));
        $this->assertFalse((bool) data_get($summary, 'external_completion_claim_policy_external_agent_claim_can_override_audit', true));
        $this->assertFalse((bool) data_get($summary, 'external_completion_claim_policy_schema_valid', true));
        $this->assertSame(
            'missing_or_malformed_external_completion_claim_policy',
            data_get($summary, 'external_completion_claim_policy_schema_violation'),
        );
        $this->assertSame([
            'external_agent_claim_accepted',
            'external_agent_claim_can_mark_os_complete',
            'external_agent_claim_can_override_audit',
        ], data_get($summary, 'external_completion_claim_policy_schema_violation_fields'));
    }

}
