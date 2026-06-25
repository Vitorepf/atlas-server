<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAtlasNativeEvidenceVerifier;
use Tests\TestCase;

class AtlasSelfConstructionAtlasNativeEvidenceVerifierTest extends TestCase
{
    public function test_fully_ready_facts_pass_with_no_blockers(): void
    {
        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($this->readyFacts());

        self::assertTrue($verdict['passed']);
        self::assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_READY, $verdict['status']);
        self::assertSame([], $verdict['blockers']);
        self::assertArrayNotHasKey('score', $verdict);
        foreach ($verdict['observed_sections'] as $section => $ok) {
            self::assertTrue($ok, "section {$section} must be ready in the all-green fixture");
        }
    }

    public function test_missing_proof_section_blocks_with_named_blocker(): void
    {
        $facts = $this->readyFacts();
        unset($facts['merge_governor_readiness']);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        self::assertFalse($verdict['passed']);
        self::assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_BLOCKED, $verdict['status']);
        self::assertContains('merge_governor_not_ready', $verdict['blockers']);
        self::assertFalse($verdict['observed_sections']['merge_governor_readiness']);
    }

    public function test_one_true_autonomy_dependency_blocks(): void
    {
        $facts = $this->readyFacts();
        $facts['autonomy_dependencies']['depends_on_claude_code'] = true;

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        self::assertFalse($verdict['passed']);
        self::assertContains('autonomy_dependency_true:depends_on_claude_code', $verdict['blockers']);
        self::assertFalse($verdict['observed_sections']['autonomy_dependencies_all_false']);
    }

    public function test_unhealthy_queue_blocks(): void
    {
        $facts = $this->readyFacts();
        $facts['serving_queue_health'] = false;

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        self::assertFalse($verdict['passed']);
        self::assertContains('serving_queue_unhealthy', $verdict['blockers']);
    }

    public function test_missing_multi_project_lane_blocks(): void
    {
        $facts = $this->readyFacts();
        $facts['multi_project_lane_readiness'] = false;

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        self::assertFalse($verdict['passed']);
        self::assertContains('multi_project_lane_not_ready', $verdict['blockers']);
    }

    public function test_wrong_final_runtime_owner_blocks(): void
    {
        $facts = $this->readyFacts();
        $facts['final_runtime_owner'] = 'external_assistant';

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        self::assertFalse($verdict['passed']);
        self::assertContains('final_runtime_owner_not_atlas_native:external_assistant', $verdict['blockers']);
    }

    public function test_steady_state_runtime_owner_accepts_atlas_server_and_atlas_native(): void
    {
        $verifier = new AtlasSelfConstructionAtlasNativeEvidenceVerifier();

        $a = $this->readyFacts();
        $a['steady_state_runtime_owner'] = 'atlas_server';
        self::assertTrue($verifier->verify($a)['passed']);

        $b = $this->readyFacts();
        $b['steady_state_runtime_owner'] = 'atlas_native';
        self::assertTrue($verifier->verify($b)['passed']);

        $c = $this->readyFacts();
        $c['steady_state_runtime_owner'] = 'operator';
        self::assertFalse($verifier->verify($c)['passed']);
    }

    /**
     * @return array<string,mixed>
     */
    private function readyFacts(): array
    {
        return [
            'final_runtime_owner' => 'atlas_native',
            'steady_state_runtime_owner' => 'atlas_server',
            'autonomy_dependencies' => [
                'depends_on_operator' => false,
                'depends_on_claude_code' => false,
                'depends_on_codex' => false,
                'depends_on_external_provider_network' => false,
            ],
            'serving_queue_health' => true,
            'native_worker_readiness' => true,
            'verification_court_readiness' => true,
            'merge_governor_readiness' => true,
            'rollback_readiness' => true,
            'learning_transfer_readiness' => true,
            'docs_health' => true,
            'kb_sync' => true,
            'code_index_readiness' => true,
            'multi_project_lane_readiness' => true,
        ];
    }
}
