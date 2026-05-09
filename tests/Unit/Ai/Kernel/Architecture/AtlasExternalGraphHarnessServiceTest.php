<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasExternalGraphHarnessService;
use Tests\TestCase;

class AtlasExternalGraphHarnessServiceTest extends TestCase
{
    public function test_contract_is_fail_closed_and_read_only(): void
    {
        $contract = app(AtlasExternalGraphHarnessService::class)->contract();

        $this->assertSame('atlas.external_graph_harness.contract.v1', $contract['schema_version']);
        $this->assertSame('implemented_read_only_contract', $contract['status']);
        $this->assertSame('candidate_validation_no_runtime_no_writes', $contract['mode']);
        $this->assertSame('code_intelligence_candidate_only', $contract['authority']);
        $this->assertFalse(data_get($contract, 'guardrails.network_fetching_enabled'));
        $this->assertFalse(data_get($contract, 'guardrails.provider_calls_enabled'));
        $this->assertFalse(data_get($contract, 'guardrails.writes_memory_registry'));
        $this->assertFalse(data_get($contract, 'guardrails.writes_context_builder'));
        $this->assertFalse(data_get($contract, 'guardrails.writes_constelacao'));
        $this->assertFalse(data_get($contract, 'guardrails.changes_decide_routing'));
        $this->assertContains('graph_json_to_memory', $contract['forbidden_shortcuts']);
        $this->assertContains('human_review', $contract['promotion_requires']);
    }

    public function test_accepts_valid_candidate_as_read_only(): void
    {
        $validation = app(AtlasExternalGraphHarnessService::class)->validateCandidate($this->validCandidate());

        $this->assertSame('atlas.external_graph_candidate.validation.v1', $validation['schema_version']);
        $this->assertSame('accepted_read_only_candidate', $validation['status']);
        $this->assertSame('validation_only_no_writes', $validation['mode']);
        $this->assertSame(2, $validation['node_count']);
        $this->assertSame(1, $validation['edge_count']);
        $this->assertSame(0, $validation['error_count']);
        $this->assertSame('eligible_for_architecture_operations_review', $validation['promotion_state']);
        $this->assertFalse(data_get($validation, 'guardrails.writes_memory_registry'));
        $this->assertFalse(data_get($validation, 'guardrails.writes_context_builder'));
    }

    public function test_rejects_private_or_unreferenced_candidate(): void
    {
        $candidate = $this->validCandidate();
        $candidate['scan_root'] = 'docs/engineering-knowledge-base/private';
        $candidate['nodes'][0]['source_refs'] = [];
        $candidate['edges'][0]['confidence'] = 'CERTAIN';

        $validation = app(AtlasExternalGraphHarnessService::class)->validateCandidate($candidate);

        $this->assertSame('rejected', $validation['status']);
        $this->assertContains('scan_root_denied', $validation['errors']);
        $this->assertContains('node_0_source_refs_required', $validation['errors']);
        $this->assertContains('edge_0_invalid_confidence', $validation['errors']);
        $this->assertSame('blocked_until_candidate_fixed', $validation['promotion_state']);
    }

    /**
     * @return array<string,mixed>
     */
    private function validCandidate(): array
    {
        return [
            'schema_version' => 'atlas.external_graph_candidate.v1',
            'source_tool' => 'graphify',
            'source_tool_version' => '0.7.11',
            'source_archive_hash' => str_repeat('a', 64),
            'scan_root' => 'app/Services/Ai',
            'generated_at' => '2026-05-09T12:00:00Z',
            'privacy_class' => 'engineering_internal',
            'review_state' => 'candidate',
            'nodes' => [
                [
                    'id' => 'service:atlas_external_graph_harness',
                    'label' => 'AtlasExternalGraphHarnessService',
                    'kind' => 'service',
                    'source_refs' => [
                        ['path' => 'app/Services/Ai/Kernel/Architecture/AtlasExternalGraphHarnessService.php'],
                    ],
                ],
                [
                    'id' => 'doc:ap_684',
                    'label' => 'AP-684',
                    'kind' => 'doc',
                    'source_refs' => [
                        ['path' => 'docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md'],
                    ],
                ],
            ],
            'edges' => [
                [
                    'source' => 'service:atlas_external_graph_harness',
                    'target' => 'doc:ap_684',
                    'relation' => 'implements_contract',
                    'confidence' => 'EXTRACTED',
                    'source_refs' => [
                        ['path' => 'docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md'],
                    ],
                ],
            ],
        ];
    }
}
