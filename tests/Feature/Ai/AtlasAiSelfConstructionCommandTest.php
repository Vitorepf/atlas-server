<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAiSelfConstructionCommandTest extends TestCase
{
    public function test_command_returns_self_construction_readiness_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_readiness.v1', data_get($payload, 'schema_version'));
        $this->assertSame('read_only_advisory', data_get($payload, 'mode'));
        $this->assertSame('ready_for_phase_2', data_get($payload, 'status'));
        $this->assertSame('phase_2_read_only_gap_report', data_get($payload, 'summary.runtime_phase'));
        $this->assertFalse(data_get($payload, 'summary.autonomous_execution_allowed'));
        $this->assertFalse(data_get($payload, 'safety_contract.self_programming_allowed'));
        $this->assertFalse(data_get($payload, 'safety_contract.write_tools_allowed'));
        $this->assertSame(0, data_get($payload, 'summary.missing_doc_count'));
        $this->assertGreaterThanOrEqual(13, data_get($payload, 'summary.required_doc_count'));
        $this->assertContains('spec_operating_system', data_get($payload, 'build_graph.prerequisites'));
        $this->assertContains('Meta-SDD artifact generator', collect(data_get($payload, 'next_safe_blocks'))->pluck('block')->all());
        $this->assertContains('php artisan atlas:ai:self-construction --traceability --json', data_get($payload, 'recommended_commands'));
        $this->assertContains('git diff --check', data_get($payload, 'recommended_commands'));
    }

    public function test_command_human_output_lists_safe_blocks(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Self-programming allowed', $output);
        $this->assertStringContainsString('Next safe blocks', $output);
        $this->assertStringContainsString('Meta-SDD artifact generator', $output);
    }

    public function test_command_returns_meta_sdd_candidate_packet_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--meta-sdd' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_meta_sdd.v1', data_get($payload, 'schema_version'));
        $this->assertSame('candidate_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_candidate', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertSame('0.8-self-construction', data_get($payload, 'meta_spec.target_layer'));
        $this->assertSame('meta_sdd_artifact_generator', data_get($payload, 'meta_spec.target_capability'));
        $this->assertSame('L2_meta_sdd_artifact_generator', data_get($payload, 'meta_spec.target_maturity'));
        $this->assertContains('no code patch execution', data_get($payload, 'meta_spec.non_goals'));
        $this->assertSame('P0', data_get($payload, 'priority.p_level'));
        $this->assertGreaterThanOrEqual(3, count(data_get($payload, 'tasks')));
        $this->assertContains('php artisan atlas:ai:self-construction --traceability --json', data_get($payload, 'required_gates'));
        $this->assertContains('php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php', data_get($payload, 'required_gates'));
        $this->assertFalse(data_get($payload, 'safety_contract.self_programming_allowed'));
    }

    public function test_command_human_output_lists_meta_sdd_candidate(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--meta-sdd' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Execution allowed', $output);
        $this->assertStringContainsString('Target capability', $output);
        $this->assertStringContainsString('Candidate Meta-SDD packet', $output);
    }

    public function test_command_returns_receipt_preview_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--receipt-preview' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_receipt_preview.v1', data_get($payload, 'schema_version'));
        $this->assertSame('preview_ready', data_get($payload, 'status'));
        $this->assertSame('receipt_preview_only', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertSame('DR-PREVIEW-SELF-CONSTRUCTION-PHASE-4', data_get($payload, 'receipt_preview.id'));
        $this->assertSame('L0_preview_only', data_get($payload, 'receipt_preview.autonomy_level'));
        $this->assertContains('generate_receipt_preview', data_get($payload, 'receipt_preview.scope.allowed_actions'));
        $this->assertContains('enable_self_programming_writes', data_get($payload, 'receipt_preview.scope.forbidden_actions'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'receipt_preview.scope.forbidden_files'));
        $this->assertContains('php artisan atlas:ai:self-construction --traceability --json', data_get($payload, 'receipt_preview.gates.required'));
        $this->assertContains('php artisan migrate', data_get($payload, 'receipt_preview.scope.forbidden_commands'));
        $this->assertTrue(data_get($payload, 'receipt_preview.evidence.append_only'));
    }

    public function test_command_human_output_lists_receipt_preview(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--receipt-preview' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Receipt', $output);
        $this->assertStringContainsString('Execution allowed', $output);
        $this->assertStringContainsString('Receipt preview is ready', $output);
    }

    public function test_command_returns_traceability_audit_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--traceability' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_traceability_audit.v1', data_get($payload, 'schema_version'));
        $this->assertSame('traceable', data_get($payload, 'status'));
        $this->assertSame('read_only_traceability_audit', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertSame(0, data_get($payload, 'summary.violation_count'));
        $this->assertGreaterThanOrEqual(13, data_get($payload, 'summary.required_doc_count'));
        $this->assertContains('php artisan atlas:ai:self-construction --traceability --json', data_get($payload, 'required_gates'));
        $this->assertFalse(data_get($payload, 'safety_contract.self_programming_allowed'));

        $root = collect(data_get($payload, 'traceability_items'))->firstWhere('path', 'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md');

        $this->assertTrue(data_get($root, 'exists'));
        $this->assertTrue(data_get($root, 'declares_self_construction_tag'));
        $this->assertTrue(data_get($root, 'declares_layer'));
        $this->assertTrue(data_get($root, 'listed_by_root_doc'));
    }

    public function test_command_human_output_lists_traceability_audit(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--traceability' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Required docs', $output);
        $this->assertStringContainsString('Violations', $output);
        $this->assertStringContainsString('Traceability audit is read-only', $output);
    }

    public function test_command_returns_promotion_gate_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--promotion-gate' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_promotion_gate.v1', data_get($payload, 'schema_version'));
        $this->assertSame('promotion_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_promotion_gate', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertSame('phase_4_5_traceability_guardrail', data_get($payload, 'current_phase'));
        $this->assertSame('phase_5_low_risk_agent_execution_candidate', data_get($payload, 'recommended_next_phase'));
        $this->assertSame([], data_get($payload, 'blocking_failures'));
        $this->assertSame('L0_not_allowed', data_get($payload, 'maturity_delta.autonomous_self_programming'));
        $this->assertContains('docs_only', data_get($payload, 'promotion_conditions.allowed_first_execution_scope'));
        $this->assertContains('voice_realtime_runtime_change', data_get($payload, 'promotion_conditions.forbidden_first_execution_scope'));
        $this->assertContains('php artisan atlas:ai:self-construction --promotion-gate --json', data_get($payload, 'required_gates'));
        $this->assertTrue(data_get($payload, 'promotion_conditions.human_review_required'));
    }

    public function test_command_human_output_lists_promotion_gate(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--promotion-gate' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Current phase', $output);
        $this->assertStringContainsString('Recommended next phase', $output);
        $this->assertStringContainsString('Blocking failures', $output);
        $this->assertStringContainsString('ready for human-reviewed Phase 5 candidate planning', $output);
    }

    public function test_command_returns_execution_candidate_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--execution-candidate' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_execution_candidate.v1', data_get($payload, 'schema_version'));
        $this->assertSame('candidate_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_execution_candidate', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'candidate.execution_allowed'));
        $this->assertTrue(data_get($payload, 'candidate.approval_required'));
        $this->assertSame('L1_human_review_required', data_get($payload, 'candidate.autonomy_level'));
        $this->assertSame('phase_5_low_risk_agent_execution', data_get($payload, 'candidate.target_phase'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'candidate_hash'));
        $this->assertContains('docs_only', data_get($payload, 'candidate.candidate_scope.allowed_work_types'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'candidate.candidate_scope.forbidden_files'));
        $this->assertContains('voice_realtime_change', data_get($payload, 'candidate.candidate_scope.forbidden_work_types'));
        $this->assertContains('signed_decision_receipt', data_get($payload, 'candidate.required_evidence'));
        $this->assertContains('php artisan atlas:ai:self-construction --promotion-gate --json', data_get($payload, 'candidate.required_preflight_gates'));
    }

    public function test_command_human_output_lists_execution_candidate(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--execution-candidate' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Execution allowed', $output);
        $this->assertStringContainsString('Candidate hash', $output);
        $this->assertStringContainsString('Phase 5 execution candidate is ready for human review only', $output);
    }

    public function test_command_returns_approval_packet_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--approval-packet' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_approval_packet.v1', data_get($payload, 'schema_version'));
        $this->assertSame('approval_pending', data_get($payload, 'status'));
        $this->assertSame('read_only_approval_packet', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'approval.approved'));
        $this->assertFalse(data_get($payload, 'approval.execution_allowed'));
        $this->assertSame('pending_human_approval', data_get($payload, 'approval.status'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'approval_hash'));
        $this->assertContains('human_owner', data_get($payload, 'approval.review_required_by'));
        $this->assertContains('approval_packet_does_not_touch_voice_realtime', data_get($payload, 'approval.invariants'));
        $this->assertSame(
            data_get($payload, 'approval.candidate_hash'),
            data_get($payload, 'approval.required_human_decision.approve_candidate_hash')
        );
        $this->assertContains('voice_realtime_change', data_get($payload, 'approval.required_human_decision.confirm_forbidden_scope'));
        $this->assertGreaterThanOrEqual(5, count(data_get($payload, 'approval.approval_checklist')));
    }

    public function test_command_human_output_lists_approval_packet(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--approval-packet' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Approval', $output);
        $this->assertStringContainsString('Approved', $output);
        $this->assertStringContainsString('Approval hash', $output);
        $this->assertStringContainsString('Approval packet is ready for human review', $output);
    }

    public function test_command_returns_receipt_draft_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--receipt-draft' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_receipt_draft.v1', data_get($payload, 'schema_version'));
        $this->assertSame('draft_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_receipt_draft', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'receipt_draft.signed'));
        $this->assertFalse(data_get($payload, 'receipt_draft.execution_allowed'));
        $this->assertFalse(data_get($payload, 'receipt_draft.signature_valid_for_execution'));
        $this->assertNull(data_get($payload, 'receipt_draft.signed_by'));
        $this->assertSame('draft_pending_human_signature', data_get($payload, 'receipt_draft.status'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'receipt_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'preview_signature'));
        $this->assertContains('draft_signature_is_preview_only', data_get($payload, 'receipt_draft.non_execution_invariants'));
        $this->assertContains('docs_only', data_get($payload, 'receipt_draft.scope.allowed_work_types'));
        $this->assertContains('voice_realtime_change', data_get($payload, 'receipt_draft.scope.forbidden_work_types'));
        $this->assertContains('php artisan atlas:ai:self-construction --receipt-draft --json', data_get($payload, 'receipt_draft.gates.required'));
    }

    public function test_command_human_output_lists_receipt_draft(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--receipt-draft' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Receipt draft', $output);
        $this->assertStringContainsString('Signed', $output);
        $this->assertStringContainsString('Receipt hash', $output);
        $this->assertStringContainsString('Receipt draft is ready for human signature review', $output);
    }

    public function test_command_returns_execution_preflight_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--execution-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_execution_preflight.v1', data_get($payload, 'schema_version'));
        $this->assertSame('blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_execution_preflight', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertSame('human_signature_required', data_get($payload, 'next_required_action'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'receipt_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'preview_signature'));
        $this->assertGreaterThanOrEqual(3, count(data_get($payload, 'blocking_failures')));
        $this->assertContains('human_owner_signature', data_get($payload, 'required_before_execution'));
        $this->assertContains('preflight_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));

        $failedIds = collect(data_get($payload, 'blocking_failures'))->pluck('id')->all();

        $this->assertContains('human_signature_present', $failedIds);
        $this->assertContains('signature_valid_for_execution', $failedIds);
        $this->assertContains('draft_execution_flag_enabled', $failedIds);
    }

    public function test_command_human_output_lists_execution_preflight(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--execution-preflight' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Blocking failures', $output);
        $this->assertStringContainsString('Next required action', $output);
        $this->assertStringContainsString('blocked as expected', $output);
    }

    public function test_command_returns_signature_request_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--signature-request' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_signature_request.v1', data_get($payload, 'schema_version'));
        $this->assertSame('signature_pending', data_get($payload, 'status'));
        $this->assertSame('read_only_signature_request', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertSame('blocked', data_get($payload, 'preflight_status'));
        $this->assertSame('pending_human_signature', data_get($payload, 'signature_request.status'));
        $this->assertFalse(data_get($payload, 'signature_request.execution_allowed'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'signable_payload_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'request_hash'));
        $this->assertContains('human_owner', data_get($payload, 'signature_request.required_signer_roles'));
        $this->assertContains('voice_realtime_change', data_get($payload, 'signature_request.signable_payload.still_forbidden_after_signature'));
        $this->assertContains('signature_request_does_not_enable_execution', data_get($payload, 'signature_request.non_execution_guarantees'));
    }

    public function test_command_human_output_lists_signature_request(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--signature-request' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Signature request', $output);
        $this->assertStringContainsString('Request hash', $output);
        $this->assertStringContainsString('Preflight', $output);
        $this->assertStringContainsString('Signature request is ready for human review', $output);
    }

    public function test_command_returns_execution_runbook_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--execution-runbook' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_execution_runbook.v1', data_get($payload, 'schema_version'));
        $this->assertSame('runbook_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_execution_runbook', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertSame('signature_pending', data_get($payload, 'signature_status'));
        $this->assertSame('waiting_for_human_signature', data_get($payload, 'runbook.status'));
        $this->assertFalse(data_get($payload, 'runbook.execution_allowed'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'runbook_hash'));
        $this->assertContains('docs_only', data_get($payload, 'runbook.allowed_scope_after_signature'));
        $this->assertContains('voice_realtime_change', data_get($payload, 'runbook.forbidden_scope_after_signature'));
        $this->assertContains('git diff --check', data_get($payload, 'runbook.required_gates_after_signature'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'runbook.rollback_contract.forbidden_rollback_targets'));
        $this->assertContains('scoped_patch_diff', data_get($payload, 'runbook.evidence_contract.required_keys'));
        $this->assertContains('runbook_does_not_apply_patch', data_get($payload, 'runbook.non_execution_guarantees'));
        $this->assertGreaterThanOrEqual(5, count(data_get($payload, 'runbook.ordered_steps')));
    }

    public function test_command_human_output_lists_execution_runbook(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--execution-runbook' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Runbook', $output);
        $this->assertStringContainsString('Runbook hash', $output);
        $this->assertStringContainsString('Signature status', $output);
        $this->assertStringContainsString('Execution runbook is ready for post-signature review', $output);
    }

    public function test_command_returns_evidence_packet_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--evidence-packet' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_evidence_packet.v1', data_get($payload, 'schema_version'));
        $this->assertSame('evidence_packet_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_evidence_packet', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertSame('runbook_ready', data_get($payload, 'runbook_status'));
        $this->assertSame('evidence_template_ready', data_get($payload, 'evidence_packet.status'));
        $this->assertFalse(data_get($payload, 'evidence_packet.execution_allowed'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'evidence_packet_hash'));
        $this->assertContains('evidence_packet_does_not_apply_patch', data_get($payload, 'evidence_packet.non_execution_guarantees'));
        $this->assertSame('block_completion', data_get($payload, 'evidence_packet.failure_policy.missing_required_evidence'));
        $this->assertSame('require_new_human_review', data_get($payload, 'evidence_packet.failure_policy.hash_mismatch'));
        $this->assertGreaterThanOrEqual(5, count(data_get($payload, 'evidence_packet.required_evidence_items')));
        $this->assertGreaterThanOrEqual(4, count(data_get($payload, 'evidence_packet.claim_checks')));

        $requiredKeys = collect(data_get($payload, 'evidence_packet.required_evidence_items'))->pluck('key')->all();

        $this->assertContains('pre_patch_delta', $requiredKeys);
        $this->assertContains('scoped_patch_diff', $requiredKeys);
        $this->assertContains('gate_outputs', $requiredKeys);
    }

    public function test_command_human_output_lists_evidence_packet(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--evidence-packet' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Evidence packet', $output);
        $this->assertStringContainsString('Evidence hash', $output);
        $this->assertStringContainsString('Runbook status', $output);
        $this->assertStringContainsString('Evidence packet is ready', $output);
    }

    public function test_command_returns_completion_readiness_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--completion-readiness' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_completion_readiness.v1', data_get($payload, 'schema_version'));
        $this->assertSame('blocked_pending_execution_evidence', data_get($payload, 'status'));
        $this->assertSame('read_only_completion_readiness', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertFalse(data_get($payload, 'completion_claim_policy.may_claim_done'));
        $this->assertTrue(data_get($payload, 'completion_claim_policy.may_claim_ready_for_human_signature'));
        $this->assertFalse(data_get($payload, 'completion_claim_policy.may_claim_self_programming_enabled'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'evidence_packet_hash'));
        $this->assertContains('completion_readiness_does_not_mark_completion', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('signed_execution_receipt', data_get($payload, 'required_before_completion'));

        $failedIds = collect(data_get($payload, 'blocking_failures'))->pluck('id')->all();

        $this->assertContains('signed_execution_receipt_present', $failedIds);
        $this->assertContains('scoped_patch_evidence_present', $failedIds);
        $this->assertContains('required_gate_outputs_present', $failedIds);
    }

    public function test_command_human_output_lists_completion_readiness(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--completion-readiness' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Completion allowed', $output);
        $this->assertStringContainsString('Blocking failures', $output);
        $this->assertStringContainsString('Evidence hash', $output);
        $this->assertStringContainsString('Completion readiness is blocked as expected', $output);
    }

    public function test_command_returns_residual_risk_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--residual-risk' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_residual_risk.v1', data_get($payload, 'schema_version'));
        $this->assertSame('residual_risk_open', data_get($payload, 'status'));
        $this->assertSame('read_only_residual_risk', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertSame('blocked_pending_execution_evidence', data_get($payload, 'completion_status'));
        $this->assertSame('blocking', data_get($payload, 'risk_summary.highest_severity'));
        $this->assertFalse(data_get($payload, 'risk_summary.promotion_allowed'));
        $this->assertContains('residual_risk_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));
        $this->assertGreaterThanOrEqual(3, data_get($payload, 'risk_summary.blocking_count'));

        $riskIds = collect(data_get($payload, 'risks'))->pluck('id')->all();

        $this->assertContains('unsigned_execution', $riskIds);
        $this->assertContains('missing_scoped_diff', $riskIds);
        $this->assertContains('missing_gate_outputs', $riskIds);
        $this->assertContains('hot_runtime_scope', $riskIds);
    }

    public function test_command_human_output_lists_residual_risk(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--residual-risk' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Highest severity', $output);
        $this->assertStringContainsString('Blocking risks', $output);
        $this->assertStringContainsString('Residual risk remains open by design', $output);
    }

    public function test_command_returns_handoff_packet_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--handoff-packet' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_handoff_packet.v1', data_get($payload, 'schema_version'));
        $this->assertSame('handoff_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_handoff_packet', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertSame('handoff_ready', data_get($payload, 'handoff_packet.status'));
        $this->assertFalse(data_get($payload, 'handoff_packet.execution_allowed'));
        $this->assertFalse(data_get($payload, 'handoff_packet.completion_allowed'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'handoff_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'handoff_packet.stable_hashes.signature_request_hash'));
        $this->assertContains('human_signature_missing', data_get($payload, 'handoff_packet.current_blockers'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'handoff_packet.must_not_touch'));
        $this->assertContains('php artisan atlas:ai:self-construction --residual-risk --json', data_get($payload, 'handoff_packet.required_commands'));
        $this->assertContains('handoff_packet_does_not_enable_execution', data_get($payload, 'handoff_packet.non_execution_guarantees'));
        $this->assertSame('blocking', data_get($payload, 'handoff_packet.handoff_integrity.highest_risk'));
    }

    public function test_command_human_output_lists_handoff_packet(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--handoff-packet' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Handoff', $output);
        $this->assertStringContainsString('Handoff hash', $output);
        $this->assertStringContainsString('Handoff packet is ready', $output);
    }

    public function test_command_returns_next_action_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--next-action' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_next_action.v1', data_get($payload, 'schema_version'));
        $this->assertSame('next_action_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_next_action', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertSame('request_human_signature_review', data_get($payload, 'selected_action.id'));
        $this->assertTrue(data_get($payload, 'selected_action.allowed'));
        $this->assertSame('human_review', data_get($payload, 'selected_action.action_type'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'handoff_hash'));
        $this->assertContains('human_signature_missing', data_get($payload, 'current_blockers'));
        $this->assertContains('execute_scoped_patch', data_get($payload, 'forbidden_until_signature'));
        $this->assertContains('next_action_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));

        $blockedAction = collect(data_get($payload, 'candidate_actions'))->firstWhere('id', 'claim_completion');

        $this->assertFalse(data_get($blockedAction, 'allowed'));
    }

    public function test_command_human_output_lists_next_action(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--next-action' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Selected action', $output);
        $this->assertStringContainsString('Handoff hash', $output);
        $this->assertStringContainsString('Next action is human signature review', $output);
    }

    public function test_command_returns_phase_ledger_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--phase-ledger' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_phase_ledger.v1', data_get($payload, 'schema_version'));
        $this->assertSame('phase_ledger_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_phase_ledger', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertFalse(data_get($payload, 'promotion_allowed'));
        $this->assertSame('phase_5_low_risk_execution', data_get($payload, 'current_phase'));
        $this->assertSame('request_human_signature_review', data_get($payload, 'next_action_id'));
        $this->assertGreaterThanOrEqual(7, data_get($payload, 'phase_count'));
        $this->assertGreaterThanOrEqual(4, data_get($payload, 'ledger_summary.completed_count'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'ledger_summary.blocked_count'));
        $this->assertSame('promotion_ready', data_get($payload, 'ledger_summary.promotion_gate_status'));
        $this->assertSame('signature_pending', data_get($payload, 'ledger_summary.signature_status'));
        $this->assertSame('residual_risk_open', data_get($payload, 'ledger_summary.residual_risk_status'));
        $this->assertContains('missing_human_signature', data_get($payload, 'hard_blocks'));
        $this->assertContains('phase_ledger_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));

        $phaseIds = collect(data_get($payload, 'phases'))->pluck('id')->all();

        $this->assertContains('phase_5_low_risk_execution', $phaseIds);
        $this->assertContains('phase_6_restricted_runtime_patches', $phaseIds);
    }

    public function test_command_human_output_lists_phase_ledger(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--phase-ledger' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Current phase', $output);
        $this->assertStringContainsString('Next action', $output);
        $this->assertStringContainsString('Blocked phases', $output);
        $this->assertStringContainsString('Phase ledger is ready', $output);
    }

    public function test_command_returns_surface_matrix_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--surface-matrix' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_surface_matrix.v1', data_get($payload, 'schema_version'));
        $this->assertSame('surface_matrix_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_surface_matrix', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertGreaterThanOrEqual(17, data_get($payload, 'surface_count'));
        $this->assertContains('all_surfaces_are_read_only', data_get($payload, 'global_invariants'));
        $this->assertContains('no_surface_enables_self_programming', data_get($payload, 'global_invariants'));

        $commands = collect(data_get($payload, 'surfaces'))->pluck('command')->all();

        $this->assertContains('php artisan atlas:ai:self-construction --phase-ledger --json', $commands);
        $this->assertContains('php artisan atlas:ai:self-construction --next-action --json', $commands);
        $this->assertContains('php artisan atlas:ai:self-construction --external-blockers --json', $commands);
        $this->assertContains('php artisan atlas:ai:self-construction --cold-lane-certification --json', $commands);
        $this->assertContains('php artisan atlas:ai:self-construction --operator-checklist --json', $commands);
        $this->assertContains('php artisan atlas:ai:self-construction --promotion-blockers --json', $commands);
        $this->assertContains('php artisan atlas:ai:self-construction --readiness-digest --json', $commands);
        $this->assertContains('php artisan atlas:ai:self-construction --governance-scorecard --json', $commands);
        $this->assertContains('php artisan atlas:ai:self-construction --integrity-manifest --json', $commands);
        $this->assertContains('php artisan atlas:ai:self-construction --continuation-token --json', $commands);
        $this->assertContains('php artisan atlas:ai:self-construction --ownership-boundary --json', $commands);

        foreach (data_get($payload, 'surfaces') as $surface) {
            $this->assertFalse(data_get($surface, 'execution_allowed'));
            $this->assertFalse(data_get($surface, 'completion_allowed'));
            $this->assertSame('none_read_only', data_get($surface, 'write_scope'));
        }
    }

    public function test_command_human_output_lists_surface_matrix(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--surface-matrix' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Surfaces', $output);
        $this->assertStringContainsString('Surface matrix is ready', $output);
    }

    public function test_command_returns_external_blockers_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--external-blockers' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_external_blockers.v1', data_get($payload, 'schema_version'));
        $this->assertSame('external_blockers_reported', data_get($payload, 'status'));
        $this->assertSame('read_only_external_blockers', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertSame('surface_matrix_ready', data_get($payload, 'self_construction_surface_status'));
        $this->assertSame('phase_ledger_ready', data_get($payload, 'phase_ledger_status'));
        $this->assertGreaterThanOrEqual(3, data_get($payload, 'blocker_count'));
        $this->assertTrue(data_get($payload, 'policy.hot_files_must_not_be_edited'));
        $this->assertContains('external_blockers_does_not_edit_hot_files', data_get($payload, 'non_execution_guarantees'));

        $blockerIds = collect(data_get($payload, 'blockers'))->pluck('id')->all();

        $this->assertContains('voice_realtime_surface_doc_delta_hot', $blockerIds);
        $this->assertContains('voice_realtime_runtime_delta_hot', $blockerIds);
        $this->assertContains('voice_realtime_ap687_runtime_entrypoint_test_gap', $blockerIds);
        $this->assertContains('voice_realtime_php_delta_hot', $blockerIds);
        $this->assertContains('kernel_scanner_delta_hot', $blockerIds);
    }

    public function test_command_human_output_lists_external_blockers(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--external-blockers' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Blockers', $output);
        $this->assertStringContainsString('Surface status', $output);
        $this->assertStringContainsString('External blockers are reported', $output);
    }

    public function test_command_returns_cold_lane_certification_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--cold-lane-certification' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_cold_lane_certification.v1', data_get($payload, 'schema_version'));
        $this->assertSame('cold_lane_certified_with_external_blockers', data_get($payload, 'status'));
        $this->assertSame('read_only_cold_lane_certification', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertGreaterThanOrEqual(17, data_get($payload, 'surface_count'));
        $this->assertSame('phase_ledger_ready', data_get($payload, 'phase_ledger_status'));
        $this->assertSame('request_human_signature_review', data_get($payload, 'next_action_id'));
        $this->assertGreaterThanOrEqual(3, data_get($payload, 'external_blocker_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'certification_hash'));
        $this->assertContains('self_construction_surfaces_are_read_only', data_get($payload, 'certification.certified_properties'));
        $this->assertContains('external_hot_blockers_are_reported_not_edited', data_get($payload, 'certification.certified_properties'));
        $this->assertContains('cold_lane_certification_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_cold_lane_certification(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--cold-lane-certification' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Next action', $output);
        $this->assertStringContainsString('External blockers', $output);
        $this->assertStringContainsString('Certification hash', $output);
        $this->assertStringContainsString('Cold lane is certified read-only', $output);
    }

    public function test_command_returns_operator_checklist_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--operator-checklist' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_operator_checklist.v1', data_get($payload, 'schema_version'));
        $this->assertSame('operator_checklist_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_operator_checklist', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertSame('request_human_signature_review', data_get($payload, 'checklist_packet.next_action_id'));
        $this->assertGreaterThanOrEqual(5, data_get($payload, 'checklist_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'checklist_hash'));
        $this->assertContains('operator_checklist_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));

        $checklistIds = collect(data_get($payload, 'checklist_packet.checklist'))->pluck('id')->all();

        $this->assertContains('verify_cold_lane_hash', $checklistIds);
        $this->assertContains('confirm_hot_blockers_are_external', $checklistIds);
        $this->assertContains('run_global_governance_gates', $checklistIds);
    }

    public function test_command_human_output_lists_operator_checklist(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--operator-checklist' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Checklist items', $output);
        $this->assertStringContainsString('Checklist hash', $output);
        $this->assertStringContainsString('Operator checklist is ready', $output);
    }

    public function test_command_returns_promotion_blockers_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--promotion-blockers' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_promotion_blockers.v1', data_get($payload, 'schema_version'));
        $this->assertSame('promotion_blockers_open', data_get($payload, 'status'));
        $this->assertSame('read_only_promotion_blockers', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'promotion_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertGreaterThanOrEqual(4, data_get($payload, 'blocker_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'blocker_hash'));
        $this->assertContains('promotion_blockers_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));

        $blockerIds = collect(data_get($payload, 'blocker_packet.blockers'))->pluck('id')->all();

        $this->assertContains('human_signature_missing', $blockerIds);
        $this->assertContains('signed_execution_evidence_missing', $blockerIds);
        $this->assertContains('residual_risk_open', $blockerIds);
        $this->assertContains('voice_realtime_runtime_delta_hot', $blockerIds);
    }

    public function test_command_human_output_lists_promotion_blockers(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--promotion-blockers' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Promotion allowed', $output);
        $this->assertStringContainsString('Completion allowed', $output);
        $this->assertStringContainsString('Blocker hash', $output);
        $this->assertStringContainsString('Promotion blockers are consolidated read-only', $output);
    }

    public function test_command_returns_readiness_digest_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--readiness-digest' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_readiness_digest.v1', data_get($payload, 'schema_version'));
        $this->assertSame('readiness_digest_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_readiness_digest', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'promotion_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertSame('phase_5_low_risk_execution', data_get($payload, 'digest.current_phase'));
        $this->assertSame('request_human_signature_review', data_get($payload, 'digest.next_action_id'));
        $this->assertGreaterThanOrEqual(21, data_get($payload, 'digest.surface_count'));
        $this->assertGreaterThanOrEqual(4, data_get($payload, 'digest.blocker_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'digest_hash'));
        $this->assertContains('readiness_digest_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('git diff --check', data_get($payload, 'digest.required_next_commands'));
    }

    public function test_command_human_output_lists_readiness_digest(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--readiness-digest' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Current phase', $output);
        $this->assertStringContainsString('Next action', $output);
        $this->assertStringContainsString('Digest hash', $output);
        $this->assertStringContainsString('Readiness digest is ready', $output);
    }

    public function test_command_returns_governance_scorecard_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--governance-scorecard' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_governance_scorecard.v1', data_get($payload, 'schema_version'));
        $this->assertSame('governance_scorecard_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_governance_scorecard', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'promotion_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertSame(100, data_get($payload, 'scorecard.score'));
        $this->assertSame(100, data_get($payload, 'scorecard.max_score'));
        $this->assertSame('strong_governed_readiness', data_get($payload, 'scorecard.rating'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'scorecard_hash'));
        $this->assertContains('governance_scorecard_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));

        $criteriaIds = collect(data_get($payload, 'scorecard.criteria'))->pluck('id')->all();

        $this->assertContains('documentation_complete', $criteriaIds);
        $this->assertContains('command_surface_complete', $criteriaIds);
        $this->assertContains('cold_lane_certified', $criteriaIds);
    }

    public function test_command_human_output_lists_governance_scorecard(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--governance-scorecard' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Score', $output);
        $this->assertStringContainsString('Rating', $output);
        $this->assertStringContainsString('Scorecard hash', $output);
        $this->assertStringContainsString('Governance scorecard is ready', $output);
    }

    public function test_command_returns_integrity_manifest_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--integrity-manifest' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_integrity_manifest.v1', data_get($payload, 'schema_version'));
        $this->assertSame('integrity_manifest_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_integrity_manifest', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'promotion_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertGreaterThanOrEqual(6, data_get($payload, 'entry_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'manifest_hash'));
        $this->assertContains('integrity_manifest_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));

        $entryIds = collect(data_get($payload, 'manifest.entries'))->pluck('id')->all();

        $this->assertContains('readiness_digest', $entryIds);
        $this->assertContains('governance_scorecard', $entryIds);
        $this->assertContains('cold_lane_certification', $entryIds);

        foreach (data_get($payload, 'manifest.entries') as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($entry, 'hash'));
        }
    }

    public function test_command_human_output_lists_integrity_manifest(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--integrity-manifest' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Manifest entries', $output);
        $this->assertStringContainsString('Manifest hash', $output);
        $this->assertStringContainsString('Integrity manifest is ready', $output);
    }

    public function test_command_returns_continuation_token_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--continuation-token' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_continuation_token.v1', data_get($payload, 'schema_version'));
        $this->assertSame('continuation_token_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_continuation_token', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'promotion_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertSame('phase_5_low_risk_execution', data_get($payload, 'token.current_phase'));
        $this->assertSame('request_human_signature_review', data_get($payload, 'token.next_action_id'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'token.manifest_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'token.digest_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'token_hash'));
        $this->assertContains('git status --short', data_get($payload, 'token.must_run_first'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'token.must_not_touch'));
        $this->assertContains('continuation_token_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_continuation_token(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--continuation-token' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Token id', $output);
        $this->assertStringContainsString('Token hash', $output);
        $this->assertStringContainsString('Continuation token is ready', $output);
    }

    public function test_command_returns_ownership_boundary_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--ownership-boundary' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_ownership_boundary.v1', data_get($payload, 'schema_version'));
        $this->assertSame('ownership_boundary_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_ownership_boundary', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'promotion_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertGreaterThanOrEqual(5, data_get($payload, 'allowed_file_count'));
        $this->assertGreaterThanOrEqual(4, data_get($payload, 'forbidden_scope_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'boundary_hash'));
        $this->assertContains('ownership_boundary_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));

        $forbiddenPaths = collect(data_get($payload, 'boundary.forbidden_scopes'))->pluck('path')->all();

        $this->assertContains('runtimes/python/voice_realtime/**', $forbiddenPaths);
        $this->assertContains('app/Services/Ai/Voice/**', $forbiddenPaths);
        $this->assertContains('app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php', $forbiddenPaths);
        $this->assertContains('report_hot_blockers_without_editing', data_get($payload, 'boundary.required_behavior'));
    }

    public function test_command_human_output_lists_ownership_boundary(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--ownership-boundary' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Allowed files', $output);
        $this->assertStringContainsString('Forbidden scopes', $output);
        $this->assertStringContainsString('Boundary hash', $output);
        $this->assertStringContainsString('Ownership boundary is ready', $output);
    }
}
