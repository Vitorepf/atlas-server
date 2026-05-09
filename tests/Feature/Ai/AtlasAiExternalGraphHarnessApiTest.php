<?php

namespace Tests\Feature\Ai;

use Tests\TestCase;

class AtlasAiExternalGraphHarnessApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_api_exposes_read_only_external_graph_contract(): void
    {
        $this->getJson('/ai/external-graph-harness', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.external_graph_harness.report.v1')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('contract.status', 'implemented_read_only_contract')
            ->assertJsonPath('contract.guardrails.provider_calls_enabled', false)
            ->assertJsonPath('contract.guardrails.writes_memory_registry', false)
            ->assertJsonPath('contract.guardrails.writes_context_builder', false)
            ->assertJsonPath('contract.guardrails.writes_constelacao', false);
    }

    public function test_api_validates_candidate_preview_without_operational_writes(): void
    {
        $candidate = [
            'schema_version' => 'atlas.external_graph_candidate.v1',
            'source_tool' => 'graphify',
            'source_tool_version' => '0.7.11',
            'source_archive_hash' => str_repeat('c', 64),
            'scan_root' => 'tests/Feature',
            'generated_at' => '2026-05-09T12:00:00Z',
            'privacy_class' => 'engineering_internal',
            'review_state' => 'candidate',
            'nodes' => [
                [
                    'id' => 'test:external_graph_harness',
                    'label' => 'AtlasAiExternalGraphHarnessApiTest',
                    'kind' => 'test',
                    'source_refs' => [
                        ['path' => 'tests/Feature/Ai/AtlasAiExternalGraphHarnessApiTest.php'],
                    ],
                ],
            ],
            'edges' => [],
        ];

        $this->postJson('/ai/external-graph-harness', ['candidate' => $candidate], $this->headers)
            ->assertOk()
            ->assertJsonPath('candidate_validation.status', 'accepted_read_only_candidate')
            ->assertJsonPath('candidate_validation.mode', 'validation_only_no_writes')
            ->assertJsonPath('candidate_validation.node_count', 1)
            ->assertJsonPath('candidate_validation.guardrails.changes_decide_routing', false);
    }

    public function test_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/external-graph-harness')
            ->assertUnauthorized();
    }
}
