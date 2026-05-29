<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsExternalEvidenceLifecycleCertification;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasForgeRivalsExternalEvidenceLifecycleCertificationTest extends TestCase
{
    public function test_cert_declares_external_evidence_lifecycle_invariants(): void
    {
        $payload = (new AtlasForgeRivalsExternalEvidenceLifecycleCertification)->evaluate();

        $this->assertSame('atlas.forge_rivals_external_evidence_lifecycle_certification.v1', $payload['schema_version']);
        $this->assertSame('atlas_forge_rivals_external_evidence_lifecycle_certification', $payload['certification_key']);
        $this->assertSame('available', $payload['status']);
        $this->assertTrue($payload['ok']);
        $this->assertCount(19, AtlasForgeRivalsExternalEvidenceLifecycleCertification::REQUIRED_INVARIANTS);
        $this->assertCount(19, $payload['invariants']);
        $this->assertTrue($payload['invariants']['deepswe_ingest_exposes_external_lifecycle']['ok']);
        $this->assertTrue($payload['invariants']['deepswe_readiness_external_runbook_available']['ok']);
        $this->assertTrue($payload['invariants']['deepswe_batch_external_claim_gate_fail_closed']['ok']);
        $this->assertTrue($payload['invariants']['statistical_repeat_operator_runbook_available']['ok']);
        $this->assertTrue($payload['invariants']['external_execution_preflight_available']['ok']);
        $this->assertTrue($payload['invariants']['external_learning_gap_available']['ok']);
        $this->assertTrue($payload['invariants']['external_execution_plan_binding_batch_available']['ok']);
        $this->assertTrue($payload['invariants']['external_runbook_manifest_validation_available']['ok']);
        $this->assertTrue($payload['invariants']['decide_learning_operational_plan_available']['ok']);
        $this->assertTrue($payload['invariants']['decide_learning_blocks_unresolved_provider_model_candidates']['ok']);
        $this->assertTrue($payload['invariants_all_true']);
        $this->assertSame([], $payload['blockers']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertFalse($payload['score_or_claim_allowed']);
        $this->assertFalse($payload['claim_ready']);
        $this->assertFalse($payload['external_claim_allowed']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertFalse($payload['should_update_provider_topology']);
        $this->assertTrue($payload['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $payload['owner_of_model_routing']);
        $this->assertSame('none', $payload['routing_effect']);
        $this->assertSame('external_rivals_certification', $payload['separated_from']);
        $this->assertSame('Rivals emits measured evidence; Atlas Decide decides model routing.', $payload['canonical_phrase']);
    }

    public function test_external_evidence_readiness_action_returns_canonical_envelope(): void
    {
        Artisan::call('atlas:forge:rivals', [
            'action' => 'external-evidence-readiness',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('external-evidence-readiness', $payload['action']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.forge_rivals_external_evidence_lifecycle_certification.v1', $payload['schema_version']);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['invariants_all_true']);
        $this->assertTrue($payload['invariants']['deepswe_batch_external_claim_gate_fail_closed']['ok']);
        $this->assertTrue($payload['invariants']['statistical_repeat_operator_runbook_available']['ok']);
        $this->assertTrue($payload['invariants']['external_execution_preflight_available']['ok']);
        $this->assertTrue($payload['invariants']['external_learning_gap_available']['ok']);
        $this->assertTrue($payload['invariants']['external_execution_plan_binding_batch_available']['ok']);
        $this->assertTrue($payload['invariants']['external_runbook_manifest_validation_available']['ok']);
        $this->assertTrue($payload['invariants']['decide_learning_operational_plan_available']['ok']);
        $this->assertTrue($payload['invariants']['decide_learning_blocks_unresolved_provider_model_candidates']['ok']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertFalse($payload['should_update_provider_topology']);
        $this->assertTrue($payload['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $payload['owner_of_model_routing']);
        $this->assertSame('none', $payload['routing_effect']);
    }

    public function test_audit_exposes_external_evidence_lifecycle_certification(): void
    {
        Artisan::call('atlas:forge:rivals', [
            'action' => 'audit',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('external_evidence_lifecycle_certification', $payload);
        $this->assertSame(
            'atlas.forge_rivals_external_evidence_lifecycle_certification.v1',
            $payload['external_evidence_lifecycle_certification']['schema_version']
        );
        $this->assertArrayHasKey('certifications', $payload);
        $this->assertArrayHasKey(
            'atlas_forge_rivals_external_evidence_lifecycle_certification',
            $payload['certifications']
        );
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertFalse($payload['should_update_provider_topology']);
        $this->assertTrue($payload['never_changes_atlas_decide_topology']);
    }
}
