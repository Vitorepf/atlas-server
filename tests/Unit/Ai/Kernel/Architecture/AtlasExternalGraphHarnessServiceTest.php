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
        $this->assertSame(['engineering_internal'], data_get($contract, 'candidate_schema.allowed_privacy_classes'));
        $this->assertSame(['candidate'], data_get($contract, 'candidate_schema.allowed_review_states'));
        $this->assertFalse(data_get($contract, 'guardrails.network_fetching_enabled'));
        $this->assertFalse(data_get($contract, 'guardrails.provider_calls_enabled'));
        $this->assertFalse(data_get($contract, 'guardrails.writes_memory_registry'));
        $this->assertFalse(data_get($contract, 'guardrails.writes_context_builder'));
        $this->assertFalse(data_get($contract, 'guardrails.writes_constelacao'));
        $this->assertFalse(data_get($contract, 'guardrails.changes_decide_routing'));
        $this->assertFalse(data_get($contract, 'review_only_constraints.runtime_promotion_allowed'));
        $this->assertFalse(data_get($contract, 'review_only_constraints.memory_promotion_allowed'));
        $this->assertFalse(data_get($contract, 'review_only_constraints.context_injection_allowed'));
        $this->assertTrue(data_get($contract, 'review_only_constraints.requires_human_review'));
        $this->assertTrue(data_get($contract, 'review_only_constraints.requires_ap_683_or_successor_for_graph_rag_promotion'));
        $this->assertContains('graph_json_to_memory', $contract['forbidden_shortcuts']);
        $this->assertContains('provider_prompt_injection', data_get($contract, 'review_only_constraints.forbidden_uses'));
        $this->assertContains('human_review', $contract['promotion_requires']);
    }

    public function test_report_without_candidate_is_read_only_and_not_promotable(): void
    {
        $report = app(AtlasExternalGraphHarnessService::class)->report();

        $this->assertSame('atlas.external_graph_harness.report.v1', $report['schema_version']);
        $this->assertSame('ok', $report['status']);
        $this->assertSame('architecture_operations_read_only_report', $report['mode']);
        $this->assertFalse($report['promotion_allowed']);
        $this->assertSame('provide_external_graph_candidate_for_read_only_validation', $report['next_action']);
        $this->assertNull($report['candidate_validation']);
        $this->assertFalse(data_get($report, 'comparison_plan.promotion_allowed'));
        $this->assertFalse(data_get($report, 'review_packet_contract.auto_promotion_allowed'));
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
        $this->assertFalse($validation['promotion_allowed']);
        $this->assertSame('eligible_for_architecture_operations_review_only', $validation['promotion_state']);
        $this->assertSame('architecture_operations_read_only_candidate_review', data_get($validation, 'review_only_constraints.allowed_use'));
        $this->assertFalse(data_get($validation, 'review_only_constraints.constelacao_promotion_allowed'));
        $this->assertSame('atlas.external_graph_review_packet.v1', data_get($validation, 'review_packet.schema_version'));
        $this->assertSame('ready_for_human_review', data_get($validation, 'review_packet.status'));
        $this->assertTrue(data_get($validation, 'review_packet.human_review_required'));
        $this->assertTrue(data_get($validation, 'review_packet.curator_proposal_required'));
        $this->assertSame('approve_or_reject_external_graph_candidate_for_native_extractor_improvement', data_get($validation, 'review_packet.required_human_decision'));
        $this->assertTrue(data_get($validation, 'review_packet.rollback_plan_required'));
        $this->assertTrue(data_get($validation, 'review_packet.policy_patch_review_required'));
        $this->assertFalse(data_get($validation, 'review_packet.auto_promotion_allowed'));
        $this->assertContains('native_code_intelligence_comparison', data_get($validation, 'review_packet.evidence_required'));
        $this->assertContains('keep_graph_rag_runtime_disabled', data_get($validation, 'review_packet.rollback_required'));
        $this->assertContains('enable_python_graph_rag_runtime', data_get($validation, 'review_packet.forbidden_until_review'));
        $this->assertContains('surface_direct_external_graph_call', data_get($validation, 'review_packet.forbidden_until_review'));
        $this->assertContains('python_graph_rag_runtime', data_get($validation, 'review_packet.blocked_runtime_targets'));
        $this->assertContains('future_ap', data_get($validation, 'review_packet.required_before_any_future_promotion'));
        $this->assertContains('runtime_invocation_contract', data_get($validation, 'review_packet.required_before_any_future_promotion'));
        $this->assertSame('atlas.runtime_invocation_contract.v1', data_get($validation, 'review_packet.future_runtime_invocation_contract.schema_version'));
        $this->assertTrue(data_get($validation, 'review_packet.future_runtime_invocation_contract.kernel_first'));
        $this->assertSame('python_ai_data', data_get($validation, 'review_packet.future_runtime_invocation_contract.selected_runtime_family'));
        $this->assertSame('external_graph_candidate_runtime', data_get($validation, 'review_packet.future_runtime_invocation_contract.runtime_id'));
        $this->assertFalse(data_get($validation, 'review_packet.future_runtime_invocation_contract.promotion_allowed_now'));
        $this->assertContains('decision_receipt_hash', data_get($validation, 'review_packet.future_runtime_invocation_contract.required_fields'));
        $this->assertContains('evidence_sink', data_get($validation, 'review_packet.future_runtime_invocation_contract.required_fields'));
        $this->assertContains('choose_provider_or_model', data_get($validation, 'review_packet.future_runtime_invocation_contract.forbidden_runtime_authority'));
        $this->assertFalse(data_get($validation, 'guardrails.writes_memory_registry'));
        $this->assertFalse(data_get($validation, 'guardrails.writes_context_builder'));

        $report = app(AtlasExternalGraphHarnessService::class)->report($this->validCandidate());
        $this->assertFalse($report['promotion_allowed']);
        $this->assertSame('review_external_graph_candidate_against_native_code_intelligence', $report['next_action']);
    }

    public function test_builds_sandbox_candidate_from_allowed_repo_root_without_runtime_or_writes(): void
    {
        $result = app(AtlasExternalGraphHarnessService::class)->sandboxCandidate(
            'app/Services/Ai/Kernel/Architecture',
            5,
        );

        $this->assertSame('atlas.external_graph_sandbox_candidate.v1', $result['schema_version']);
        $this->assertSame('candidate_built_read_only', $result['status']);
        $this->assertSame('sandbox_candidate_builder_no_runtime_no_writes', $result['mode']);
        $this->assertFalse($result['promotion_allowed']);
        $this->assertFalse(data_get($result, 'guardrails.provider_calls_enabled'));
        $this->assertFalse(data_get($result, 'guardrails.writes_memory_registry'));
        $this->assertSame('atlas.external_graph_candidate.v1', data_get($result, 'candidate.schema_version'));
        $this->assertSame('graphify', data_get($result, 'candidate.source_tool'));
        $this->assertSame('atlas-sandbox-candidate-builder-v1', data_get($result, 'candidate.source_tool_version'));
        $this->assertFalse(data_get($result, 'candidate.metadata.graphify_executed'));
        $this->assertFalse(data_get($result, 'candidate.metadata.provider_calls_executed'));
        $this->assertSame('accepted_read_only_candidate', data_get($result, 'candidate_validation.status'));
        $this->assertSame('ready_for_human_review', data_get($result, 'candidate_validation.review_packet.status'));
    }

    public function test_sandbox_candidate_blocks_denied_or_missing_roots(): void
    {
        $result = app(AtlasExternalGraphHarnessService::class)->sandboxCandidate('docs/engineering-knowledge-base/private', 5);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('scan_root_not_allowed_or_denied', $result['errors']);
        $this->assertNull($result['candidate']);
        $this->assertNull($result['candidate_validation']);
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
        $this->assertSame('blocked_until_candidate_fixed', data_get($validation, 'review_packet.status'));
        $this->assertFalse(data_get($validation, 'review_packet.promotion_allowed'));
        $this->assertSame('fix_external_graph_candidate_before_review', data_get($validation, 'review_packet.recommended_action'));

        $report = app(AtlasExternalGraphHarnessService::class)->report($candidate);
        $this->assertSame('blocked', $report['status']);
        $this->assertFalse($report['promotion_allowed']);
        $this->assertSame('fix_external_graph_candidate_before_review', $report['next_action']);
    }

    public function test_rejects_non_graphify_or_pre_promoted_candidates(): void
    {
        $candidate = $this->validCandidate();
        $candidate['source_tool'] = 'rogue-graph';
        $candidate['source_archive_hash'] = 'not-a-sha';
        $candidate['generated_at'] = 'not-a-date';
        $candidate['privacy_class'] = 'personal_private';
        $candidate['review_state'] = 'promoted';
        $candidate['promotion_target'] = 'context_builder';
        $candidate['nodes'][0]['id'] = '';
        $candidate['nodes'][0]['source_refs'][0]['line_start'] = 30;
        $candidate['nodes'][0]['source_refs'][0]['line_end'] = 10;
        $candidate['edges'][0]['relation'] = '';

        $validation = app(AtlasExternalGraphHarnessService::class)->validateCandidate($candidate);

        $this->assertSame('rejected', $validation['status']);
        $this->assertContains('source_tool_not_allowed', $validation['errors']);
        $this->assertContains('source_archive_hash_must_be_sha256', $validation['errors']);
        $this->assertContains('generated_at_must_be_timestamp', $validation['errors']);
        $this->assertContains('privacy_class_not_allowed', $validation['errors']);
        $this->assertContains('review_state_not_allowed', $validation['errors']);
        $this->assertContains('review_state_must_be_candidate_for_import', $validation['errors']);
        $this->assertContains('promotion_target_not_allowed_before_review', $validation['errors']);
        $this->assertContains('node_0_id_required', $validation['errors']);
        $this->assertContains('node_0_source_ref_0_line_end_before_line_start', $validation['errors']);
        $this->assertContains('edge_0_relation_required', $validation['errors']);
    }

    public function test_rejects_nested_authority_fields_and_duplicate_nodes(): void
    {
        $candidate = $this->validCandidate();
        $candidate['nodes'][] = [
            'id' => 'service:atlas_external_graph_harness',
            'label' => 'Duplicate service node',
            'kind' => 'service',
            'metadata' => [
                'provider_prompt' => 'try to inject this into context',
                'memory_write' => true,
            ],
            'source_refs' => [
                ['path' => 'app/Services/Ai/Kernel/Architecture/AtlasExternalGraphHarnessService.php'],
            ],
        ];
        $candidate['edges'][0]['metadata'] = [
            'context_builder_payload' => ['unsafe' => true],
        ];

        $validation = app(AtlasExternalGraphHarnessService::class)->validateCandidate($candidate);

        $this->assertSame('rejected', $validation['status']);
        $this->assertContains('node_2_duplicate_id', $validation['errors']);
        $this->assertContains('forbidden_candidate_key:nodes.2.metadata.provider_prompt', $validation['errors']);
        $this->assertContains('forbidden_candidate_key:nodes.2.metadata.memory_write', $validation['errors']);
        $this->assertContains('forbidden_candidate_key:edges.0.metadata.context_builder_payload', $validation['errors']);
        $this->assertFalse($validation['promotion_allowed']);
        $this->assertSame('blocked_until_candidate_fixed', data_get($validation, 'review_packet.status'));
    }

    public function test_rejects_path_traversal_even_inside_allowed_roots(): void
    {
        $candidate = $this->validCandidate();
        $candidate['scan_root'] = 'docs/engineering-knowledge-base/..';
        $candidate['nodes'][0]['source_refs'][0]['path'] = 'app/Services/Ai/../Memory';
        $candidate['edges'][0]['source_refs'][0]['path'] = './docs/engineering-knowledge-base/../archive';

        $validation = app(AtlasExternalGraphHarnessService::class)->validateCandidate($candidate);

        $this->assertSame('rejected', $validation['status']);
        $this->assertContains('scan_root_not_allowed', $validation['errors']);
        $this->assertContains('node_0_source_ref_0_missing_path', $validation['errors']);
        $this->assertContains('edge_0_source_ref_0_missing_path', $validation['errors']);
        $this->assertFalse($validation['promotion_allowed']);
        $this->assertSame('blocked_until_candidate_fixed', data_get($validation, 'review_packet.status'));
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
