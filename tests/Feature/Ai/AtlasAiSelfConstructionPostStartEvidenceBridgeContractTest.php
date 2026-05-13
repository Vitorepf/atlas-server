<?php

namespace Tests\Feature\Ai;

use Tests\TestCase;

class AtlasAiSelfConstructionPostStartEvidenceBridgeContractTest extends TestCase
{
    public function test_every_codex_real_invoker_post_start_service_carries_evidence_acceptance_bridge_id(): void
    {
        $files = glob(app_path('Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStart*.php')) ?: [];

        sort($files);

        $this->assertNotEmpty($files, 'Expected Codex real invoker post-start services to exist.');

        $missing = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            if ($contents === false || ! str_contains($contents, 'post_start_evidence_acceptance_bridge_id')) {
                $missing[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Every post-start service must carry post_start_evidence_acceptance_bridge_id so raw liveness cannot bypass accepted evidence.'
        );
    }
}
