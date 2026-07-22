<?php

namespace Tests\Feature\Ai;

use Tests\TestCase;

class AtlasAiSelfConstructionPostStartEvidenceBridgeContractTest extends TestCase
{
    public function test_post_start_evidence_producers_do_not_depend_on_their_downstream_bridge(): void
    {
        $producerFiles = [
            app_path('Services/Ai/SelfConstruction/Support/AgentCodexRealInvokerPostStartReceiptContractBuilder.php'),
            app_path('Services/Ai/SelfConstruction/Support/AgentCodexRealInvokerPostStartEvidenceReceiptWriter.php'),
        ];

        foreach ($producerFiles as $file) {
            $this->assertFileExists($file);
            $this->assertStringNotContainsString('post_start_evidence_acceptance_bridge_id', (string) file_get_contents($file));
        }

        $bridge = app_path('Services/Ai/SelfConstruction/Support/AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge.php');

        $this->assertFileExists($bridge);
        $this->assertStringContainsString('post_start_evidence_acceptance_bridge_id', (string) file_get_contents($bridge));
    }
}
