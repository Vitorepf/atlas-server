<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainImplementationProofDemand;
use Tests\TestCase;

final class AtlasExternalBrainImplementationProofDemandTest extends TestCase
{
    public function test_base_proof_type_derived_from_target_class_with_risk_escalation(): void
    {
        $svc = new AtlasExternalBrainImplementationProofDemand;

        $low = $svc->derive(['task' => ['target_class' => 'command', 'risk_level' => 'low']]);
        $this->assertSame([AtlasExternalBrainImplementationProofDemand::PROOF_COMMAND_SMOKE], $low['required_proofs']);

        $medium = $svc->derive(['task' => ['target_class' => 'command', 'risk_level' => 'medium']]);
        $this->assertContains(AtlasExternalBrainImplementationProofDemand::PROOF_COLLISION_SWEEP, $medium['required_proofs']);

        $high = $svc->derive(['task' => ['target_class' => 'command', 'risk_level' => 'high']]);
        $this->assertContains(AtlasExternalBrainImplementationProofDemand::PROOF_COLLISION_SWEEP, $high['required_proofs']);
        $this->assertContains(AtlasExternalBrainImplementationProofDemand::PROOF_RUNTIME_RECEIPT, $high['required_proofs']);
    }

    public function test_base_proof_type_derived_from_value_mechanism_fallback(): void
    {
        $svc = new AtlasExternalBrainImplementationProofDemand;

        $result = $svc->derive(['task' => ['value_mechanism' => 'queue', 'risk_level' => 'low']]);
        $this->assertSame([AtlasExternalBrainImplementationProofDemand::PROOF_QUEUE_HEALTH], $result['required_proofs']);
    }

    public function test_property_gated_tasks_always_require_runtime_receipt_and_notes_insufficient(): void
    {
        $svc = new AtlasExternalBrainImplementationProofDemand;

        $result = $svc->derive(['task' => ['is_property_gated' => true, 'risk_level' => 'low']]);

        $this->assertContains(AtlasExternalBrainImplementationProofDemand::PROOF_RUNTIME_RECEIPT, $result['required_proofs']);
        $this->assertFalse($result['implementation_notes_sufficient']);
    }

    public function test_accepted_real_proof_types_are_accepted(): void
    {
        $svc = new AtlasExternalBrainImplementationProofDemand;

        foreach (AtlasExternalBrainImplementationProofDemand::ACCEPTED_REAL_PROOF_TYPES as $proofType) {
            $result = $svc->verifySubmittedProof($proofType);
            $this->assertTrue($result['accepted'], "expected acceptance for: $proofType");
            $this->assertFalse($result['is_proxy']);
        }
    }

    public function test_proxy_proof_types_are_rejected(): void
    {
        $svc = new AtlasExternalBrainImplementationProofDemand;

        foreach (AtlasExternalBrainImplementationProofDemand::REJECTED_PROXY_PROOF_TYPES as $proofType) {
            $result = $svc->verifySubmittedProof($proofType);
            $this->assertFalse($result['accepted'], "expected rejection for: $proofType");
            $this->assertTrue($result['is_proxy']);
        }
    }
}
