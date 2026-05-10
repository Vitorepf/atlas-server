<?php

namespace Tests\Feature\Ai;

use Tests\TestCase;

class AtlasAiRuntimeBoundaryApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_api_exposes_runtime_language_boundary_report(): void
    {
        $response = $this->getJson('/ai/runtime-boundary', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.runtime_language_boundary_report.v1')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('boundary.valid', true)
            ->assertJsonPath('boundary.violation_count', 0)
            ->assertJsonPath('surfaces.api', '/ai/runtime-boundary')
            ->assertJsonPath('surfaces.mcp', 'atlas_runtime_boundary')
            ->assertJsonPath('writes', false);

        $this->assertContains('app/Services/Semantic', $response->json('protected_scopes'));
        $this->assertContains('docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md', $response->json('owner_docs'));
        $this->assertContains('FAISS/Chroma/LlamaIndex/LangGraph/NetworkX in Laravel app', $response->json('forbidden_without_runtime_ap'));
        $this->assertSame('atlas.runtime_boundary_preflight_gate.v1', $response->json('preflight_gate.schema_version'));
        $this->assertContains('run_runtime_boundary_scan', $response->json('preflight_gate.required_before_runtime_work'));
        $this->assertContains('call_python_go_or_swift_directly_from_surface', $response->json('preflight_gate.forbidden_preflight_shortcuts'));
        $this->assertContains('skip_decision_receipt_for_runtime', $response->json('preflight_gate.forbidden_preflight_shortcuts'));
        $this->assertSame('atlas.runtime_promotion_policy.v1', $response->json('runtime_promotion_policy.schema_version'));
        $this->assertFalse($response->json('runtime_promotion_policy.auto_promotion_allowed'));
        $this->assertTrue($response->json('runtime_promotion_policy.human_review_required'));
        $this->assertSame('atlas.runtime_invocation_contract.v1', $response->json('runtime_invocation_contract.schema_version'));
        $this->assertContains('decision_receipt_hash', $response->json('runtime_invocation_contract.required_fields'));
        $this->assertContains('bypass_evidence_ledger', $response->json('runtime_invocation_contract.forbidden_runtime_authority'));
        $this->assertSame('runtimes/python', $response->json('runtime_owner_map.python_ai_data.allowed_write_scope'));
        $this->assertContains('graph', $response->json('runtime_owner_map.python_ai_data.allowed_for'));
    }

    public function test_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/runtime-boundary')
            ->assertUnauthorized();
    }
}
