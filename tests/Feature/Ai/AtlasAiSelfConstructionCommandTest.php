<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasAiSelfConstructionCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(storage_path('app/atlas/self-construction/reservations'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/atlas/self-construction/reservations'));

        parent::tearDown();
    }

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

    public function test_command_returns_implementation_packet_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--implementation-packet' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_ai_implementation_packet.v1', data_get($payload, 'schema_version'));
        $this->assertSame('packet_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_ai_implementation_packet', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'packet.execution_allowed'));
        $this->assertTrue(data_get($payload, 'packet.requires_human_signature'));
        $this->assertSame('AIP-SELF-CONSTRUCTION-READ-ONLY-0001', data_get($payload, 'packet.packet_id'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'packet_hash'));
        $this->assertContains('docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md', data_get($payload, 'packet.context_docs'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'packet.forbidden_files'));
        $this->assertContains('php artisan atlas:ai:self-construction --scope-validator --json', data_get($payload, 'packet.required_gates'));
        $this->assertContains('implementation_packet_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_implementation_packet(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--implementation-packet' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Packet hash', $output);
        $this->assertStringContainsString('AI Implementation Packet is ready', $output);
    }

    public function test_command_returns_work_splitter_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--work-splitter' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_work_splitter.v1', data_get($payload, 'schema_version'));
        $this->assertSame('split_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_work_splitter', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertSame(5, data_get($payload, 'packet_count'));
        $this->assertGreaterThanOrEqual(2, data_get($payload, 'withheld_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'split_hash'));
        $this->assertContains('work_splitter_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));

        $packetIds = collect(data_get($payload, 'split.packets'))->pluck('packet_id')->all();
        $withheldIds = collect(data_get($payload, 'split.withheld_work'))->pluck('id')->all();

        $this->assertContains('AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001', $packetIds);
        $this->assertContains('AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002', $packetIds);
        $this->assertContains('AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003', $packetIds);
        $this->assertContains('AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004', $packetIds);
        $this->assertContains('AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005', $packetIds);
        $this->assertContains('voice_runtime_packet_withheld', $withheldIds);
        $this->assertContains('kernel_scanner_packet_withheld', $withheldIds);
    }

    public function test_command_human_output_lists_work_splitter(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--work-splitter' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Split hash', $output);
        $this->assertStringContainsString('Work Splitter emits disjoint read-only packets', $output);
    }

    public function test_command_returns_scope_validator_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--scope-validator' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_scope_validator.v1', data_get($payload, 'schema_version'));
        $this->assertContains(data_get($payload, 'status'), ['pass', 'blocked']);
        $this->assertSame('read_only_scope_validator', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'validator_hash'));
        $this->assertIsArray(data_get($payload, 'validator.changed_files'));
        $this->assertIsArray(data_get($payload, 'validator.blocking_violations'));
        $this->assertIsInt(data_get($payload, 'summary.changed_count'));
        $this->assertSame('implementation_packet', data_get($payload, 'validator.packet_scope_source'));
        $this->assertContains('scope_validator_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_returns_packet_scoped_scope_validator_as_json(): void
    {
        $packetId = 'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004';

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--scope-validator' => true,
            '--packet' => $packetId,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_scope_validator.v1', data_get($payload, 'schema_version'));
        $this->assertSame($packetId, data_get($payload, 'validator.packet_id'));
        $this->assertSame($packetId, data_get($payload, 'validator.requested_packet_id'));
        $this->assertSame('work_splitter_packet', data_get($payload, 'validator.packet_scope_source'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'validator_hash'));
        $this->assertContains('scope_validator_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_scope_validator(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--scope-validator' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Validator hash', $output);
        $this->assertStringContainsString('Scope Validator', $output);
    }

    public function test_command_returns_assignment_preview_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--assignment-preview' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_assignment_preview.v1', data_get($payload, 'schema_version'));
        $this->assertSame('claim_preview_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_assignment_preview', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'assignment.execution_allowed'));
        $this->assertSame('preview_only_not_persisted', data_get($payload, 'assignment.claim_state'));
        $this->assertSame('AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001', data_get($payload, 'assignment.selected_packet_id'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'assignment_hash'));
        $this->assertContains('php artisan atlas:ai:self-construction --scope-validator --json', data_get($payload, 'assignment.required_first_commands'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'assignment.forbidden_files'));
        $this->assertContains('assignment_preview_does_not_persist_claim', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('assignment_preview_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_assignment_preview(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--assignment-preview' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Selected packet', $output);
        $this->assertStringContainsString('Assignment hash', $output);
        $this->assertStringContainsString('Assignment preview selected one safe packet', $output);
    }

    public function test_command_returns_packet_runbook_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--packet-runbook' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_packet_consumption_runbook.v1', data_get($payload, 'schema_version'));
        $this->assertSame('runbook_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_packet_consumption_runbook', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'runbook.execution_allowed'));
        $this->assertFalse(data_get($payload, 'runbook.claim_persisted'));
        $this->assertSame('AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001', data_get($payload, 'runbook.selected_packet_id'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'runbook_hash'));
        $this->assertContains('php artisan atlas:ai:self-construction --scope-validator --packet=AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001 --json', data_get($payload, 'runbook.required_gates'));
        $this->assertContains('scope_validator_output', data_get($payload, 'runbook.required_evidence'));
        $this->assertContains('state_scope_validator_status', data_get($payload, 'runbook.final_response_contract'));
        $this->assertContains('packet_runbook_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));

        $stepIds = collect(data_get($payload, 'runbook.steps'))->pluck('id')->all();

        $this->assertContains('inspect_worktree', $stepIds);
        $this->assertContains('confirm_scope', $stepIds);
        $this->assertContains('return_evidence', $stepIds);
    }

    public function test_command_human_output_lists_packet_runbook(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--packet-runbook' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Runbook hash', $output);
        $this->assertStringContainsString('Packet runbook is ready', $output);
    }

    public function test_command_returns_packet_evidence_report_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--packet-evidence-report' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_packet_evidence_report.v1', data_get($payload, 'schema_version'));
        $this->assertSame('read_only_packet_evidence_report', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertFalse(data_get($payload, 'report.execution_allowed'));
        $this->assertFalse(data_get($payload, 'report.completion_allowed'));
        $this->assertSame('AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001', data_get($payload, 'report.selected_packet_id'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'report_hash'));
        $this->assertContains('packet_evidence_report_does_not_mark_completed', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('packet_evidence_report_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));

        $gateIds = collect(data_get($payload, 'report.gate_results'))->pluck('id')->all();
        $evidenceIds = collect(data_get($payload, 'report.evidence_results'))->pluck('id')->all();

        $this->assertContains('scope_validator_passed', $gateIds);
        $this->assertContains('scope_validator_output', $evidenceIds);
        $this->assertIsArray(data_get($payload, 'report.blocking_reasons'));
    }

    public function test_command_human_output_lists_packet_evidence_report(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--packet-evidence-report' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Report status', $output);
        $this->assertStringContainsString('Report hash', $output);
        $this->assertStringContainsString('Packet evidence report', $output);
    }

    public function test_command_returns_packet_completion_gate_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--packet-completion-gate' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_packet_completion_gate.v1', data_get($payload, 'schema_version'));
        $this->assertSame('read_only_packet_completion_gate', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertFalse(data_get($payload, 'gate.execution_allowed'));
        $this->assertFalse(data_get($payload, 'gate.completion_allowed'));
        $this->assertFalse(data_get($payload, 'gate.durable_completion_written'));
        $this->assertSame('AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001', data_get($payload, 'gate.selected_packet_id'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'gate_hash'));
        $this->assertContains(data_get($payload, 'gate.decision'), ['block', 'request_human_review']);
        $this->assertContains('packet_completion_gate_does_not_mark_completed', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('packet_completion_gate_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('evidence_results', data_get($payload, 'gate.inspected_fields'));
    }

    public function test_command_human_output_lists_packet_completion_gate(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--packet-completion-gate' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Decision', $output);
        $this->assertStringContainsString('Gate hash', $output);
        $this->assertStringContainsString('Packet completion gate', $output);
    }

    public function test_command_returns_reservation_ledger_preview_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--reservation-ledger-preview' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_reservation_ledger_preview.v1', data_get($payload, 'schema_version'));
        $this->assertSame('reservation_preview_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_reservation_ledger_preview', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'reservation_persisted'));
        $this->assertSame('AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001', data_get($payload, 'reservation.selected_packet_id'));
        $this->assertSame('preview_only_not_persisted', data_get($payload, 'reservation.claim_state'));
        $this->assertFalse(data_get($payload, 'reservation.claim_persisted'));
        $this->assertFalse(data_get($payload, 'reservation.ledger_write_allowed'));
        $this->assertNull(data_get($payload, 'reservation.lease_expires_at'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'reservation_hash'));
        $this->assertContains('one_active_reservation_per_packet', data_get($payload, 'reservation.collision_policy'));
        $this->assertContains('block_when_packet_hash_changes', data_get($payload, 'reservation.stale_policy'));
        $this->assertContains('approved_reservation_ledger_ap', data_get($payload, 'reservation.future_persistence_requires'));
        $this->assertContains('reservation_ledger_preview_does_not_persist_claim', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('reservation_ledger_preview_does_not_write_ledger', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('reservation_ledger_preview_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_reservation_ledger_preview(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--reservation-ledger-preview' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Selected packet', $output);
        $this->assertStringContainsString('Reservation hash', $output);
        $this->assertStringContainsString('Reservation ledger preview is ready', $output);
    }

    public function test_command_returns_ai_session_bootstrap_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--ai-session-bootstrap' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_ai_session_bootstrap.v1', data_get($payload, 'schema_version'));
        $this->assertSame('bootstrap_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_ai_session_bootstrap', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertFalse(data_get($payload, 'claim_persisted'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertSame('AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001', data_get($payload, 'bootstrap.selected_packet_id'));
        $this->assertSame('blocked', data_get($payload, 'bootstrap.scope_validator_status'));
        $this->assertSame('blocked', data_get($payload, 'bootstrap.completion_gate_status'));
        $this->assertFalse(data_get($payload, 'bootstrap.claim_persisted'));
        $this->assertFalse(data_get($payload, 'bootstrap.ledger_write_allowed'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'bootstrap_hash'));
        $this->assertContains('php artisan atlas:ai:self-construction --ai-session-bootstrap --packet=AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001 --json', data_get($payload, 'bootstrap.required_first_commands'));
        $this->assertContains('scope_validator_blocked', data_get($payload, 'bootstrap.stop_conditions'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'bootstrap.forbidden_hot_scopes'));
        $this->assertSame('atlas.self_construction_reservation_ledger_preview.v1', data_get($payload, 'bootstrap.payload_refs.reservation_schema'));
        $this->assertContains('ai_session_bootstrap_does_not_persist_claim', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('ai_session_bootstrap_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_ai_session_bootstrap(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--ai-session-bootstrap' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Claim persisted', $output);
        $this->assertStringContainsString('Selected packet', $output);
        $this->assertStringContainsString('Bootstrap hash', $output);
        $this->assertStringContainsString('AI session bootstrap is ready', $output);
    }

    public function test_command_returns_reservation_status_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--reservation-status' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_reservation_status.v1', data_get($payload, 'schema_version'));
        $this->assertSame('reservation_ledger_ready', data_get($payload, 'status'));
        $this->assertSame('durable_local_reservation_status', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertTrue(data_get($payload, 'ledger.ledger_available'));
        $this->assertSame(0, data_get($payload, 'ledger.active_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'ledger_hash'));
    }

    public function test_command_human_output_lists_reservation_status(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--reservation-status' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Active reservations', $output);
        $this->assertStringContainsString('Completed reservations', $output);
        $this->assertStringContainsString('Ledger hash', $output);
        $this->assertStringContainsString('Durable local reservation ledger is available', $output);
    }

    public function test_command_claims_packet_and_queue_marks_it_claimed(): void
    {
        $packetId = 'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001';

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--claim-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
            '--json' => true,
        ]);

        $claim = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_claim_packet.v1', data_get($claim, 'schema_version'));
        $this->assertSame('claimed', data_get($claim, 'status'));
        $this->assertTrue(data_get($claim, 'claim_persisted'));
        $this->assertTrue(data_get($claim, 'claim.event_appended'));
        $this->assertSame($packetId, data_get($claim, 'claim.packet_id'));
        $this->assertSame('codex-a', data_get($claim, 'claim.actor'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($claim, 'claim_hash'));

        Artisan::call('atlas:ai:self-construction', [
            '--packet-queue' => true,
            '--json' => true,
        ]);

        $queue = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $entry = collect(data_get($queue, 'queue.entries'))->firstWhere('packet_id', $packetId);

        $this->assertSame(4, data_get($queue, 'queue.available_count'));
        $this->assertSame(1, data_get($queue, 'queue.claimed_count'));
        $this->assertSame('claimed', data_get($entry, 'queue_state'));
        $this->assertSame('codex-a', data_get($entry, 'active_reservation_actor'));
    }

    public function test_command_claims_next_packet_with_scoped_start_packet(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--claim-next-packet' => true,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_claim_next_packet.v1', data_get($payload, 'schema_version'));
        $this->assertSame('claimed', data_get($payload, 'status'));
        $this->assertSame('durable_local_claim_next_packet', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertTrue(data_get($payload, 'claim_persisted'));
        $this->assertSame('AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001', data_get($payload, 'packet_id'));
        $this->assertSame('AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001', data_get($payload, 'start.packet_id'));
        $this->assertSame('codex-a', data_get($payload, 'start.actor'));
        $this->assertContains('docs/engineering-knowledge-base/atlas-ai-self-construction-os.md', data_get($payload, 'start.allowed_files'));
        $this->assertContains('php artisan atlas:ai:self-construction --scope-validator --packet=AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001 --json', data_get($payload, 'start.required_first_commands'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'start_hash'));
    }

    public function test_command_human_output_lists_claim_next_packet(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--claim-next-packet' => true,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Claim persisted', $output);
        $this->assertStringContainsString('Packet', $output);
        $this->assertStringContainsString('Start hash', $output);
        $this->assertStringContainsString('Next packet was durably claimed', $output);
    }

    public function test_command_returns_codex_launch_plan_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-launch-plan' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_launch_plan.v1', data_get($payload, 'schema_version'));
        $this->assertSame('codex_launch_plan_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_launch_plan', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'claim_persisted'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame(5, data_get($payload, 'plan.max_sessions'));
        $this->assertSame(5, data_get($payload, 'plan.launchable_count'));
        $this->assertSame('AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001', data_get($payload, 'plan.sessions.0.expected_packet_id'));
        $this->assertSame('codex-1', data_get($payload, 'plan.sessions.0.actor'));
        $this->assertStringContainsString('--codex-start-packet --actor=codex-1 --session=self-construction-session-1 --json', data_get($payload, 'plan.sessions.0.command'));
        $this->assertContains('codex_launch_plan_does_not_claim_packets', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'plan_hash'));
    }

    public function test_command_human_output_lists_codex_launch_plan(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-launch-plan' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Launchable sessions', $output);
        $this->assertStringContainsString('Plan hash', $output);
        $this->assertStringContainsString('Codex launch plan is ready', $output);
    }

    public function test_command_codex_launch_plan_does_not_claim_packets(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--codex-launch-plan' => true,
            '--json' => true,
        ]);

        $launchPlan = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        Artisan::call('atlas:ai:self-construction', [
            '--reservation-status' => true,
            '--json' => true,
        ]);

        $status = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(5, data_get($launchPlan, 'plan.launchable_count'));
        $this->assertSame(0, data_get($status, 'ledger.active_count'));
        $this->assertSame(0, data_get($status, 'ledger.event_count'));
    }

    public function test_command_returns_codex_execution_status_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-execution-status' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_execution_status.v1', data_get($payload, 'schema_version'));
        $this->assertSame('codex_execution_status_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_execution_status', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'claim_persisted'));
        $this->assertFalse(data_get($payload, 'completion_persisted'));
        $this->assertSame(5, data_get($payload, 'monitor.counts.available'));
        $this->assertSame(0, data_get($payload, 'monitor.counts.claimed'));
        $this->assertSame(0, data_get($payload, 'monitor.counts.completed'));
        $this->assertSame(5, data_get($payload, 'monitor.counts.launchable'));
        $this->assertSame('launch_available_codex_sessions', data_get($payload, 'monitor.recommended_next_action'));
        $this->assertContains('codex_execution_status_does_not_claim_packets', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'monitor_hash'));
    }

    public function test_command_codex_execution_status_reports_claimed_sessions(): void
    {
        $packetId = 'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001';

        Artisan::call('atlas:ai:self-construction', [
            '--claim-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-execution-status' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(4, data_get($payload, 'monitor.counts.available'));
        $this->assertSame(1, data_get($payload, 'monitor.counts.claimed'));
        $this->assertSame($packetId, data_get($payload, 'monitor.claimed_sessions.0.packet_id'));
        $this->assertSame('codex-a', data_get($payload, 'monitor.claimed_sessions.0.actor'));
        $this->assertSame('wait_for_active_sessions_or_review_their_final_response_contracts', data_get($payload, 'monitor.recommended_next_action'));
    }

    public function test_command_human_output_lists_codex_execution_status(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-execution-status' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Active', $output);
        $this->assertStringContainsString('Completed', $output);
        $this->assertStringContainsString('Monitor hash', $output);
        $this->assertStringContainsString('Codex execution status is ready', $output);
    }

    public function test_command_returns_codex_integration_report_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-integration-report' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_integration_report.v1', data_get($payload, 'schema_version'));
        $this->assertSame('codex_integration_report_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_integration_report', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_persisted'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertSame('nothing_completed_yet', data_get($payload, 'report.integration_status'));
        $this->assertSame(0, data_get($payload, 'report.counts.ready_to_review'));
        $this->assertSame(5, data_get($payload, 'report.counts.missing_packets'));
        $this->assertContains('codex_integration_report_does_not_approve_code', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('packet_completion_is_not_code_approval', data_get($payload, 'report.approval_boundaries'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'report_hash'));
    }

    public function test_command_codex_integration_report_lists_completed_packets(): void
    {
        $packetId = 'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001';

        Artisan::call('atlas:ai:self-construction', [
            '--claim-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
            '--json' => true,
        ]);

        Artisan::call('atlas:ai:self-construction', [
            '--complete-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
            '--reason' => 'packet_scope_finished',
            '--evidence-hash' => str_repeat('a', 64),
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-integration-report' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(1, data_get($payload, 'report.counts.ready_to_review'));
        $this->assertSame(4, data_get($payload, 'report.counts.missing_packets'));
        $this->assertSame($packetId, data_get($payload, 'report.ready_to_review_packets.0.packet_id'));
        $this->assertSame('codex-a', data_get($payload, 'report.ready_to_review_packets.0.actor'));
        $this->assertSame(str_repeat('a', 64), data_get($payload, 'report.ready_to_review_packets.0.evidence_hash'));
        $this->assertContains('inspect_diff_for_allowed_files_only', data_get($payload, 'report.ready_to_review_packets.0.review_expectations'));
        $this->assertSame('partial_completion_review_available', data_get($payload, 'report.integration_status'));
    }

    public function test_command_human_output_lists_codex_integration_report(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-integration-report' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Integration status', $output);
        $this->assertStringContainsString('Ready to review', $output);
        $this->assertStringContainsString('Report hash', $output);
        $this->assertStringContainsString('Codex integration report is ready', $output);
    }

    public function test_command_returns_codex_merge_readiness_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-merge-readiness' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_merge_readiness.v1', data_get($payload, 'schema_version'));
        $this->assertSame('blocked_for_merge_review', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_merge_readiness', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame(0, data_get($payload, 'readiness.ready_packet_count'));
        $this->assertSame(5, data_get($payload, 'readiness.missing_packet_count'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'readiness.blocking_count'));
        $this->assertContains('codex_merge_readiness_does_not_merge', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('merge_readiness_is_not_merge_approval', data_get($payload, 'readiness.merge_boundaries'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'readiness_hash'));
    }

    public function test_command_returns_codex_merge_readiness_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-merge-readiness' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ready_for_human_merge_review', data_get($payload, 'status'));
        $this->assertSame('ready_for_human_merge_review', data_get($payload, 'readiness.merge_review_status'));
        $this->assertSame(5, data_get($payload, 'readiness.ready_packet_count'));
        $this->assertSame(0, data_get($payload, 'readiness.missing_packet_count'));
        $this->assertSame(0, data_get($payload, 'readiness.blocking_count'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertSame('perform_human_or_governed_receipt_review_before_merge', data_get($payload, 'readiness.recommended_next_action'));
    }

    public function test_command_human_output_lists_codex_merge_readiness(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-merge-readiness' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Merge review status', $output);
        $this->assertStringContainsString('Blocking count', $output);
        $this->assertStringContainsString('Readiness hash', $output);
        $this->assertStringContainsString('Codex merge readiness is blocked', $output);
    }

    public function test_command_returns_codex_final_review_packet_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-final-review-packet' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_final_review_packet.v1', data_get($payload, 'schema_version'));
        $this->assertSame('blocked_before_final_review', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_final_review_packet', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertTrue(data_get($payload, 'packet.decision_required'));
        $this->assertSame(0, data_get($payload, 'packet.ready_packet_count'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'packet.blocking_count'));
        $this->assertContains('final_review_packet_is_not_approval', data_get($payload, 'packet.review_boundaries'));
        $this->assertContains('codex_final_review_packet_does_not_merge', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'packet_hash'));
    }

    public function test_command_returns_codex_final_review_packet_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-final-review-packet' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('final_review_packet_ready', data_get($payload, 'status'));
        $this->assertSame('ready_for_principal_integrator_review', data_get($payload, 'packet.review_status'));
        $this->assertSame(5, data_get($payload, 'packet.ready_packet_count'));
        $this->assertSame(0, data_get($payload, 'packet.blocking_count'));
        $this->assertContains('confirm_each_packet_diff_only_touches_allowed_files', data_get($payload, 'packet.principal_integrator_checklist'));
        $this->assertContains('php artisan atlas:ai:architecture-validate --json', data_get($payload, 'packet.required_review_gates'));
        $this->assertSame('request_changes', data_get($payload, 'packet.decision_slots.0.default'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
    }

    public function test_command_human_output_lists_codex_final_review_packet(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-final-review-packet' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Review status', $output);
        $this->assertStringContainsString('Decision required', $output);
        $this->assertStringContainsString('Packet hash', $output);
        $this->assertStringContainsString('Codex final review packet is blocked', $output);
    }

    public function test_command_returns_codex_review_decision_template_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-decision-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_decision_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('blocked_before_review_decision_template', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_decision_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'template.decision_recording_allowed'));
        $this->assertSame('request_changes', data_get($payload, 'template.default_decision'));
        $this->assertContains('principal_integrator_name', data_get($payload, 'template.required_inputs'));
        $this->assertContains('decision_template_does_not_record_decision', data_get($payload, 'template.boundaries'));
        $this->assertContains('codex_review_decision_template_does_not_merge', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'template_hash'));
    }

    public function test_command_returns_codex_review_decision_template_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-decision-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('review_decision_template_ready', data_get($payload, 'status'));
        $this->assertSame('ready_for_manual_decision', data_get($payload, 'template.decision_status'));
        $this->assertSame(5, data_get($payload, 'template.ready_packet_count'));
        $this->assertSame(0, data_get($payload, 'template.blocking_count'));
        $this->assertContains('approve_for_merge', data_get($payload, 'template.allowed_decisions'));
        $this->assertContains('all_required_review_gates_passed', data_get($payload, 'template.approval_preconditions'));
        $this->assertSame('request_changes', data_get($payload, 'template.default_safe_decision_policy.when_any_precondition_is_missing'));
        $this->assertFalse(data_get($payload, 'template.decision_recording_allowed'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
    }

    public function test_command_human_output_lists_codex_review_decision_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-decision-template' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Decision status', $output);
        $this->assertStringContainsString('Recording allowed', $output);
        $this->assertStringContainsString('Template hash', $output);
        $this->assertStringContainsString('Codex review decision template is blocked', $output);
    }

    public function test_command_returns_codex_review_receipt_draft_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-receipt-draft' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_receipt_draft.v1', data_get($payload, 'schema_version'));
        $this->assertSame('blocked_before_review_receipt_draft', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_receipt_draft', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'decision_recorded'));
        $this->assertTrue(data_get($payload, 'receipt.signature_required'));
        $this->assertFalse(data_get($payload, 'receipt.signature_valid'));
        $this->assertSame('request_changes', data_get($payload, 'receipt.default_decision'));
        $this->assertContains('receipt_draft_is_unsigned', data_get($payload, 'receipt.non_authorizing_invariants'));
        $this->assertContains('codex_review_receipt_draft_does_not_merge', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'receipt_hash'));
    }

    public function test_command_returns_codex_review_receipt_draft_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-receipt-draft' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('review_receipt_draft_ready', data_get($payload, 'status'));
        $this->assertSame('draft_ready_for_signature_review', data_get($payload, 'receipt.status'));
        $this->assertContains('principal_integrator', data_get($payload, 'receipt.signer_roles'));
        $this->assertContains('all_required_review_gates_passed', data_get($payload, 'receipt.approval_preconditions'));
        $this->assertContains('php artisan atlas:ai:self-construction --codex-review-decision-template --json', data_get($payload, 'receipt.verification_commands'));
        $this->assertSame('principal_integrator_completes_and_signs_receipt_in_future_governed_surface', data_get($payload, 'receipt.next_required_action'));
        $this->assertFalse(data_get($payload, 'receipt.signature_valid'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
    }

    public function test_command_human_output_lists_codex_review_receipt_draft(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-receipt-draft' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Receipt status', $output);
        $this->assertStringContainsString('Signature required', $output);
        $this->assertStringContainsString('Receipt hash', $output);
        $this->assertStringContainsString('Codex review receipt draft is blocked', $output);
    }

    public function test_command_returns_codex_review_signature_request_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-signature-request' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_signature_request.v1', data_get($payload, 'schema_version'));
        $this->assertSame('blocked_before_review_signature_request', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_signature_request', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'signature_request.signature_present'));
        $this->assertFalse(data_get($payload, 'signature_request.signature_valid'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'decision_recorded'));
        $this->assertContains('signature_request_is_not_signature', data_get($payload, 'signature_request.non_authorizing_invariants'));
        $this->assertContains('codex_review_signature_request_does_not_merge', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'signable_payload_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'request_hash'));
    }

    public function test_command_returns_codex_review_signature_request_pending_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-signature-request' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('review_signature_pending', data_get($payload, 'status'));
        $this->assertSame('pending_principal_integrator_signature', data_get($payload, 'signature_request.status'));
        $this->assertSame('principal_integrator_explicit_review_decision', data_get($payload, 'signable_payload.requested_signature_type'));
        $this->assertContains('principal_integrator', data_get($payload, 'signature_request.required_signer_roles'));
        $this->assertContains('approve_for_merge', data_get($payload, 'signable_payload.allowed_decisions'));
        $this->assertContains('auto_merge_without_explicit_human_merge_action', data_get($payload, 'signable_payload.still_forbidden_after_signature'));
        $this->assertFalse(data_get($payload, 'signature_request.signature_present'));
        $this->assertFalse(data_get($payload, 'signature_request.signature_valid'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
    }

    public function test_command_human_output_lists_codex_review_signature_request(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-signature-request' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Signature status', $output);
        $this->assertStringContainsString('Signature present', $output);
        $this->assertStringContainsString('Signable hash', $output);
        $this->assertStringContainsString('Codex review signature request is blocked', $output);
    }

    public function test_command_returns_codex_review_post_signature_runbook_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-post-signature-runbook' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_post_signature_runbook.v1', data_get($payload, 'schema_version'));
        $this->assertSame('blocked_before_post_signature_runbook', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_post_signature_runbook', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertTrue(data_get($payload, 'runbook.signature_required'));
        $this->assertFalse(data_get($payload, 'runbook.auto_merge_allowed'));
        $this->assertContains('post_signature_runbook_does_not_merge', data_get($payload, 'runbook.non_authorizing_invariants'));
        $this->assertContains('codex_review_post_signature_runbook_does_not_merge', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'runbook_hash'));
    }

    public function test_command_returns_codex_review_post_signature_runbook_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-post-signature-runbook' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('post_signature_runbook_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_valid_signature', data_get($payload, 'runbook.status'));
        $this->assertSame(4, data_get($payload, 'runbook.step_count'));
        $this->assertContains('valid_signature_against_signable_payload_hash', data_get($payload, 'runbook.required_before_any_merge_action'));
        $this->assertContains('explicit_manual_or_governed_merge_action_created', data_get($payload, 'runbook.required_before_any_merge_action'));
        $this->assertContains('auto_merge_from_runbook', data_get($payload, 'runbook.still_forbidden'));
        $this->assertFalse(data_get($payload, 'runbook.signature_valid'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
    }

    public function test_command_human_output_lists_codex_review_post_signature_runbook(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-post-signature-runbook' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Runbook status', $output);
        $this->assertStringContainsString('Signature required', $output);
        $this->assertStringContainsString('Runbook hash', $output);
        $this->assertStringContainsString('Codex review post-signature runbook is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_action_template_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-action-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_action_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('blocked_before_merge_action_template', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_action_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertTrue(data_get($payload, 'template.explicit_merge_action_required'));
        $this->assertFalse(data_get($payload, 'template.auto_merge_allowed'));
        $this->assertSame('request_changes', data_get($payload, 'template.default_decision'));
        $this->assertContains('valid_signature_against_signable_payload_hash', data_get($payload, 'template.required_before_merge'));
        $this->assertContains('merge_action_template_does_not_merge', data_get($payload, 'template.merge_boundaries'));
        $this->assertContains('codex_review_merge_action_template_does_not_merge', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'template_hash'));
    }

    public function test_command_returns_codex_review_merge_action_template_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-action-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_action_template_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_explicit_signed_merge_action', data_get($payload, 'template.status'));
        $this->assertTrue(data_get($payload, 'template.explicit_merge_action_required'));
        $this->assertFalse(data_get($payload, 'template.signature_validated_by_this_template'));
        $this->assertFalse(data_get($payload, 'template.merge_allowed'));
        $this->assertFalse(data_get($payload, 'template.approval_granted'));
        $this->assertContains('selected_decision_is_approve_for_merge', data_get($payload, 'template.required_before_merge'));
        $this->assertContains('human_merge_confirmation', data_get($payload, 'template.required_inputs'));
        $this->assertContains('merge', data_get($payload, 'template.allowed_merge_decisions'));
        $this->assertContains('request_changes', data_get($payload, 'template.allowed_merge_decisions'));
        $this->assertContains('abort', data_get($payload, 'template.allowed_merge_decisions'));
    }

    public function test_command_human_output_lists_codex_review_merge_action_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-action-template' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Merge action status', $output);
        $this->assertStringContainsString('Explicit action required', $output);
        $this->assertStringContainsString('Template hash', $output);
        $this->assertStringContainsString('Codex review merge action template is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_preflight_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_preflight.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_preflight_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_preflight', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertGreaterThan(0, data_get($payload, 'preflight.blocking_count'));
        $this->assertContains('valid_signature_against_signable_payload_hash', data_get($payload, 'preflight.required_external_evidence_before_merge'));
        $this->assertContains('auto_merge_from_preflight', data_get($payload, 'preflight.still_forbidden'));
        $this->assertContains('codex_review_merge_preflight_does_not_merge', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'preflight_hash'));
    }

    public function test_command_returns_codex_review_merge_preflight_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_preflight_ready', data_get($payload, 'status'));
        $this->assertSame('ready_for_explicit_merge_action_review', data_get($payload, 'preflight.status'));
        $this->assertSame(0, data_get($payload, 'preflight.blocking_count'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertContains('prepare_separate_governed_merge_action', data_get($payload, 'preflight.next_allowed_actions'));
        $this->assertContains('signature_validation_by_preflight', data_get($payload, 'preflight.still_forbidden'));
        $this->assertArrayHasKey('merge_action_template_hash', data_get($payload, 'preflight.source_hashes'));
    }

    public function test_command_human_output_lists_codex_review_merge_preflight(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-preflight' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Preflight status', $output);
        $this->assertStringContainsString('Blocking count', $output);
        $this->assertStringContainsString('Preflight hash', $output);
        $this->assertStringContainsString('Codex review merge preflight is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_action_draft_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-action-draft' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_action_draft.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_action_draft_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_action_draft', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('request_changes', data_get($payload, 'draft.default_decision'));
        $this->assertContains('merge', data_get($payload, 'draft.allowed_decisions'));
        $this->assertContains('human_merge_confirmation', data_get($payload, 'draft.required_fields'));
        $this->assertContains('merge_action_draft_does_not_merge', data_get($payload, 'draft.explicit_non_authority'));
        $this->assertContains('codex_review_merge_action_draft_does_not_merge', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'draft_hash'));
    }

    public function test_command_returns_codex_review_merge_action_draft_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-action-draft' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_action_draft_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_signature_and_human_confirmation', data_get($payload, 'draft.status'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertContains('signature_must_be_validated_by_external_governed_actor', data_get($payload, 'draft.merge_action_guardrails'));
        $this->assertContains('valid_signature_against_signable_payload_hash', data_get($payload, 'draft.required_external_evidence_before_merge'));
        $this->assertArrayHasKey('merge_action_template_hash', data_get($payload, 'draft.source_hashes'));
    }

    public function test_command_human_output_lists_codex_review_merge_action_draft(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-action-draft' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Draft status', $output);
        $this->assertStringContainsString('Default decision', $output);
        $this->assertStringContainsString('Draft hash', $output);
        $this->assertStringContainsString('Codex review merge action draft is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_receipt_draft_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-receipt-draft' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_receipt_draft.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_receipt_draft_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_receipt_draft', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertTrue(data_get($payload, 'receipt.signature_required'));
        $this->assertFalse(data_get($payload, 'receipt.signature_present'));
        $this->assertSame('request_changes', data_get($payload, 'receipt.default_decision'));
        $this->assertContains('receipt_signature', data_get($payload, 'receipt.required_receipt_inputs'));
        $this->assertContains('merge_receipt_draft_does_not_merge', data_get($payload, 'receipt.non_authorizing_invariants'));
        $this->assertContains('codex_review_merge_receipt_draft_does_not_merge', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'receipt_hash'));
    }

    public function test_command_returns_codex_review_merge_receipt_draft_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-receipt-draft' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_receipt_draft_ready', data_get($payload, 'status'));
        $this->assertSame('unsigned_receipt_ready', data_get($payload, 'receipt.status'));
        $this->assertTrue(data_get($payload, 'receipt.signature_required'));
        $this->assertFalse(data_get($payload, 'receipt.signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt.decision_recorded'));
        $this->assertFalse(data_get($payload, 'receipt.merge_allowed'));
        $this->assertContains('principal_integrator', data_get($payload, 'receipt.required_signer_roles'));
        $this->assertContains('valid_signature_against_signable_payload_hash', data_get($payload, 'receipt.approval_preconditions'));
        $this->assertArrayHasKey('merge_action_template_hash', data_get($payload, 'receipt.source_hashes'));
    }

    public function test_command_human_output_lists_codex_review_merge_receipt_draft(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-receipt-draft' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Receipt status', $output);
        $this->assertStringContainsString('Signature required', $output);
        $this->assertStringContainsString('Receipt hash', $output);
        $this->assertStringContainsString('Codex review merge receipt draft is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_signature_request_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-signature-request' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_signature_request.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_signature_request_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_signature_request', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertTrue(data_get($payload, 'signature_request.signature_required'));
        $this->assertFalse(data_get($payload, 'signature_request.signature_present'));
        $this->assertSame('human_or_governed_merge_receipt_signature', data_get($payload, 'signable_payload.requested_signature_type'));
        $this->assertContains('merge_from_signature_request', data_get($payload, 'signable_payload.still_forbidden_after_signature_request'));
        $this->assertContains('codex_review_merge_signature_request_does_not_validate_signature', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'signable_payload_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'request_hash'));
    }

    public function test_command_returns_codex_review_merge_signature_request_pending_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-signature-request' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_signature_request_pending', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_signature', data_get($payload, 'signature_request.status'));
        $this->assertFalse(data_get($payload, 'signature_request.signature_valid'));
        $this->assertFalse(data_get($payload, 'signature_request.decision_recorded'));
        $this->assertFalse(data_get($payload, 'signature_request.merge_allowed'));
        $this->assertContains('principal_integrator', data_get($payload, 'signable_payload.required_signer_roles'));
        $this->assertContains('valid_signature_against_signable_payload_hash', data_get($payload, 'signable_payload.approval_preconditions'));
        $this->assertArrayHasKey('merge_action_template_hash', data_get($payload, 'signable_payload.source_hashes'));
    }

    public function test_command_human_output_lists_codex_review_merge_signature_request(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-signature-request' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Signature status', $output);
        $this->assertStringContainsString('Signature present', $output);
        $this->assertStringContainsString('Request hash', $output);
        $this->assertStringContainsString('Codex review merge signature request is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_signature_runbook_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-signature-runbook' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_signature_runbook.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_signature_runbook_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_signature_runbook', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertTrue(data_get($payload, 'runbook.signature_required'));
        $this->assertFalse(data_get($payload, 'runbook.signature_present'));
        $this->assertContains('external_signature_value', data_get($payload, 'runbook.required_external_inputs'));
        $this->assertContains('signature_validation_by_runbook', data_get($payload, 'runbook.still_forbidden_after_runbook'));
        $this->assertContains('codex_review_merge_post_signature_runbook_does_not_merge', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'runbook_hash'));
    }

    public function test_command_returns_codex_review_merge_post_signature_runbook_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-signature-runbook' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_signature_runbook_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_valid_signature_evidence', data_get($payload, 'runbook.status'));
        $this->assertFalse(data_get($payload, 'runbook.signature_valid'));
        $this->assertFalse(data_get($payload, 'runbook.approval_granted'));
        $this->assertFalse(data_get($payload, 'runbook.merge_allowed'));
        $this->assertContains('rerun_merge_preflight', data_get($payload, 'runbook.ordered_steps_after_external_signature'));
        $this->assertContains('fresh_gate_failure', data_get($payload, 'runbook.blocking_conditions'));
        $this->assertArrayHasKey('architecture_validate', data_get($payload, 'runbook.verification_commands'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_signature_runbook(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-signature-runbook' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Runbook status', $output);
        $this->assertStringContainsString('Signature required', $output);
        $this->assertStringContainsString('Runbook hash', $output);
        $this->assertStringContainsString('Codex review merge post-signature runbook is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_execution_checklist_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-execution-checklist' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_execution_checklist.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_execution_checklist_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_execution_checklist', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertTrue(data_get($payload, 'checklist.external_authorization_required'));
        $this->assertContains('merge_execution', data_get($payload, 'checklist.forbidden_until_future_authorizing_surface'));
        $this->assertContains('human_final_confirmation_present', data_get($payload, 'checklist.required_pre_execution_checks'));
        $this->assertContains('codex_review_merge_execution_checklist_does_not_merge', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'checklist_hash'));
    }

    public function test_command_returns_codex_review_merge_execution_checklist_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-execution-checklist' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_execution_checklist_ready', data_get($payload, 'status'));
        $this->assertSame('ready_for_future_authorizing_surface', data_get($payload, 'checklist.status'));
        $this->assertFalse(data_get($payload, 'checklist.signature_valid'));
        $this->assertFalse(data_get($payload, 'checklist.approval_granted'));
        $this->assertFalse(data_get($payload, 'checklist.merge_allowed'));
        $this->assertContains('fresh_gate_outputs_pass', data_get($payload, 'checklist.required_pre_execution_checks'));
        $this->assertContains('merge_operator', data_get($payload, 'checklist.future_authorizing_surface_must_record'));
        $this->assertArrayHasKey('reservation_status', data_get($payload, 'checklist.verification_commands'));
    }

    public function test_command_human_output_lists_codex_review_merge_execution_checklist(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-execution-checklist' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Checklist status', $output);
        $this->assertStringContainsString('External authorization required', $output);
        $this->assertStringContainsString('Checklist hash', $output);
        $this->assertStringContainsString('Codex review merge execution checklist is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_authorization_template_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-authorization-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_authorization_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_authorization_template_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_authorization_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('request_changes', data_get($payload, 'template.default_decision'));
        $this->assertContains('authorization_decision', data_get($payload, 'template.required_authorization_fields'));
        $this->assertContains('merge_from_template', data_get($payload, 'template.still_forbidden_here'));
        $this->assertContains('codex_review_merge_authorization_template_does_not_merge', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'template_hash'));
    }

    public function test_command_returns_codex_review_merge_authorization_template_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-authorization-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_authorization_template_ready', data_get($payload, 'status'));
        $this->assertSame('ready_for_external_authorization_values', data_get($payload, 'template.status'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertContains('merge', data_get($payload, 'template.allowed_decisions'));
        $this->assertContains('fresh_gate_outputs_pass', data_get($payload, 'template.required_preconditions'));
        $this->assertTrue(data_get($payload, 'template.future_authorizing_surface_contract.must_be_a_different_surface'));
        $this->assertSame('abort', data_get($payload, 'template.rejection_defaults.hash_mismatch'));
    }

    public function test_command_human_output_lists_codex_review_merge_authorization_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-authorization-template' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Template status', $output);
        $this->assertStringContainsString('Default decision', $output);
        $this->assertStringContainsString('Template hash', $output);
        $this->assertStringContainsString('Codex review merge authorization template is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_authorization_receipt_draft_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-authorization-receipt-draft' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_authorization_receipt_draft.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_authorization_receipt_draft_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_authorization_receipt_draft', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertTrue(data_get($payload, 'receipt.signature_required'));
        $this->assertFalse(data_get($payload, 'receipt.signature_present'));
        $this->assertContains('principal_integrator', data_get($payload, 'receipt.required_receipt_signers'));
        $this->assertContains('authorization_receipt_draft_does_not_merge', data_get($payload, 'receipt.non_authorizing_invariants'));
        $this->assertContains('codex_review_merge_authorization_receipt_draft_does_not_merge', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'receipt_hash'));
    }

    public function test_command_returns_codex_review_merge_authorization_receipt_draft_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-authorization-receipt-draft' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_authorization_receipt_draft_ready', data_get($payload, 'status'));
        $this->assertSame('unsigned_authorization_receipt_ready', data_get($payload, 'receipt.status'));
        $this->assertFalse(data_get($payload, 'receipt.signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt.decision_recorded'));
        $this->assertFalse(data_get($payload, 'receipt.merge_allowed'));
        $this->assertContains('merge', data_get($payload, 'receipt.allowed_decisions'));
        $this->assertContains('fresh_gate_outputs_pass', data_get($payload, 'receipt.required_preconditions'));
        $this->assertContains('authorization_template_hash', data_get($payload, 'receipt.must_record'));
    }

    public function test_command_human_output_lists_codex_review_merge_authorization_receipt_draft(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-authorization-receipt-draft' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Receipt status', $output);
        $this->assertStringContainsString('Signature required', $output);
        $this->assertStringContainsString('Receipt hash', $output);
        $this->assertStringContainsString('Codex review merge authorization receipt draft is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_authorization_signature_request_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-authorization-signature-request' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_authorization_signature_request.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_authorization_signature_request_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_authorization_signature_request', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertTrue(data_get($payload, 'signature_request.signature_required'));
        $this->assertFalse(data_get($payload, 'signature_request.signature_present'));
        $this->assertSame('human_or_governed_merge_authorization_receipt_signature', data_get($payload, 'signable_payload.requested_signature_type'));
        $this->assertContains('merge_from_authorization_signature_request', data_get($payload, 'signable_payload.still_forbidden_after_signature_request'));
        $this->assertContains('codex_review_merge_authorization_signature_request_does_not_validate_signature', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'signable_payload_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'request_hash'));
    }

    public function test_command_returns_codex_review_merge_authorization_signature_request_pending_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-authorization-signature-request' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_authorization_signature_request_pending', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_authorization_signature', data_get($payload, 'signature_request.status'));
        $this->assertFalse(data_get($payload, 'signature_request.signature_valid'));
        $this->assertFalse(data_get($payload, 'signature_request.decision_recorded'));
        $this->assertFalse(data_get($payload, 'signature_request.merge_allowed'));
        $this->assertContains('principal_integrator', data_get($payload, 'signable_payload.required_receipt_signers'));
        $this->assertContains('fresh_gate_outputs_pass', data_get($payload, 'signable_payload.required_preconditions'));
        $this->assertContains('authorization_template_hash', data_get($payload, 'signable_payload.must_record'));
    }

    public function test_command_human_output_lists_codex_review_merge_authorization_signature_request(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-authorization-signature-request' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Signature status', $output);
        $this->assertStringContainsString('Signature present', $output);
        $this->assertStringContainsString('Request hash', $output);
        $this->assertStringContainsString('Codex review merge authorization signature request is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_authorization_post_signature_runbook_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-authorization-post-signature-runbook' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_authorization_post_signature_runbook.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_authorization_post_signature_runbook_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_authorization_post_signature_runbook', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertTrue(data_get($payload, 'runbook.signature_required'));
        $this->assertFalse(data_get($payload, 'runbook.signature_present'));
        $this->assertContains('external_authorization_signature_value', data_get($payload, 'runbook.required_external_inputs'));
        $this->assertContains('merge_from_authorization_post_signature_runbook', data_get($payload, 'runbook.still_forbidden_after_runbook'));
        $this->assertContains('codex_review_merge_authorization_post_signature_runbook_does_not_validate_signature', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'runbook_hash'));
    }

    public function test_command_returns_codex_review_merge_authorization_post_signature_runbook_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-authorization-post-signature-runbook' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_authorization_post_signature_runbook_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_authorization_signature_evidence', data_get($payload, 'runbook.status'));
        $this->assertFalse(data_get($payload, 'runbook.signature_valid'));
        $this->assertFalse(data_get($payload, 'runbook.decision_recorded'));
        $this->assertFalse(data_get($payload, 'runbook.merge_allowed'));
        $this->assertContains('rerun_merge_preflight', data_get($payload, 'runbook.ordered_steps_after_external_authorization_signature'));
        $this->assertContains('fresh_gate_failure', data_get($payload, 'runbook.blocking_conditions'));
        $this->assertArrayHasKey('architecture_validate', data_get($payload, 'runbook.verification_commands'));
    }

    public function test_command_human_output_lists_codex_review_merge_authorization_post_signature_runbook(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-authorization-post-signature-runbook' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Runbook status', $output);
        $this->assertStringContainsString('Signature required', $output);
        $this->assertStringContainsString('Runbook hash', $output);
        $this->assertStringContainsString('Codex review merge authorization post-signature runbook is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_final_authorization_preflight_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-final-authorization-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_final_authorization_preflight.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_final_authorization_preflight_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_final_authorization_preflight', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'authorization_ready'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_authorization_post_signature_runbook', data_get($payload, 'preflight.status'));
        $this->assertContains('validated_authorization_signature_evidence', data_get($payload, 'preflight.required_external_evidence'));
        $this->assertContains('must_be_separate_command_or_endpoint', data_get($payload, 'preflight.future_authorizing_surface_requirements'));
        $this->assertContains('merge_from_final_authorization_preflight', data_get($payload, 'preflight.still_forbidden_after_preflight'));
        $this->assertContains('codex_review_merge_final_authorization_preflight_does_not_validate_signature', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'preflight_hash'));
    }

    public function test_command_returns_codex_review_merge_final_authorization_preflight_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-final-authorization-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_final_authorization_preflight_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_final_authorization_evidence', data_get($payload, 'preflight.status'));
        $this->assertFalse(data_get($payload, 'preflight.authorization_ready'));
        $this->assertFalse(data_get($payload, 'preflight.signature_valid'));
        $this->assertFalse(data_get($payload, 'preflight.merge_allowed'));
        $this->assertContains('fresh_tests_pass', data_get($payload, 'preflight.required_preflight_checks'));
        $this->assertContains('hot_voice_or_kernel_scope_touched', data_get($payload, 'preflight.blocking_conditions'));
        $this->assertContains('must_persist_authorization_receipt_append_only', data_get($payload, 'preflight.future_authorizing_surface_requirements'));
    }

    public function test_command_human_output_lists_codex_review_merge_final_authorization_preflight(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-final-authorization-preflight' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Preflight status', $output);
        $this->assertStringContainsString('Authorization ready', $output);
        $this->assertStringContainsString('Preflight hash', $output);
        $this->assertStringContainsString('Codex review merge final authorization preflight is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_authorizing_action_template_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-authorizing-action-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_authorizing_action_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_authorizing_action_template_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_authorizing_action_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'authorization_ready'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_final_authorization_preflight', data_get($payload, 'template.status'));
        $this->assertSame('request_changes', data_get($payload, 'template.default_decision'));
        $this->assertContains('external_authorization_signature_value', data_get($payload, 'template.required_inputs_for_future_authorizing_action'));
        $this->assertContains('signature_must_validate_against_source_authorization_signable_payload_hash', data_get($payload, 'template.required_action_validations'));
        $this->assertContains('authorizing_action_must_not_apply_patch_or_merge_directly', data_get($payload, 'template.future_execution_boundary'));
        $this->assertContains('merge_from_authorizing_action_template', data_get($payload, 'template.still_forbidden_by_template'));
        $this->assertContains('codex_review_merge_authorizing_action_template_does_not_record_decision', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'template_hash'));
    }

    public function test_command_returns_codex_review_merge_authorizing_action_template_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-authorizing-action-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_authorizing_action_template_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_authorizing_action_evidence', data_get($payload, 'template.status'));
        $this->assertFalse(data_get($payload, 'template.authorization_ready'));
        $this->assertFalse(data_get($payload, 'template.signature_valid'));
        $this->assertFalse(data_get($payload, 'template.decision_recorded'));
        $this->assertFalse(data_get($payload, 'template.merge_allowed'));
        $this->assertContains('selected_decision_must_equal_merge', data_get($payload, 'template.required_action_validations'));
        $this->assertContains('separate_executor_must_consume_final_merge_receipt', data_get($payload, 'template.future_execution_boundary'));
        $this->assertContains('authorized_at', data_get($payload, 'template.receipt_fields_to_persist_in_future'));
    }

    public function test_command_human_output_lists_codex_review_merge_authorizing_action_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-authorizing-action-template' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Template status', $output);
        $this->assertStringContainsString('Default decision', $output);
        $this->assertStringContainsString('Template hash', $output);
        $this->assertStringContainsString('Codex review merge authorizing action template is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_final_receipt_draft_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-final-receipt-draft' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_final_receipt_draft.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_final_receipt_draft_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_final_receipt_draft', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'authorization_ready'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'receipt_signed'));
        $this->assertSame('blocked_before_authorizing_action_template', data_get($payload, 'receipt.status'));
        $this->assertSame('request_changes', data_get($payload, 'receipt.default_decision'));
        $this->assertContains('validated_signature_hash', data_get($payload, 'receipt.drafted_authorization_fields'));
        $this->assertContains('external_authorization_signature_validated', data_get($payload, 'receipt.required_before_final_receipt_can_be_signed'));
        $this->assertContains('executor_must_consume_signed_final_merge_receipt', data_get($payload, 'receipt.future_executor_contract'));
        $this->assertContains('merge_from_final_receipt_draft', data_get($payload, 'receipt.still_forbidden_by_receipt_draft'));
        $this->assertContains('codex_review_merge_final_receipt_draft_does_not_validate_signature', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'receipt_hash'));
    }

    public function test_command_returns_codex_review_merge_final_receipt_draft_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-final-receipt-draft' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_final_receipt_draft_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_authorizing_action_receipt_evidence', data_get($payload, 'receipt.status'));
        $this->assertFalse(data_get($payload, 'receipt.receipt_signed'));
        $this->assertFalse(data_get($payload, 'receipt.signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt.decision_recorded'));
        $this->assertFalse(data_get($payload, 'receipt.merge_allowed'));
        $this->assertContains('selected_decision_equals_merge', data_get($payload, 'receipt.required_before_final_receipt_can_be_signed'));
        $this->assertContains('executor_must_not_run_without_signed_final_receipt', data_get($payload, 'receipt.future_executor_contract'));
        $this->assertContains('human_confirmation_hash', data_get($payload, 'receipt.drafted_authorization_fields'));
    }

    public function test_command_human_output_lists_codex_review_merge_final_receipt_draft(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-final-receipt-draft' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Receipt status', $output);
        $this->assertStringContainsString('Default decision', $output);
        $this->assertStringContainsString('Receipt hash', $output);
        $this->assertStringContainsString('Codex review merge final receipt draft is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_final_signature_request_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-final-signature-request' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_final_signature_request.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_final_signature_request_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_final_signature_request', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'authorization_ready'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'receipt_signed'));
        $this->assertSame('blocked_before_final_receipt_draft', data_get($payload, 'signature_request.status'));
        $this->assertTrue(data_get($payload, 'signature_request.signature_required'));
        $this->assertFalse(data_get($payload, 'signature_request.signature_present'));
        $this->assertSame('human_or_governed_final_merge_receipt_signature', data_get($payload, 'signable_payload.requested_signature_type'));
        $this->assertContains('executor_must_consume_signed_final_merge_receipt', data_get($payload, 'signable_payload.future_executor_contract'));
        $this->assertContains('signature_validation_by_final_signature_request', data_get($payload, 'signable_payload.still_forbidden_after_signature_request'));
        $this->assertContains('codex_review_merge_final_signature_request_does_not_accept_signature', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'signable_payload_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'request_hash'));
    }

    public function test_command_returns_codex_review_merge_final_signature_request_pending_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-final-signature-request' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_final_signature_request_pending', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_final_merge_receipt_signature', data_get($payload, 'signature_request.status'));
        $this->assertFalse(data_get($payload, 'signature_request.signature_present'));
        $this->assertFalse(data_get($payload, 'signature_request.signature_valid'));
        $this->assertFalse(data_get($payload, 'signature_request.receipt_signed'));
        $this->assertFalse(data_get($payload, 'signature_request.merge_allowed'));
        $this->assertContains('selected_decision_equals_merge', data_get($payload, 'signable_payload.required_before_final_receipt_can_be_signed'));
        $this->assertContains('human_confirmation_hash', data_get($payload, 'signable_payload.drafted_authorization_fields'));
        $this->assertContains('merge_from_final_signature_request', data_get($payload, 'signable_payload.still_forbidden_after_signature_request'));
    }

    public function test_command_human_output_lists_codex_review_merge_final_signature_request(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-final-signature-request' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Signature status', $output);
        $this->assertStringContainsString('Signature present', $output);
        $this->assertStringContainsString('Request hash', $output);
        $this->assertStringContainsString('Codex review merge final signature request is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_final_post_signature_runbook_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-final-post-signature-runbook' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_final_post_signature_runbook.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_final_post_signature_runbook_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_final_post_signature_runbook', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_signed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_final_signature_request', data_get($payload, 'runbook.status'));
        $this->assertTrue(data_get($payload, 'runbook.signature_required'));
        $this->assertFalse(data_get($payload, 'runbook.signature_present'));
        $this->assertContains('external_final_merge_receipt_signature_value', data_get($payload, 'runbook.required_external_inputs'));
        $this->assertContains('prepare_separate_signed_final_receipt_surface', data_get($payload, 'runbook.ordered_steps_after_external_final_signature'));
        $this->assertContains('receipt_signing_by_final_post_signature_runbook', data_get($payload, 'runbook.still_forbidden_after_runbook'));
        $this->assertContains('codex_review_merge_final_post_signature_runbook_does_not_sign_receipt', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'runbook_hash'));
    }

    public function test_command_returns_codex_review_merge_final_post_signature_runbook_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-final-post-signature-runbook' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_final_post_signature_runbook_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_final_signature_evidence', data_get($payload, 'runbook.status'));
        $this->assertFalse(data_get($payload, 'runbook.signature_valid'));
        $this->assertFalse(data_get($payload, 'runbook.receipt_signed'));
        $this->assertFalse(data_get($payload, 'runbook.merge_allowed'));
        $this->assertContains('confirm_selected_decision_equals_merge', data_get($payload, 'runbook.ordered_steps_after_external_final_signature'));
        $this->assertContains('selected_decision_is_not_merge', data_get($payload, 'runbook.blocking_conditions'));
        $this->assertContains('must_persist_signed_final_merge_receipt_append_only', data_get($payload, 'runbook.future_signed_receipt_surface_requirements'));
    }

    public function test_command_human_output_lists_codex_review_merge_final_post_signature_runbook(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-final-post-signature-runbook' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Runbook status', $output);
        $this->assertStringContainsString('Signature required', $output);
        $this->assertStringContainsString('Runbook hash', $output);
        $this->assertStringContainsString('Codex review merge final post-signature runbook is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_signed_final_receipt_template_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-signed-final-receipt-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_signed_final_receipt_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_signed_final_receipt_template_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_signed_final_receipt_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_signed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'executor_allowed'));
        $this->assertSame('blocked_before_final_post_signature_runbook', data_get($payload, 'template.status'));
        $this->assertContains('external_final_merge_receipt_signature_value', data_get($payload, 'template.required_external_evidence_for_future_signed_receipt'));
        $this->assertContains('signature_validates_against_final_signable_payload_hash', data_get($payload, 'template.required_validations_before_persisting_signed_receipt'));
        $this->assertContains('executor_consumes_signed_final_receipt_only', data_get($payload, 'template.future_executor_release_conditions'));
        $this->assertContains('receipt_persistence_by_signed_final_receipt_template', data_get($payload, 'template.still_forbidden_by_template'));
        $this->assertContains('codex_review_merge_signed_final_receipt_template_does_not_persist_receipt', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'template_hash'));
    }

    public function test_command_returns_codex_review_merge_signed_final_receipt_template_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-signed-final-receipt-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_signed_final_receipt_template_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_signed_final_receipt_evidence', data_get($payload, 'template.status'));
        $this->assertFalse(data_get($payload, 'template.signature_valid'));
        $this->assertFalse(data_get($payload, 'template.receipt_signed'));
        $this->assertFalse(data_get($payload, 'template.executor_allowed'));
        $this->assertContains('signed_final_receipt_id', data_get($payload, 'template.signed_receipt_fields_to_persist_in_future'));
        $this->assertContains('selected_decision_equals_merge', data_get($payload, 'template.required_validations_before_persisting_signed_receipt'));
        $this->assertContains('signed_final_receipt_persisted_append_only', data_get($payload, 'template.future_executor_release_conditions'));
    }

    public function test_command_human_output_lists_codex_review_merge_signed_final_receipt_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-signed-final-receipt-template' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Template status', $output);
        $this->assertStringContainsString('Receipt signed', $output);
        $this->assertStringContainsString('Template hash', $output);
        $this->assertStringContainsString('Codex review merge signed final receipt template is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_signed_final_receipt_preflight_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-signed-final-receipt-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_signed_final_receipt_preflight.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_signed_final_receipt_preflight_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_signed_final_receipt_preflight', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_signed'));
        $this->assertFalse(data_get($payload, 'executor_allowed'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_signed_final_receipt_template', data_get($payload, 'preflight.status'));
        $this->assertContains('external_signature_evidence_present', data_get($payload, 'preflight.required_preflight_checks'));
        $this->assertContains('missing_external_signature_evidence', data_get($payload, 'preflight.blocking_conditions'));
        $this->assertContains('persist_signed_final_receipt_append_only', data_get($payload, 'preflight.future_persistence_requirements'));
        $this->assertContains('executor_release_from_signed_final_receipt_preflight', data_get($payload, 'preflight.still_forbidden_after_preflight'));
        $this->assertContains('codex_review_merge_signed_final_receipt_preflight_does_not_persist_receipt', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'preflight_hash'));
    }

    public function test_command_returns_codex_review_merge_signed_final_receipt_preflight_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-signed-final-receipt-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_signed_final_receipt_preflight_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_signed_final_receipt_inputs', data_get($payload, 'preflight.status'));
        $this->assertFalse(data_get($payload, 'preflight.signature_valid'));
        $this->assertFalse(data_get($payload, 'preflight.receipt_signed'));
        $this->assertFalse(data_get($payload, 'preflight.executor_allowed'));
        $this->assertContains('validated_selected_decision_equals_merge', data_get($payload, 'preflight.required_preflight_checks'));
        $this->assertContains('hot_voice_or_kernel_scope_touched', data_get($payload, 'preflight.blocking_conditions'));
        $this->assertContains('emit_executor_release_preflight_after_persistence', data_get($payload, 'preflight.future_persistence_requirements'));
    }

    public function test_command_human_output_lists_codex_review_merge_signed_final_receipt_preflight(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-signed-final-receipt-preflight' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Preflight status', $output);
        $this->assertStringContainsString('Executor allowed', $output);
        $this->assertStringContainsString('Preflight hash', $output);
        $this->assertStringContainsString('Codex review merge signed final receipt preflight is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_signed_final_receipt_persistence_template_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-signed-final-receipt-persistence-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_signed_final_receipt_persistence_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_signed_final_receipt_persistence_template_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_signed_final_receipt_persistence_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_signed'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertFalse(data_get($payload, 'executor_allowed'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_signed_final_receipt_preflight', data_get($payload, 'template.status'));
        $this->assertContains('append_only_store_available', data_get($payload, 'template.required_persistence_validations'));
        $this->assertContains('signed_final_receipt_persisted_append_only', data_get($payload, 'template.future_executor_release_requirements'));
        $this->assertContains('receipt_persistence_by_signed_final_receipt_persistence_template', data_get($payload, 'template.still_forbidden_by_template'));
        $this->assertContains('codex_review_merge_signed_final_receipt_persistence_template_does_not_persist_receipt', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'template_hash'));
    }

    public function test_command_returns_codex_review_merge_signed_final_receipt_persistence_template_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-signed-final-receipt-persistence-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_signed_final_receipt_persistence_template_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_signed_receipt_persistence_evidence', data_get($payload, 'template.status'));
        $this->assertFalse(data_get($payload, 'template.signature_valid'));
        $this->assertFalse(data_get($payload, 'template.receipt_signed'));
        $this->assertFalse(data_get($payload, 'template.receipt_persisted'));
        $this->assertFalse(data_get($payload, 'template.executor_allowed'));
        $this->assertContains('signed_final_receipt_id', data_get($payload, 'template.append_only_persistence_fields'));
        $this->assertContains('source_hashes_match_preflight', data_get($payload, 'template.required_persistence_validations'));
        $this->assertContains('executor_release_preflight_ready', data_get($payload, 'template.future_executor_release_requirements'));
    }

    public function test_command_human_output_lists_codex_review_merge_signed_final_receipt_persistence_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-signed-final-receipt-persistence-template' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Template status', $output);
        $this->assertStringContainsString('Receipt persisted', $output);
        $this->assertStringContainsString('Template hash', $output);
        $this->assertStringContainsString('Codex review merge signed final receipt persistence template is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_executor_release_preflight_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-executor-release-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_executor_release_preflight.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_executor_release_preflight_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_executor_release_preflight', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertFalse(data_get($payload, 'executor_allowed'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_signed_final_receipt_persistence_template', data_get($payload, 'preflight.status'));
        $this->assertContains('persisted_signed_final_receipt_hash_present', data_get($payload, 'preflight.required_release_checks'));
        $this->assertContains('hot_voice_or_kernel_scope_touched', data_get($payload, 'preflight.blocking_conditions'));
        $this->assertContains('executor_consumes_persisted_signed_final_receipt_only', data_get($payload, 'preflight.future_executor_contract_requirements'));
        $this->assertContains('executor_release_by_executor_release_preflight', data_get($payload, 'preflight.still_forbidden_by_preflight'));
        $this->assertContains('codex_review_merge_executor_release_preflight_does_not_release_executor', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'preflight_hash'));
    }

    public function test_command_returns_codex_review_merge_executor_release_preflight_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-executor-release-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_executor_release_preflight_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_persisted_signed_final_receipt_inputs', data_get($payload, 'preflight.status'));
        $this->assertFalse(data_get($payload, 'preflight.receipt_persisted'));
        $this->assertFalse(data_get($payload, 'preflight.executor_allowed'));
        $this->assertContains('persisted_receipt_sources_match_template_hashes', data_get($payload, 'preflight.required_release_checks'));
        $this->assertContains('executor_revalidates_final_diff_before_patch', data_get($payload, 'preflight.future_executor_contract_requirements'));
        $this->assertContains('patch_execution_by_executor_release_preflight', data_get($payload, 'preflight.still_forbidden_by_preflight'));
    }

    public function test_command_human_output_lists_codex_review_merge_executor_release_preflight(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-executor-release-preflight' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Preflight status', $output);
        $this->assertStringContainsString('Receipt persisted', $output);
        $this->assertStringContainsString('Executor allowed', $output);
        $this->assertStringContainsString('Preflight hash', $output);
        $this->assertStringContainsString('Codex review merge executor release preflight is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_executor_contract_template_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-executor-contract-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_executor_contract_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_executor_contract_template_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_executor_contract_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'patch_execution_allowed'));
        $this->assertFalse(data_get($payload, 'executor_allowed'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_executor_release_preflight', data_get($payload, 'template.status'));
        $this->assertContains('executor_release_authority_hash', data_get($payload, 'template.required_executor_inputs'));
        $this->assertContains('hot_scope_check_still_clean', data_get($payload, 'template.executor_must_revalidate'));
        $this->assertContains('stop_on_any_mismatch', data_get($payload, 'template.allowed_future_executor_actions'));
        $this->assertContains('expand_scope_beyond_signed_receipt', data_get($payload, 'template.forbidden_future_executor_actions'));
        $this->assertContains('patch_execution_by_executor_contract_template', data_get($payload, 'template.still_forbidden_by_template'));
        $this->assertContains('codex_review_merge_executor_contract_template_does_not_execute_patch', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'template_hash'));
    }

    public function test_command_returns_codex_review_merge_executor_contract_template_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-executor-contract-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_executor_contract_template_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_executor_release_authority', data_get($payload, 'template.status'));
        $this->assertFalse(data_get($payload, 'template.executor_allowed'));
        $this->assertFalse(data_get($payload, 'template.patch_execution_allowed'));
        $this->assertContains('persisted_signed_final_receipt_hash_matches_contract', data_get($payload, 'template.executor_must_revalidate'));
        $this->assertContains('apply_only_receipt_bound_patch_set', data_get($payload, 'template.allowed_future_executor_actions'));
        $this->assertContains('source_executor_contract_hash', data_get($payload, 'template.required_execution_receipt_fields'));
    }

    public function test_command_human_output_lists_codex_review_merge_executor_contract_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-executor-contract-template' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Template status', $output);
        $this->assertStringContainsString('Patch execution allowed', $output);
        $this->assertStringContainsString('Merge allowed', $output);
        $this->assertStringContainsString('Template hash', $output);
        $this->assertStringContainsString('Codex review merge executor contract template is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_execution_receipt_template_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-execution-receipt-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_execution_receipt_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_execution_receipt_template_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_execution_receipt_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'patch_execution_allowed'));
        $this->assertFalse(data_get($payload, 'patch_executed'));
        $this->assertFalse(data_get($payload, 'execution_recorded'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_executor_contract_template', data_get($payload, 'template.status'));
        $this->assertContains('applied_patch_hash', data_get($payload, 'template.required_execution_evidence'));
        $this->assertContains('files_changed_subset_of_signed_receipt_scope', data_get($payload, 'template.required_post_execution_checks'));
        $this->assertContains('patch_hash_mismatch', data_get($payload, 'template.blocking_conditions'));
        $this->assertContains('execution_receipt_persisted_append_only', data_get($payload, 'template.future_merge_preflight_requirements'));
        $this->assertContains('patch_execution_by_execution_receipt_template', data_get($payload, 'template.still_forbidden_by_template'));
        $this->assertContains('codex_review_merge_execution_receipt_template_does_not_execute_patch', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'template_hash'));
    }

    public function test_command_returns_codex_review_merge_execution_receipt_template_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-execution-receipt-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_execution_receipt_template_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_future_executor_execution_evidence', data_get($payload, 'template.status'));
        $this->assertFalse(data_get($payload, 'template.patch_executed'));
        $this->assertFalse(data_get($payload, 'template.execution_recorded'));
        $this->assertContains('post_execution_diff_hash', data_get($payload, 'template.required_execution_evidence'));
        $this->assertContains('architecture_validate_passed_after_execution', data_get($payload, 'template.required_post_execution_checks'));
        $this->assertContains('merge_executor_uses_execution_receipt_only', data_get($payload, 'template.future_merge_preflight_requirements'));
    }

    public function test_command_human_output_lists_codex_review_merge_execution_receipt_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-execution-receipt-template' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Template status', $output);
        $this->assertStringContainsString('Patch executed', $output);
        $this->assertStringContainsString('Merge allowed', $output);
        $this->assertStringContainsString('Template hash', $output);
        $this->assertStringContainsString('Codex review merge execution receipt template is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_preflight_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_preflight.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_preflight_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_preflight', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'execution_receipt_persisted'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_execution_receipt_template', data_get($payload, 'preflight.status'));
        $this->assertContains('persisted_execution_receipt_hash_verified', data_get($payload, 'preflight.required_preflight_checks'));
        $this->assertContains('post_execution_gate_failure', data_get($payload, 'preflight.blocking_conditions'));
        $this->assertContains('merge_action_consumes_persisted_execution_receipt_only', data_get($payload, 'preflight.future_merge_action_requirements'));
        $this->assertContains('merge_from_post_execution_preflight', data_get($payload, 'preflight.still_forbidden_by_preflight'));
        $this->assertContains('codex_review_merge_post_execution_preflight_does_not_merge', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'preflight_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_preflight_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_preflight_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_persisted_execution_receipt_inputs', data_get($payload, 'preflight.status'));
        $this->assertFalse(data_get($payload, 'preflight.execution_receipt_persisted'));
        $this->assertFalse(data_get($payload, 'preflight.merge_allowed'));
        $this->assertContains('post_execution_diff_matches_receipt', data_get($payload, 'preflight.required_preflight_checks'));
        $this->assertContains('missing_human_post_execution_confirmation', data_get($payload, 'preflight.blocking_conditions'));
        $this->assertContains('merge_action_emits_final_merge_receipt', data_get($payload, 'preflight.future_merge_action_requirements'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_preflight(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-preflight' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Preflight status', $output);
        $this->assertStringContainsString('Execution receipt persisted', $output);
        $this->assertStringContainsString('Merge allowed', $output);
        $this->assertStringContainsString('Preflight hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution preflight is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_template_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_template_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_post_execution_preflight', data_get($payload, 'template.status'));
        $this->assertSame('do_not_merge', data_get($payload, 'template.default_decision'));
        $this->assertContains('merge_candidate_hash_matches_post_execution_diff', data_get($payload, 'template.required_action_validations'));
        $this->assertContains('emit_unsigned_final_merge_action_receipt', data_get($payload, 'template.allowed_future_action_steps'));
        $this->assertContains('merge_with_unreviewed_diff', data_get($payload, 'template.forbidden_future_action_steps'));
        $this->assertContains('merge_from_post_execution_action_template', data_get($payload, 'template.still_forbidden_by_template'));
        $this->assertContains('codex_review_merge_post_execution_action_template_does_not_merge', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'template_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_template_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_template_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_merge_action_authority', data_get($payload, 'template.status'));
        $this->assertFalse(data_get($payload, 'template.approval_granted'));
        $this->assertFalse(data_get($payload, 'template.merge_allowed'));
        $this->assertContains('no_hot_scope_drift_since_preflight', data_get($payload, 'template.required_action_validations'));
        $this->assertContains('request_final_merge_confirmation', data_get($payload, 'template.allowed_future_action_steps'));
        $this->assertContains('final_merge_action_signed_receipt', data_get($payload, 'template.future_final_merge_receipt_requirements'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-template' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Template status', $output);
        $this->assertStringContainsString('Default decision', $output);
        $this->assertStringContainsString('Merge allowed', $output);
        $this->assertStringContainsString('Template hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action template is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_receipt_draft_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-receipt-draft' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_receipt_draft.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_receipt_draft_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_receipt_draft', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'receipt_signed'));
        $this->assertSame('blocked_before_post_execution_action_template', data_get($payload, 'receipt.status'));
        $this->assertSame('do_not_merge', data_get($payload, 'receipt.default_decision'));
        $this->assertTrue(data_get($payload, 'receipt.signature_required'));
        $this->assertContains('merge_candidate_hash', data_get($payload, 'receipt.decision_fields'));
        $this->assertContains('selected_decision', data_get($payload, 'receipt.required_signable_payload_fields'));
        $this->assertContains('external_final_merge_action_signature_value', data_get($payload, 'receipt.future_signature_requirements'));
        $this->assertContains('merge_from_post_execution_action_receipt_draft', data_get($payload, 'receipt.still_forbidden_by_receipt_draft'));
        $this->assertContains('codex_review_merge_post_execution_action_receipt_draft_does_not_merge', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'receipt_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_receipt_draft_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-receipt-draft' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_receipt_draft_ready', data_get($payload, 'status'));
        $this->assertSame('unsigned_waiting_for_external_final_merge_confirmation', data_get($payload, 'receipt.status'));
        $this->assertFalse(data_get($payload, 'receipt.approval_granted'));
        $this->assertFalse(data_get($payload, 'receipt.merge_allowed'));
        $this->assertFalse(data_get($payload, 'receipt.receipt_signed'));
        $this->assertContains('human_post_execution_confirmation_hash', data_get($payload, 'receipt.required_signable_payload_fields'));
        $this->assertContains('signed_final_merge_action_receipt_persisted_append_only', data_get($payload, 'receipt.future_signature_requirements'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_receipt_draft(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-receipt-draft' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Receipt status', $output);
        $this->assertStringContainsString('Default decision', $output);
        $this->assertStringContainsString('Signature required', $output);
        $this->assertStringContainsString('Receipt hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action receipt draft is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signature_request_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signature-request' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signature_request.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signature_request_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signature_request', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertSame('blocked_before_post_execution_action_receipt_draft', data_get($payload, 'signature_request.status'));
        $this->assertTrue(data_get($payload, 'signature_request.signature_required'));
        $this->assertFalse(data_get($payload, 'signature_request.signature_present'));
        $this->assertContains('human_or_governed_post_execution_merge_action_receipt_signature', [data_get($payload, 'signable_payload.requested_signature_type')]);
        $this->assertContains('signed_final_merge_action_receipt_persisted_append_only', data_get($payload, 'signable_payload.required_external_signature_fields'));
        $this->assertContains('merge_from_post_execution_action_signature_request', data_get($payload, 'signable_payload.still_forbidden_after_signature_request'));
        $this->assertContains('codex_review_merge_post_execution_action_signature_request_does_not_validate_signature', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'signable_payload_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'request_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signature_request_pending_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signature-request' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signature_request_pending', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_final_merge_action_signature', data_get($payload, 'signature_request.status'));
        $this->assertFalse(data_get($payload, 'signature_request.signature_valid'));
        $this->assertFalse(data_get($payload, 'signature_request.approval_granted'));
        $this->assertFalse(data_get($payload, 'signature_request.merge_allowed'));
        $this->assertFalse(data_get($payload, 'signature_request.receipt_persisted'));
        $this->assertContains('merge_candidate_hash', data_get($payload, 'signable_payload.decision_fields'));
        $this->assertContains('selected_decision', data_get($payload, 'signable_payload.required_signable_payload_fields'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signature_request(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signature-request' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Signature status', $output);
        $this->assertStringContainsString('Signature present', $output);
        $this->assertStringContainsString('Signable hash', $output);
        $this->assertStringContainsString('Request hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signature request is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_post_signature_runbook_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-post-signature-runbook' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_post_signature_runbook.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_post_signature_runbook_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_post_signature_runbook', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertSame('blocked_before_post_execution_action_signature_request', data_get($payload, 'runbook.status'));
        $this->assertTrue(data_get($payload, 'runbook.signature_required'));
        $this->assertSame(7, data_get($payload, 'runbook.step_count'));
        $this->assertContains('external_final_merge_action_signature_value', data_get($payload, 'runbook.required_external_inputs'));
        $this->assertContains('stop_before_signature_acceptance_or_merge', data_get($payload, 'runbook.ordered_steps'));
        $this->assertContains('merge_from_post_execution_action_post_signature_runbook', data_get($payload, 'runbook.still_forbidden_by_runbook'));
        $this->assertContains('codex_review_merge_post_execution_action_post_signature_runbook_does_not_persist_receipt', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'runbook_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_post_signature_runbook_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-post-signature-runbook' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_post_signature_runbook_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_final_merge_action_signature_evidence', data_get($payload, 'runbook.status'));
        $this->assertFalse(data_get($payload, 'runbook.signature_valid'));
        $this->assertFalse(data_get($payload, 'runbook.approval_granted'));
        $this->assertFalse(data_get($payload, 'runbook.merge_allowed'));
        $this->assertFalse(data_get($payload, 'runbook.receipt_persisted'));
        $this->assertContains('signed_final_merge_action_receipt_persistence_event_hash', data_get($payload, 'runbook.required_external_inputs'));
        $this->assertContains('no_hot_scope_drift_since_signature_request', data_get($payload, 'runbook.future_validator_must_check'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_post_signature_runbook(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-post-signature-runbook' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Runbook status', $output);
        $this->assertStringContainsString('Signature required', $output);
        $this->assertStringContainsString('Step count', $output);
        $this->assertStringContainsString('Runbook hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action post-signature runbook is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_template_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_template_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertSame('blocked_before_post_execution_action_post_signature_runbook', data_get($payload, 'template.status'));
        $this->assertContains('signed_action_receipt_id', data_get($payload, 'template.signed_receipt_fields_to_persist_in_future'));
        $this->assertContains('signature_validates_against_action_signable_payload_hash', data_get($payload, 'template.required_validations_before_persisting_signed_receipt'));
        $this->assertContains('merge_surface_consumes_signed_action_receipt_only', data_get($payload, 'template.future_merge_surface_release_conditions'));
        $this->assertContains('merge_from_post_execution_action_signed_receipt_template', data_get($payload, 'template.still_forbidden_by_template'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_template_does_not_persist_receipt', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'template_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_template_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_template_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_signed_action_receipt_evidence', data_get($payload, 'template.status'));
        $this->assertFalse(data_get($payload, 'template.signature_valid'));
        $this->assertFalse(data_get($payload, 'template.approval_granted'));
        $this->assertFalse(data_get($payload, 'template.merge_allowed'));
        $this->assertFalse(data_get($payload, 'template.receipt_persisted'));
        $this->assertContains('validated_action_receipt_hash', data_get($payload, 'template.required_external_evidence_for_future_signed_receipt'));
        $this->assertContains('human_post_execution_confirmation_hash', data_get($payload, 'template.signed_receipt_fields_to_persist_in_future'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-template' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Template status', $output);
        $this->assertStringContainsString('Receipt persisted', $output);
        $this->assertStringContainsString('Merge allowed', $output);
        $this->assertStringContainsString('Template hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt template is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_preflight_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_preflight.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_preflight_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_preflight', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertSame('blocked_before_signed_action_receipt_template', data_get($payload, 'preflight.status'));
        $this->assertSame(14, data_get($payload, 'preflight.blocking_count'));
        $this->assertContains('missing_external_final_merge_action_signature_value', data_get($payload, 'preflight.blocking_conditions'));
        $this->assertContains('hot_scope_drift_since_action_signature_request', data_get($payload, 'preflight.blocking_conditions'));
        $this->assertContains('merge_from_post_execution_action_signed_receipt_preflight', data_get($payload, 'preflight.still_forbidden_by_preflight'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_preflight_does_not_persist_receipt', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'preflight_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_preflight_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_preflight_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_signed_action_receipt_evidence', data_get($payload, 'preflight.status'));
        $this->assertFalse(data_get($payload, 'preflight.signature_valid'));
        $this->assertFalse(data_get($payload, 'preflight.approval_granted'));
        $this->assertFalse(data_get($payload, 'preflight.merge_allowed'));
        $this->assertFalse(data_get($payload, 'preflight.receipt_persisted'));
        $this->assertContains('signed_action_receipt_id', data_get($payload, 'preflight.required_future_persisted_fields'));
        $this->assertContains('merge_surface_consumes_signed_action_receipt_only', data_get($payload, 'preflight.future_merge_surface_release_conditions'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_preflight(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-preflight' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Preflight status', $output);
        $this->assertStringContainsString('Blocking count', $output);
        $this->assertStringContainsString('Receipt persisted', $output);
        $this->assertStringContainsString('Preflight hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt preflight is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_template_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_template_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertSame('blocked_before_signed_action_receipt_preflight', data_get($payload, 'template.status'));
        $this->assertSame('CODEX_REVIEW_MERGE_POST_EXECUTION_ACTION_SIGNED_RECEIPT_PERSISTED', data_get($payload, 'template.future_append_only_event_type'));
        $this->assertContains('append_only_event_hash', data_get($payload, 'template.future_verification_outputs'));
        $this->assertContains('ledger_write_by_post_execution_action_signed_receipt_persistence_template', data_get($payload, 'template.still_forbidden_by_template'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_write_ledger', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'template_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_template_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_template_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_future_append_only_persistence_surface', data_get($payload, 'template.status'));
        $this->assertFalse(data_get($payload, 'template.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'template.signature_valid'));
        $this->assertFalse(data_get($payload, 'template.approval_granted'));
        $this->assertFalse(data_get($payload, 'template.merge_allowed'));
        $this->assertFalse(data_get($payload, 'template.receipt_persisted'));
        $this->assertContains('signed_action_receipt_hash', data_get($payload, 'template.future_append_only_event_fields'));
        $this->assertContains('source_hashes_match_preflight', data_get($payload, 'template.required_pre_persistence_checks'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-template' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Template status', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Receipt persisted', $output);
        $this->assertStringContainsString('Template hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence template is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-receipt-draft' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_receipt_draft_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertFalse(data_get($payload, 'receipt_signed'));
        $this->assertSame('blocked_before_signed_action_receipt_persistence_template', data_get($payload, 'receipt.status'));
        $this->assertSame('CODEX_REVIEW_MERGE_POST_EXECUTION_ACTION_SIGNED_RECEIPT_PERSISTED', data_get($payload, 'receipt.future_append_only_event_type'));
        $this->assertContains('append_only_event_hash', data_get($payload, 'receipt.required_receipt_evidence'));
        $this->assertContains('signed_action_receipt_hash', data_get($payload, 'receipt.required_persistence_fields'));
        $this->assertContains('ledger_write_by_post_execution_action_signed_receipt_persistence_receipt_draft', data_get($payload, 'receipt.still_forbidden_by_receipt_draft'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_write_ledger', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'receipt_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-receipt-draft' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_receipt_draft_ready', data_get($payload, 'status'));
        $this->assertSame('unsigned_waiting_for_external_persistence_evidence', data_get($payload, 'receipt.status'));
        $this->assertFalse(data_get($payload, 'receipt.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'receipt.signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt.approval_granted'));
        $this->assertFalse(data_get($payload, 'receipt.merge_allowed'));
        $this->assertFalse(data_get($payload, 'receipt.receipt_persisted'));
        $this->assertContains('source_hash_match_report', data_get($payload, 'receipt.required_receipt_evidence'));
        $this->assertContains('hot_scope_still_clean', data_get($payload, 'receipt.required_pre_persistence_checks'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-receipt-draft' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Receipt status', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Receipt persisted', $output);
        $this->assertStringContainsString('Receipt hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence receipt draft is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_preflight_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_preflight.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_preflight_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_preflight', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertFalse(data_get($payload, 'receipt_signed'));
        $this->assertSame('blocked_before_signed_action_receipt_persistence_receipt_draft', data_get($payload, 'preflight.status'));
        $this->assertSame(1, data_get($payload, 'preflight.blocking_count'));
        $this->assertContains('signed_action_receipt_persistence_receipt_draft_not_ready', data_get($payload, 'preflight.blocking_conditions'));
        $this->assertContains('ledger_write_by_post_execution_action_signed_receipt_persistence_preflight', data_get($payload, 'preflight.still_forbidden_by_preflight'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_write_ledger', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'preflight_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_preflight_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_preflight_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_append_only_persistence_evidence', data_get($payload, 'preflight.status'));
        $this->assertSame(10, data_get($payload, 'preflight.blocking_count'));
        $this->assertFalse(data_get($payload, 'preflight.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'preflight.signature_valid'));
        $this->assertFalse(data_get($payload, 'preflight.approval_granted'));
        $this->assertFalse(data_get($payload, 'preflight.merge_allowed'));
        $this->assertFalse(data_get($payload, 'preflight.receipt_persisted'));
        $this->assertContains('external_append_only_event_hash_missing', data_get($payload, 'preflight.blocking_conditions'));
        $this->assertContains('persistence_surface_allows_append_only_write_only', data_get($payload, 'preflight.release_conditions_for_future_persistence_surface'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_preflight(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-preflight' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Preflight status', $output);
        $this->assertStringContainsString('Blocking count', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Preflight hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence preflight is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-post-preflight-runbook' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertSame('blocked_before_signed_action_receipt_persistence_preflight', data_get($payload, 'runbook.status'));
        $this->assertSame(6, data_get($payload, 'runbook.step_count'));
        $this->assertContains('future_writer_surface_separately_authorized', data_get($payload, 'runbook.exit_conditions'));
        $this->assertContains('ledger_write_by_post_execution_action_signed_receipt_persistence_post_preflight_runbook', data_get($payload, 'runbook.still_forbidden_by_runbook'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_write_ledger', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'runbook_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-post-preflight-runbook' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_persistence_evidence_collection', data_get($payload, 'runbook.status'));
        $this->assertSame(6, data_get($payload, 'runbook.step_count'));
        $this->assertFalse(data_get($payload, 'runbook.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'runbook.approval_granted'));
        $this->assertFalse(data_get($payload, 'runbook.merge_allowed'));
        $this->assertFalse(data_get($payload, 'runbook.receipt_persisted'));
        $this->assertContains('external_append_only_event_hash_missing', data_get($payload, 'runbook.preflight_blocking_conditions'));
        $this->assertSame('step_06_prepare_future_append_only_write', data_get($payload, 'runbook.steps.5.id'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-post-preflight-runbook' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Runbook status', $output);
        $this->assertStringContainsString('Step count', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Runbook hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence post-preflight runbook is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-append-only-event-payload-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertSame('blocked_before_signed_action_receipt_persistence_post_preflight_runbook', data_get($payload, 'payload.status'));
        $this->assertSame('CODEX_REVIEW_MERGE_POST_EXECUTION_ACTION_SIGNED_RECEIPT_PERSISTED', data_get($payload, 'payload.event_type'));
        $this->assertContains('human_persistence_confirmation_hash', data_get($payload, 'payload.required_payload_fields'));
        $this->assertContains('future_writer_surface_separately_authorized', data_get($payload, 'payload.required_before_write'));
        $this->assertContains('ledger_write_by_post_execution_action_signed_receipt_persistence_append_only_event_payload_template', data_get($payload, 'payload.still_forbidden_by_payload_template'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_write_ledger', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'payload_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-append-only-event-payload-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_future_writer_surface_authorization', data_get($payload, 'payload.status'));
        $this->assertFalse(data_get($payload, 'payload.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'payload.approval_granted'));
        $this->assertFalse(data_get($payload, 'payload.merge_allowed'));
        $this->assertFalse(data_get($payload, 'payload.receipt_persisted'));
        $this->assertContains('payload_hash_recomputed_by_writer', data_get($payload, 'payload.required_before_write'));
        $this->assertNull(data_get($payload, 'payload.field_values.append_only_event_hash'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-append-only-event-payload-template' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Payload status', $output);
        $this->assertStringContainsString('Event type', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Payload hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence append-only event payload template is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_preflight_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertSame('blocked_before_append_only_event_payload_template', data_get($payload, 'writer_preflight.status'));
        $this->assertSame(1, data_get($payload, 'writer_preflight.blocking_count'));
        $this->assertContains('append_only_event_payload_template_not_ready', data_get($payload, 'writer_preflight.blocking_conditions'));
        $this->assertContains('append_only_ledger_write_only', data_get($payload, 'writer_preflight.writer_contract_required_capabilities'));
        $this->assertContains('ledger_write_by_post_execution_action_signed_receipt_persistence_writer_preflight', data_get($payload, 'writer_preflight.still_forbidden_by_writer_preflight'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_write_ledger', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'writer_preflight_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_preflight_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_future_writer_surface_authorization', data_get($payload, 'writer_preflight.status'));
        $this->assertSame(12, data_get($payload, 'writer_preflight.blocking_count'));
        $this->assertFalse(data_get($payload, 'writer_preflight.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'writer_preflight.approval_granted'));
        $this->assertFalse(data_get($payload, 'writer_preflight.merge_allowed'));
        $this->assertFalse(data_get($payload, 'writer_preflight.receipt_persisted'));
        $this->assertContains('future_writer_surface_not_separately_authorized', data_get($payload, 'writer_preflight.blocking_conditions'));
        $this->assertContains('writer_preflight_hash_bound_to_writer_contract', data_get($payload, 'writer_preflight.future_writer_release_conditions'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-preflight' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Writer preflight status', $output);
        $this->assertStringContainsString('Blocking count', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Writer preflight hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence writer preflight is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-contract-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_contract_template_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertSame('blocked_before_persistence_writer_preflight', data_get($payload, 'contract.status'));
        $this->assertSame('CODEX_REVIEW_MERGE_POST_EXECUTION_ACTION_SIGNED_RECEIPT_PERSISTED', data_get($payload, 'contract.event_type'));
        $this->assertContains('writer_contract_hash_bound_to_implementation', data_get($payload, 'contract.required_pre_write_checks'));
        $this->assertContains('merge_execution', data_get($payload, 'contract.implementation_must_not_include'));
        $this->assertContains('ledger_write_by_post_execution_action_signed_receipt_persistence_writer_contract_template', data_get($payload, 'contract.still_forbidden_by_contract_template'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_write_ledger', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'contract_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-contract-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_contract_template_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_future_writer_implementation', data_get($payload, 'contract.status'));
        $this->assertSame(7, data_get($payload, 'contract.capability_count'));
        $this->assertFalse(data_get($payload, 'contract.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'contract.approval_granted'));
        $this->assertFalse(data_get($payload, 'contract.merge_allowed'));
        $this->assertFalse(data_get($payload, 'contract.receipt_persisted'));
        $this->assertContains('append_only_ledger_write_only', data_get($payload, 'contract.required_capabilities'));
        $this->assertContains('no_dispatch_authority', data_get($payload, 'contract.required_capabilities'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-contract-template' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Contract status', $output);
        $this->assertStringContainsString('Capability count', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Contract hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence writer contract template is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-implementation-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_persistence_writer_contract_template', data_get($payload, 'implementation_preflight.status'));
        $this->assertSame(1, data_get($payload, 'implementation_preflight.blocking_count'));
        $this->assertContains('writer_contract_template_not_ready', data_get($payload, 'implementation_preflight.blocking_conditions'));
        $this->assertContains('future:app/Services/Ai/SelfConstruction/CodexReviewMergePostExecutionActionSignedReceiptPersistenceWriter.php', data_get($payload, 'implementation_preflight.required_implementation_files'));
        $this->assertContains('writer_recomputes_payload_hash', data_get($payload, 'implementation_preflight.required_implementation_tests'));
        $this->assertContains('writer_file_creation_by_post_execution_action_signed_receipt_persistence_writer_implementation_preflight', data_get($payload, 'implementation_preflight.still_forbidden_by_implementation_preflight'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_create_writer_file', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'implementation_preflight_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-implementation-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_future_writer_implementation_patch', data_get($payload, 'implementation_preflight.status'));
        $this->assertSame(12, data_get($payload, 'implementation_preflight.blocking_count'));
        $this->assertFalse(data_get($payload, 'implementation_preflight.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'implementation_preflight.writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'implementation_preflight.approval_granted'));
        $this->assertFalse(data_get($payload, 'implementation_preflight.merge_allowed'));
        $this->assertContains('writer_implementation_absent', data_get($payload, 'implementation_preflight.blocking_conditions'));
        $this->assertContains('all_forbidden_authorities_absent', data_get($payload, 'implementation_preflight.future_release_conditions'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-implementation-preflight' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Implementation preflight status', $output);
        $this->assertStringContainsString('Blocking count', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Implementation preflight hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence writer implementation preflight is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_writer_implementation_preflight', data_get($payload, 'authorization.status'));
        $this->assertSame(9, data_get($payload, 'authorization.required_evidence_count'));
        $this->assertContains('human_writer_release_confirmation_hash', data_get($payload, 'authorization.required_external_evidence'));
        $this->assertContains('writer_file_creation_by_writer_release_authorization_template', data_get($payload, 'authorization.still_forbidden_by_release_authorization_template'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_write_ledger', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'authorization_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_future_human_writer_release_authorization', data_get($payload, 'authorization.status'));
        $this->assertFalse(data_get($payload, 'authorization.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'authorization.writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'authorization.approval_granted'));
        $this->assertFalse(data_get($payload, 'authorization.merge_allowed'));
        $this->assertContains('writer_patch_reviewed_by_principal_integrator', data_get($payload, 'authorization.required_authorization_checks'));
        $this->assertContains('may_write_one_append_only_persistence_event_after_all_checks_pass', data_get($payload, 'authorization.future_authorized_writer_scope'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-template' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Release authorization status', $output);
        $this->assertStringContainsString('Required evidence count', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Authorization hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence writer release authorization template is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_writer_release_authorization_template', data_get($payload, 'preflight.status'));
        $this->assertSame(1, data_get($payload, 'preflight.blocking_count'));
        $this->assertContains('writer_release_authorization_template_not_ready', data_get($payload, 'preflight.blocking_conditions'));
        $this->assertContains('writer_file_creation_by_writer_release_authorization_preflight', data_get($payload, 'preflight.still_forbidden_by_release_authorization_preflight'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_create_writer_file', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'preflight_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_writer_release_evidence', data_get($payload, 'preflight.status'));
        $this->assertSame(12, data_get($payload, 'preflight.blocking_count'));
        $this->assertFalse(data_get($payload, 'preflight.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'preflight.writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'preflight.approval_granted'));
        $this->assertFalse(data_get($payload, 'preflight.merge_allowed'));
        $this->assertContains('missing_human_writer_release_confirmation_hash', data_get($payload, 'preflight.blocking_conditions'));
        $this->assertContains('writer_release_authorization_receipt_hash', data_get($payload, 'preflight.future_writer_release_outputs'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-preflight' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Release authorization preflight status', $output);
        $this->assertStringContainsString('Blocking count', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Preflight hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence writer release authorization preflight is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-receipt-draft' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_writer_release_authorization_preflight', data_get($payload, 'receipt.status'));
        $this->assertSame('request_external_writer_release_evidence', data_get($payload, 'receipt.selected_decision'));
        $this->assertContains('authorize_writer_release', data_get($payload, 'receipt.allowed_decisions'));
        $this->assertContains('writer_file_creation_by_writer_release_authorization_receipt_draft', data_get($payload, 'receipt.still_forbidden_by_receipt_draft'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_write_ledger', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'receipt_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-receipt-draft' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_writer_release_evidence', data_get($payload, 'receipt.status'));
        $this->assertFalse(data_get($payload, 'receipt.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'receipt.writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'receipt.approval_granted'));
        $this->assertFalse(data_get($payload, 'receipt.merge_allowed'));
        $this->assertContains('receipt_hash', data_get($payload, 'receipt.future_signature_request_inputs'));
        $this->assertContains('source_writer_release_authorization_preflight_hash', data_get($payload, 'receipt.signable_payload_fields'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-receipt-draft' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Receipt status', $output);
        $this->assertStringContainsString('Selected decision', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Receipt hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence writer release authorization receipt draft is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-signature-request' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_writer_release_authorization_receipt_draft', data_get($payload, 'signature_request.status'));
        $this->assertTrue(data_get($payload, 'signature_request.signature_required'));
        $this->assertFalse(data_get($payload, 'signature_request.signature_present'));
        $this->assertFalse(data_get($payload, 'signature_request.signature_valid'));
        $this->assertContains('external_writer_release_signature_value', data_get($payload, 'signature_request.required_external_signature_evidence'));
        $this->assertContains('writer_file_creation_by_writer_release_authorization_signature_request', data_get($payload, 'signature_request.still_forbidden_by_signature_request'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_accept_signature', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'request_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'signable_payload_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-signature-request' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_writer_release_signature', data_get($payload, 'signature_request.status'));
        $this->assertFalse(data_get($payload, 'signature_request.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'signature_request.writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'signature_request.approval_granted'));
        $this->assertFalse(data_get($payload, 'signature_request.merge_allowed'));
        $this->assertContains('signed_writer_release_authorization_receipt_template_hash', data_get($payload, 'signature_request.future_post_signature_outputs'));
        $this->assertSame(data_get($payload, 'signature_request.signable_payload_hash'), data_get($payload, 'signable_payload_hash'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-signature-request' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Signature request status', $output);
        $this->assertStringContainsString('Signature required', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Request hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence writer release authorization signature request is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-post-signature-runbook' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_writer_release_authorization_signature_request', data_get($payload, 'runbook.status'));
        $this->assertSame(8, data_get($payload, 'runbook.step_count'));
        $this->assertContains('collect_external_writer_release_signature_evidence', data_get($payload, 'runbook.ordered_steps'));
        $this->assertContains('writer_file_creation_by_writer_release_authorization_post_signature_runbook', data_get($payload, 'runbook.still_forbidden_by_runbook'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_validate_signature', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'runbook_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-post-signature-runbook' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_writer_release_signature_evidence', data_get($payload, 'runbook.status'));
        $this->assertFalse(data_get($payload, 'runbook.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'runbook.writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'runbook.approval_granted'));
        $this->assertFalse(data_get($payload, 'runbook.merge_allowed'));
        $this->assertContains('validated_writer_release_authorization_signature_hash', data_get($payload, 'runbook.future_signed_receipt_template_inputs'));
        $this->assertContains('writer_patch_still_matches_contract_hash', data_get($payload, 'runbook.future_validator_must_check'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-post-signature-runbook' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Runbook status', $output);
        $this->assertStringContainsString('Step count', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Runbook hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence writer release authorization post-signature runbook is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-signed-receipt-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'receipt_signed'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_writer_release_authorization_post_signature_runbook', data_get($payload, 'template.status'));
        $this->assertContains('validated_writer_release_authorization_signature_hash', data_get($payload, 'template.required_external_signed_receipt_evidence'));
        $this->assertContains('selected_decision_equals_authorize_writer_release', data_get($payload, 'template.future_writer_release_preflight_requirements'));
        $this->assertContains('writer_file_creation_by_writer_release_authorization_signed_receipt_template', data_get($payload, 'template.still_forbidden_by_template'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_persist_receipt', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'template_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-signed-receipt-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_signed_writer_release_authorization_evidence', data_get($payload, 'template.status'));
        $this->assertFalse(data_get($payload, 'template.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'template.writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'template.approval_granted'));
        $this->assertFalse(data_get($payload, 'template.merge_allowed'));
        $this->assertContains('signed_writer_release_authorization_receipt_id', data_get($payload, 'template.signed_receipt_fields_to_persist_in_future'));
        $this->assertContains('writer_capability_tests_still_pass', data_get($payload, 'template.future_writer_release_preflight_requirements'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-signed-receipt-template' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Template status', $output);
        $this->assertStringContainsString('Receipt signed', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Template hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence writer release authorization signed receipt template is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'receipt_signed'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_writer_release_authorization_signed_receipt_template', data_get($payload, 'preflight.status'));
        $this->assertSame(1, data_get($payload, 'preflight.blocking_count'));
        $this->assertContains('writer_release_authorization_signed_receipt_template_not_ready', data_get($payload, 'preflight.blocking_conditions'));
        $this->assertContains('writer_file_creation_by_writer_release_preflight', data_get($payload, 'preflight.still_forbidden_by_release_preflight'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_write_ledger', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'preflight_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_signed_writer_release_authorization_evidence', data_get($payload, 'preflight.status'));
        $this->assertSame(10, data_get($payload, 'preflight.blocking_count'));
        $this->assertFalse(data_get($payload, 'preflight.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'preflight.writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'preflight.approval_granted'));
        $this->assertFalse(data_get($payload, 'preflight.merge_allowed'));
        $this->assertContains('selected_decision_not_authorize_writer_release', data_get($payload, 'preflight.blocking_conditions'));
        $this->assertContains('writer_has_no_merge_authority', data_get($payload, 'preflight.required_release_checks'));
        $this->assertContains('writer_release_execution_contract_hash', data_get($payload, 'preflight.future_release_outputs'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-preflight' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Release preflight status', $output);
        $this->assertStringContainsString('Blocking count', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Preflight hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence writer release preflight is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-receipt-draft' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'receipt_signed'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_writer_release_preflight', data_get($payload, 'receipt.status'));
        $this->assertContains('writer_release_receipt_id', data_get($payload, 'receipt.release_receipt_fields'));
        $this->assertContains('writer_release_receipt_hash', data_get($payload, 'receipt.future_signature_request_inputs'));
        $this->assertContains('writer_file_creation_by_writer_release_receipt_draft', data_get($payload, 'receipt.still_forbidden_by_receipt_draft'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_does_not_persist_receipt', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'receipt_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-receipt-draft' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_ready', data_get($payload, 'status'));
        $this->assertSame('unsigned_writer_release_receipt_draft_waiting_for_external_evidence', data_get($payload, 'receipt.status'));
        $this->assertSame(10, data_get($payload, 'receipt.inherited_blocking_count'));
        $this->assertFalse(data_get($payload, 'receipt.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'receipt.writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'receipt.approval_granted'));
        $this->assertFalse(data_get($payload, 'receipt.merge_allowed'));
        $this->assertContains('selected_decision_not_authorize_writer_release', data_get($payload, 'receipt.inherited_blocking_conditions'));
        $this->assertContains('writer_no_merge_authority_evidence_hash', data_get($payload, 'receipt.release_receipt_fields'));
        $this->assertContains('writer_release_execution_contract_hash', data_get($payload, 'receipt.future_post_signature_outputs'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-receipt-draft' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Receipt status', $output);
        $this->assertStringContainsString('Selected decision', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Receipt hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence writer release receipt draft is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-signature-request' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_signed'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_writer_release_receipt_draft', data_get($payload, 'signature_request.status'));
        $this->assertTrue(data_get($payload, 'signature_request.signature_required'));
        $this->assertFalse(data_get($payload, 'signature_request.signature_present'));
        $this->assertContains('external_writer_release_signature_value', data_get($payload, 'signature_request.required_signature_evidence'));
        $this->assertContains('writer_file_creation_by_writer_release_signature_request', data_get($payload, 'signature_request.still_forbidden_by_signature_request'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_accept_signature', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'request_hash'));
        $this->assertSame(data_get($payload, 'signature_request.signable_payload_hash'), data_get($payload, 'signable_payload_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-signature-request' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_writer_release_signature', data_get($payload, 'signature_request.status'));
        $this->assertTrue(data_get($payload, 'signature_request.signature_required'));
        $this->assertFalse(data_get($payload, 'signature_request.signature_present'));
        $this->assertFalse(data_get($payload, 'signature_request.signature_valid'));
        $this->assertFalse(data_get($payload, 'signature_request.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'signature_request.writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'signature_request.merge_allowed'));
        $this->assertContains('selected_decision_not_authorize_writer_release', data_get($payload, 'signature_request.signable_payload.inherited_blocking_conditions'));
        $this->assertContains('writer_release_signed_receipt_template_hash', data_get($payload, 'signature_request.future_post_signature_outputs'));
        $this->assertSame('writer_release_receipt_only', data_get($payload, 'signature_request.signable_payload.requested_signature_scope'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-signature-request' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Signature request status', $output);
        $this->assertStringContainsString('Signature required', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Request hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence writer release signature request is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-post-signature-runbook' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_signed'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_writer_release_signature_request', data_get($payload, 'runbook.status'));
        $this->assertSame(8, data_get($payload, 'runbook.step_count'));
        $this->assertContains('collect_external_writer_release_signature_evidence', data_get($payload, 'runbook.ordered_steps'));
        $this->assertContains('writer_file_creation_by_writer_release_post_signature_runbook', data_get($payload, 'runbook.still_forbidden_by_runbook'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_does_not_validate_signature', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'runbook_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-post-signature-runbook' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_writer_release_signature_evidence', data_get($payload, 'runbook.status'));
        $this->assertSame(8, data_get($payload, 'runbook.step_count'));
        $this->assertFalse(data_get($payload, 'runbook.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'runbook.writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'runbook.approval_granted'));
        $this->assertFalse(data_get($payload, 'runbook.merge_allowed'));
        $this->assertContains('validated_writer_release_signature_hash', data_get($payload, 'runbook.future_signed_receipt_template_inputs'));
        $this->assertContains('writer_no_dispatch_authority_still_true', data_get($payload, 'runbook.future_validator_must_check'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-post-signature-runbook' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Runbook status', $output);
        $this->assertStringContainsString('Step count', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Runbook hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence writer release post-signature runbook is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-signed-receipt-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_signed'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_writer_release_post_signature_runbook', data_get($payload, 'template.status'));
        $this->assertContains('validated_writer_release_signature_hash', data_get($payload, 'template.required_external_validated_signature_evidence'));
        $this->assertContains('selected_decision_equals_authorize_writer_release', data_get($payload, 'template.future_execution_contract_requirements'));
        $this->assertContains('writer_file_creation_by_writer_release_signed_receipt_template', data_get($payload, 'template.still_forbidden_by_template'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_does_not_persist_receipt', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'template_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-signed-receipt-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_validated_writer_release_signature_evidence', data_get($payload, 'template.status'));
        $this->assertFalse(data_get($payload, 'template.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'template.writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'template.approval_granted'));
        $this->assertFalse(data_get($payload, 'template.merge_allowed'));
        $this->assertContains('signed_writer_release_receipt_id', data_get($payload, 'template.signed_receipt_fields_to_persist_in_future'));
        $this->assertContains('writer_has_no_dispatch_authority', data_get($payload, 'template.future_execution_contract_requirements'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-signed-receipt-template' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Template status', $output);
        $this->assertStringContainsString('Receipt signed', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Template hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence writer release signed receipt template is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-execution-contract-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_signed'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_writer_release_signed_receipt_template', data_get($payload, 'preflight.status'));
        $this->assertSame(1, data_get($payload, 'preflight.blocking_count'));
        $this->assertContains('writer_release_signed_receipt_template_not_ready', data_get($payload, 'preflight.blocking_conditions'));
        $this->assertContains('execution_scope_is_writer_release_only', data_get($payload, 'preflight.required_execution_contract_checks'));
        $this->assertContains('writer_file_creation_by_writer_release_execution_contract_preflight', data_get($payload, 'preflight.still_forbidden_by_execution_contract_preflight'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_does_not_dispatch_work', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'preflight_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-execution-contract-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_ready', data_get($payload, 'status'));
        $this->assertSame('waiting_for_external_validated_writer_release_signature_evidence', data_get($payload, 'preflight.status'));
        $this->assertSame(10, data_get($payload, 'preflight.blocking_count'));
        $this->assertFalse(data_get($payload, 'preflight.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'preflight.writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'preflight.approval_granted'));
        $this->assertFalse(data_get($payload, 'preflight.merge_allowed'));
        $this->assertContains('selected_decision_not_authorize_writer_release', data_get($payload, 'preflight.blocking_conditions'));
        $this->assertContains('rollback_and_disable_path_defined', data_get($payload, 'preflight.required_execution_contract_checks'));
        $this->assertContains('writer_release_post_execution_receipt_hash', data_get($payload, 'preflight.future_execution_contract_outputs'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-execution-contract-preflight' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Execution preflight status', $output);
        $this->assertStringContainsString('Blocking count', $output);
        $this->assertStringContainsString('Writer file creation allowed', $output);
        $this->assertStringContainsString('Preflight hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence writer release execution contract preflight is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-execution-contract-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_signed'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_writer_release_execution_contract_preflight', data_get($payload, 'contract.status'));
        $this->assertSame(1, data_get($payload, 'contract.blocking_count'));
        $this->assertContains('writer_release_execution_contract_preflight_not_ready', data_get($payload, 'contract.blocking_conditions'));
        $this->assertContains('writer_release_executor_identity', data_get($payload, 'contract.required_actor_evidence'));
        $this->assertContains('writer_file_creation_by_writer_release_execution_contract_template', data_get($payload, 'contract.still_forbidden_by_execution_contract_template'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_does_not_dispatch_work', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'contract_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-execution-contract-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_ready', data_get($payload, 'status'));
        $this->assertSame('blocked_waiting_for_external_writer_release_execution_authority', data_get($payload, 'contract.status'));
        $this->assertSame(10, data_get($payload, 'contract.blocking_count'));
        $this->assertFalse(data_get($payload, 'contract.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'contract.writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'contract.approval_granted'));
        $this->assertFalse(data_get($payload, 'contract.merge_allowed'));
        $this->assertContains('writer_release_execution_still_not_authorized', data_get($payload, 'contract.blocking_conditions'));
        $this->assertContains('writer_contract_hash_rechecked_against_patch', data_get($payload, 'contract.required_recheck_evidence'));
        $this->assertContains('writer_release_post_execution_receipt_hash', data_get($payload, 'contract.future_post_execution_outputs'));
        $this->assertContains('merge_authority', data_get($payload, 'contract.execution_scope.writer_contract_forbidden_capabilities'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-execution-contract-template' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Contract status', $output);
        $this->assertStringContainsString('Blocking count', $output);
        $this->assertStringContainsString('Writer file creation allowed', $output);
        $this->assertStringContainsString('Contract hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence writer release execution contract template is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-disable-contract-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_signed'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_writer_release_execution_contract_template', data_get($payload, 'disable_contract.status'));
        $this->assertSame(10, data_get($payload, 'disable_contract.trigger_count'));
        $this->assertContains('writer_merge_authority_detected', data_get($payload, 'disable_contract.disable_triggers'));
        $this->assertContains('revoke_writer_release_capability_flag', data_get($payload, 'disable_contract.required_disable_steps'));
        $this->assertContains('writer_file_creation_by_writer_release_disable_contract_template', data_get($payload, 'disable_contract.still_forbidden_by_disable_contract_template'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_does_not_dispatch_work', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'disable_contract_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-disable-contract-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_ready', data_get($payload, 'status'));
        $this->assertSame('ready_as_future_disable_template', data_get($payload, 'disable_contract.status'));
        $this->assertSame(10, data_get($payload, 'disable_contract.trigger_count'));
        $this->assertFalse(data_get($payload, 'disable_contract.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'disable_contract.writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'disable_contract.approval_granted'));
        $this->assertFalse(data_get($payload, 'disable_contract.merge_allowed'));
        $this->assertContains('post_disable_no_merge_authority_evidence_hash', data_get($payload, 'disable_contract.required_disable_evidence'));
        $this->assertContains('writer_release_reenable_review_packet_hash', data_get($payload, 'disable_contract.future_disable_outputs'));
        $this->assertContains('fresh_human_authorization', data_get($payload, 'disable_contract.reenable_requirements'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-disable-contract-template' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Disable status', $output);
        $this->assertStringContainsString('Trigger count', $output);
        $this->assertStringContainsString('Writer file creation allowed', $output);
        $this->assertStringContainsString('Disable hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence writer release disable contract template is blocked', $output);
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template_blocked_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-observability-contract-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template_blocked', data_get($payload, 'status'));
        $this->assertSame('read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'signature_valid'));
        $this->assertFalse(data_get($payload, 'receipt_signed'));
        $this->assertFalse(data_get($payload, 'receipt_persisted'));
        $this->assertFalse(data_get($payload, 'merge_allowed'));
        $this->assertSame('blocked_before_writer_release_disable_contract_template', data_get($payload, 'observability_contract.status'));
        $this->assertSame(12, data_get($payload, 'observability_contract.signal_count'));
        $this->assertContains('writer_release_forbidden_merge_attempt_detected', data_get($payload, 'observability_contract.required_signals'));
        $this->assertContains('alert_on_unexpected_ledger_write', data_get($payload, 'observability_contract.required_alerts'));
        $this->assertContains('writer_file_creation_by_writer_release_observability_contract_template', data_get($payload, 'observability_contract.still_forbidden_by_observability_contract_template'));
        $this->assertContains('codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template_does_not_dispatch_work', data_get($payload, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'observability_contract_hash'));
    }

    public function test_command_returns_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template_ready_after_all_packets_completed(): void
    {
        $packets = [
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ];

        foreach ($packets as $index => $packetId) {
            $actor = 'codex-'.($index + 1);
            $session = 'session-'.($index + 1);

            Artisan::call('atlas:ai:self-construction', [
                '--claim-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--json' => true,
            ]);

            Artisan::call('atlas:ai:self-construction', [
                '--complete-packet' => true,
                '--packet' => $packetId,
                '--actor' => $actor,
                '--session' => $session,
                '--reason' => 'packet_scope_finished',
                '--evidence-hash' => hash('sha256', $packetId),
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-observability-contract-template' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template_ready', data_get($payload, 'status'));
        $this->assertSame('ready_as_future_observability_template', data_get($payload, 'observability_contract.status'));
        $this->assertSame(12, data_get($payload, 'observability_contract.signal_count'));
        $this->assertFalse(data_get($payload, 'observability_contract.ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'observability_contract.writer_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'observability_contract.approval_granted'));
        $this->assertFalse(data_get($payload, 'observability_contract.merge_allowed'));
        $this->assertContains('writer_release_time_to_disable_ms', data_get($payload, 'observability_contract.required_metrics'));
        $this->assertContains('writer_release_post_monitoring_review_hash', data_get($payload, 'observability_contract.future_observability_outputs'));
        $this->assertSame(60, data_get($payload, 'observability_contract.minimum_monitoring_window.after_future_release_minutes'));
    }

    public function test_command_human_output_lists_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-observability-contract-template' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Observability status', $output);
        $this->assertStringContainsString('Signal count', $output);
        $this->assertStringContainsString('Writer file creation allowed', $output);
        $this->assertStringContainsString('Observability hash', $output);
        $this->assertStringContainsString('Codex review merge post-execution action signed receipt persistence writer release observability contract template is blocked', $output);
    }

    public function test_command_returns_codex_start_packet_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-start-packet' => true,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_codex_start_packet.v1', data_get($payload, 'schema_version'));
        $this->assertSame('codex_start_packet_ready', data_get($payload, 'status'));
        $this->assertSame('durable_local_codex_start_packet', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertTrue(data_get($payload, 'claim_persisted'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001', data_get($payload, 'packet_id'));
        $this->assertSame('AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001', data_get($payload, 'contract.packet_id'));
        $this->assertSame('codex-a', data_get($payload, 'contract.actor'));
        $this->assertStringContainsString('continua a implementação', data_get($payload, 'contract.one_line_user_prompt'));
        $this->assertStringContainsString('durably claimed packet', data_get($payload, 'contract.operator_prompt'));
        $this->assertMatchesRegularExpression('/^RES-[A-F0-9]{20}$/', data_get($payload, 'contract.claim.reservation_id'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'contract.claim.claim_hash'));
        $this->assertContains('docs/engineering-knowledge-base/atlas-ai-self-construction-os.md', data_get($payload, 'contract.scope.allowed_files'));
        $this->assertSame(
            'php artisan atlas:ai:self-construction --ai-session-bootstrap --packet=AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001 --json',
            data_get($payload, 'contract.bootstrap_command')
        );
        $this->assertSame(
            'php artisan atlas:ai:self-construction --scope-validator --packet=AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001 --json',
            data_get($payload, 'contract.scope_validator_command')
        );
        $this->assertStringContainsString(
            'php artisan atlas:ai:self-construction --complete-packet --packet=AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            data_get($payload, 'contract.completion_command')
        );
        $this->assertStringContainsString(
            '--evidence-hash=<sha256-of-final-evidence>',
            data_get($payload, 'contract.completion_command')
        );
        $this->assertContains('touch_only_allowed_files', data_get($payload, 'contract.implementation_rules'));
        $this->assertContains('state_scope_validator_status', data_get($payload, 'contract.final_response_contract'));
        $this->assertContains('scope_validator_output', data_get($payload, 'contract.required_evidence'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'contract_hash'));
    }

    public function test_command_human_output_lists_codex_start_packet(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-start-packet' => true,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Claim persisted', $output);
        $this->assertStringContainsString('Packet', $output);
        $this->assertStringContainsString('Contract hash', $output);
        $this->assertStringContainsString('Codex start packet is ready', $output);
    }

    public function test_command_blocks_codex_start_packet_when_no_packets_remain(): void
    {
        for ($index = 1; $index <= 5; $index++) {
            Artisan::call('atlas:ai:self-construction', [
                '--codex-start-packet' => true,
                '--actor' => 'codex-'.$index,
                '--session' => 'session-'.$index,
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--codex-start-packet' => true,
            '--actor' => 'codex-extra',
            '--session' => 'session-extra',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('blocked', data_get($payload, 'status'));
        $this->assertFalse(data_get($payload, 'claim_persisted'));
        $this->assertNull(data_get($payload, 'packet_id'));
        $this->assertContains('no_available_packet', data_get($payload, 'contract.blocking_reasons'));
        $this->assertSame(5, data_get($payload, 'contract.queue_state.claimed_count'));
    }

    public function test_command_claims_next_packet_without_reusing_claimed_packet(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--claim-next-packet' => true,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
            '--json' => true,
        ]);

        $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        Artisan::call('atlas:ai:self-construction', [
            '--claim-next-packet' => true,
            '--actor' => 'codex-b',
            '--session' => 'session-b',
            '--json' => true,
        ]);

        $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001', data_get($first, 'packet_id'));
        $this->assertSame('AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002', data_get($second, 'packet_id'));
        $this->assertTrue(data_get($first, 'claim_persisted'));
        $this->assertTrue(data_get($second, 'claim_persisted'));

        Artisan::call('atlas:ai:self-construction', [
            '--packet-queue' => true,
            '--json' => true,
        ]);

        $queue = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(3, data_get($queue, 'queue.available_count'));
        $this->assertSame(2, data_get($queue, 'queue.claimed_count'));
    }

    public function test_command_blocks_claim_next_when_no_packets_remain(): void
    {
        for ($index = 1; $index <= 5; $index++) {
            Artisan::call('atlas:ai:self-construction', [
                '--claim-next-packet' => true,
                '--actor' => 'codex-'.$index,
                '--session' => 'session-'.$index,
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--claim-next-packet' => true,
            '--actor' => 'codex-extra',
            '--session' => 'session-extra',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('blocked', data_get($payload, 'status'));
        $this->assertFalse(data_get($payload, 'claim_persisted'));
        $this->assertNull(data_get($payload, 'packet_id'));
        $this->assertContains('no_available_packet', data_get($payload, 'start.blocking_reasons'));
        $this->assertSame(0, data_get($payload, 'start.available_count'));
        $this->assertSame(5, data_get($payload, 'start.claimed_count'));
    }

    public function test_command_blocks_duplicate_packet_claim_and_releases_owner_claim(): void
    {
        $packetId = 'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001';

        Artisan::call('atlas:ai:self-construction', [
            '--claim-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--claim-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-b',
            '--session' => 'session-b',
            '--json' => true,
        ]);

        $duplicate = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('blocked', data_get($duplicate, 'status'));
        $this->assertFalse(data_get($duplicate, 'claim_persisted'));
        $this->assertContains('packet_already_claimed', data_get($duplicate, 'claim.blocking_reasons'));

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--release-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
            '--reason' => 'test_release',
            '--json' => true,
        ]);

        $release = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('released', data_get($release, 'status'));
        $this->assertTrue(data_get($release, 'release.released'));
        $this->assertSame('test_release', data_get($release, 'release.reservation.release_reason'));
    }

    public function test_command_completes_owner_packet_and_queue_marks_it_completed(): void
    {
        $packetId = 'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001';

        Artisan::call('atlas:ai:self-construction', [
            '--claim-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--complete-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
            '--reason' => 'focused_tests_passed',
            '--evidence-hash' => str_repeat('a', 64),
            '--json' => true,
        ]);

        $completion = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_complete_packet.v1', data_get($completion, 'schema_version'));
        $this->assertSame('completed', data_get($completion, 'status'));
        $this->assertSame('durable_local_packet_completion', data_get($completion, 'mode'));
        $this->assertFalse(data_get($completion, 'execution_allowed'));
        $this->assertFalse(data_get($completion, 'completion_allowed'));
        $this->assertTrue(data_get($completion, 'completion_persisted'));
        $this->assertFalse(data_get($completion, 'approval_granted'));
        $this->assertTrue(data_get($completion, 'completion.completed'));
        $this->assertSame($packetId, data_get($completion, 'completion.packet_id'));
        $this->assertSame(str_repeat('a', 64), data_get($completion, 'completion.reservation.completion_evidence_hash'));
        $this->assertContains('complete_packet_does_not_approve_code', data_get($completion, 'non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($completion, 'completion_hash'));

        Artisan::call('atlas:ai:self-construction', [
            '--packet-queue' => true,
            '--json' => true,
        ]);

        $queue = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $entry = collect(data_get($queue, 'queue.entries'))->firstWhere('packet_id', $packetId);

        $this->assertSame(1, data_get($queue, 'queue.completed_count'));
        $this->assertSame(4, data_get($queue, 'queue.available_count'));
        $this->assertSame(0, data_get($queue, 'queue.claimed_count'));
        $this->assertSame('completed', data_get($entry, 'queue_state'));
        $this->assertSame('codex-a', data_get($entry, 'completion_actor'));
    }

    public function test_command_blocks_completed_packet_from_being_claimed_again(): void
    {
        $packetId = 'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001';

        Artisan::call('atlas:ai:self-construction', [
            '--claim-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
            '--json' => true,
        ]);
        Artisan::call('atlas:ai:self-construction', [
            '--complete-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--claim-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-b',
            '--session' => 'session-b',
            '--json' => true,
        ]);

        $claim = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('blocked', data_get($claim, 'status'));
        $this->assertFalse(data_get($claim, 'claim_persisted'));
        $this->assertContains('packet_already_completed', data_get($claim, 'claim.blocking_reasons'));
    }

    public function test_command_blocks_packet_completion_by_non_owner(): void
    {
        $packetId = 'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001';

        Artisan::call('atlas:ai:self-construction', [
            '--claim-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--complete-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-b',
            '--session' => 'session-b',
            '--json' => true,
        ]);

        $completion = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('blocked', data_get($completion, 'status'));
        $this->assertFalse(data_get($completion, 'completion_persisted'));
        $this->assertContains('actor_or_session_not_owner', data_get($completion, 'completion.blocking_reasons'));
    }

    public function test_command_human_output_lists_complete_packet(): void
    {
        $packetId = 'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001';

        Artisan::call('atlas:ai:self-construction', [
            '--claim-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--complete-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Completed', $output);
        $this->assertStringContainsString('Completion hash', $output);
        $this->assertStringContainsString('Packet reservation was durably marked completed', $output);
    }

    public function test_command_reservation_status_reports_completed_packets(): void
    {
        $packetId = 'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001';

        Artisan::call('atlas:ai:self-construction', [
            '--claim-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
            '--json' => true,
        ]);
        Artisan::call('atlas:ai:self-construction', [
            '--complete-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-a',
            '--session' => 'session-a',
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--reservation-status' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(0, data_get($payload, 'ledger.active_count'));
        $this->assertSame(1, data_get($payload, 'ledger.completed_count'));
        $this->assertSame($packetId, data_get($payload, 'ledger.completed_reservations.0.packet_id'));
        $this->assertSame('completed', data_get($payload, 'ledger.completed_reservations.0.state'));
    }

    public function test_command_returns_packet_queue_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--packet-queue' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_packet_queue.v1', data_get($payload, 'schema_version'));
        $this->assertSame('packet_queue_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_packet_queue', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertFalse(data_get($payload, 'claim_persisted'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'queue_write_allowed'));
        $this->assertSame('AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001', data_get($payload, 'queue.recommended_packet_id'));
        $this->assertSame(7, data_get($payload, 'queue.entry_count'));
        $this->assertSame(5, data_get($payload, 'queue.available_count'));
        $this->assertSame(0, data_get($payload, 'queue.blocked_count'));
        $this->assertSame(0, data_get($payload, 'queue.claimed_count'));
        $this->assertSame(2, data_get($payload, 'queue.withheld_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'queue_hash'));

        $states = collect(data_get($payload, 'queue.entries'))->pluck('queue_state')->all();

        $this->assertContains('available', $states);
        $this->assertContains('withheld', $states);
        $this->assertSame('php artisan atlas:ai:self-construction --ai-session-bootstrap --packet=AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001 --json', data_get($payload, 'queue.entries.0.required_bootstrap_command'));
        $this->assertContains('packet_queue_does_not_persist_claim', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('packet_queue_does_not_dispatch_work', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('packet_queue_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_packet_queue(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--packet-queue' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Claim persisted', $output);
        $this->assertStringContainsString('Queue entries', $output);
        $this->assertStringContainsString('Queue hash', $output);
        $this->assertStringContainsString('Packet queue is ready', $output);
    }

    public function test_command_returns_parallel_session_plan_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--parallel-session-plan' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_parallel_session_plan.v1', data_get($payload, 'schema_version'));
        $this->assertSame('parallel_session_plan_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_parallel_session_plan', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertFalse(data_get($payload, 'claim_persisted'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame(5, data_get($payload, 'plan.max_session_slots'));
        $this->assertSame(5, data_get($payload, 'plan.slot_count'));
        $this->assertSame(5, data_get($payload, 'plan.preview_assignable_count'));
        $this->assertSame(0, data_get($payload, 'plan.idle_slot_count'));
        $this->assertSame(0, data_get($payload, 'plan.blocked_packet_count'));
        $this->assertSame(2, data_get($payload, 'plan.withheld_packet_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'plan_hash'));

        $states = collect(data_get($payload, 'plan.slots'))->pluck('state')->all();

        $this->assertContains('preview_assignable', $states);
        $this->assertNotContains('idle_no_safe_packet', $states);
        $this->assertSame('AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001', data_get($payload, 'plan.slots.0.packet_id'));
        $this->assertSame('AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005', data_get($payload, 'plan.slots.4.packet_id'));
        $this->assertSame('php artisan atlas:ai:self-construction --ai-session-bootstrap --packet=AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001 --json', data_get($payload, 'plan.slots.0.bootstrap_command'));
        $this->assertContains('parallel_session_plan_does_not_persist_claim', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('parallel_session_plan_does_not_dispatch_work', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('parallel_session_plan_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_parallel_session_plan(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--parallel-session-plan' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Dispatch allowed', $output);
        $this->assertStringContainsString('Session slots', $output);
        $this->assertStringContainsString('Plan hash', $output);
        $this->assertStringContainsString('Parallel session plan is ready', $output);
    }

    public function test_command_returns_collision_matrix_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--collision-matrix' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_collision_matrix.v1', data_get($payload, 'schema_version'));
        $this->assertSame('collision_matrix_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_collision_matrix', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertFalse(data_get($payload, 'claim_persisted'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame(7, data_get($payload, 'matrix.entry_count'));
        $this->assertSame(21, data_get($payload, 'matrix.pair_count'));
        $this->assertSame(10, data_get($payload, 'matrix.safe_pair_count'));
        $this->assertSame(11, data_get($payload, 'matrix.blocked_pair_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'matrix_hash'));

        $decisions = collect(data_get($payload, 'matrix.pairs'))->pluck('decision')->all();
        $safePair = collect(data_get($payload, 'matrix.pairs'))->firstWhere('decision', 'parallel_safe');

        $this->assertContains('parallel_safe', $decisions);
        $this->assertContains('blocked', $decisions);
        $this->assertSame('AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001', data_get($safePair, 'left_packet_id'));
        $this->assertSame('AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002', data_get($safePair, 'right_packet_id'));
        $this->assertContains('collision_matrix_does_not_persist_claim', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('collision_matrix_does_not_dispatch_work', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('collision_matrix_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_collision_matrix(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--collision-matrix' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Pair count', $output);
        $this->assertStringContainsString('Blocked pairs', $output);
        $this->assertStringContainsString('Matrix hash', $output);
        $this->assertStringContainsString('Collision matrix is ready', $output);
    }

    public function test_command_returns_dependency_unlock_plan_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--dependency-unlock-plan' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_dependency_unlock_plan.v1', data_get($payload, 'schema_version'));
        $this->assertSame('dependency_unlock_plan_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_dependency_unlock_plan', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertFalse(data_get($payload, 'claim_persisted'));
        $this->assertFalse(data_get($payload, 'completion_persisted'));
        $this->assertFalse(data_get($payload, 'state_mutation_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame([
            'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
            'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
            'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
            'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
            'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
        ], data_get($payload, 'plan.available_packet_ids'));
        $this->assertSame(0, data_get($payload, 'plan.blocked_packet_count'));
        $this->assertSame(0, data_get($payload, 'plan.unlock_edge_count'));
        $this->assertSame(2, data_get($payload, 'plan.withheld_packet_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'plan_hash'));

        $this->assertContains('dependency_unlock_plan_does_not_mutate_queue', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('dependency_unlock_plan_does_not_persist_completion', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('dependency_unlock_plan_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_dependency_unlock_plan(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--dependency-unlock-plan' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Blocked packets', $output);
        $this->assertStringContainsString('Unlock edges', $output);
        $this->assertStringContainsString('Plan hash', $output);
        $this->assertStringContainsString('Dependency unlock plan is ready', $output);
    }

    public function test_command_returns_multi_session_readiness_gate_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--multi-session-readiness-gate' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_multi_session_readiness_gate.v1', data_get($payload, 'schema_version'));
        $this->assertSame('multi_session_readiness_gate_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_multi_session_readiness_gate', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertFalse(data_get($payload, 'multi_session_allowed'));
        $this->assertTrue(data_get($payload, 'parallel_preview_allowed'));
        $this->assertTrue(data_get($payload, 'single_session_preview_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('ready_for_multi_session_preview', data_get($payload, 'gate.decision'));
        $this->assertFalse(data_get($payload, 'gate.multi_session_allowed'));
        $this->assertTrue(data_get($payload, 'gate.single_session_preview_allowed'));
        $this->assertTrue(data_get($payload, 'gate.parallel_preview_allowed'));
        $this->assertSame('continue_parallel_preview_with_packet_scoped_bootstrap', data_get($payload, 'gate.safe_next_instruction'));
        $this->assertSame(5, data_get($payload, 'gate.counts.preview_assignable_packets'));
        $this->assertSame(2, data_get($payload, 'gate.counts.withheld_packets'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'gate_hash'));
        $this->assertSame([], data_get($payload, 'gate.blocking_reasons'));
        $this->assertContains('hot_external_work_withheld_from_cold_lane', data_get($payload, 'gate.non_blocking_warnings'));
        $this->assertContains('multi_session_readiness_gate_does_not_start_sessions', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('multi_session_readiness_gate_does_not_write_ledger', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('multi_session_readiness_gate_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_multi_session_readiness_gate(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--multi-session-readiness-gate' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Decision', $output);
        $this->assertStringContainsString('Multi-session allowed', $output);
        $this->assertStringContainsString('Gate hash', $output);
        $this->assertStringContainsString('Multi-session readiness gate is ready', $output);
    }

    public function test_command_returns_single_session_instruction_packet_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--single-session-instruction-packet' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_single_session_instruction_packet.v1', data_get($payload, 'schema_version'));
        $this->assertSame('single_session_instruction_packet_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_single_session_instruction_packet', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));
        $this->assertFalse(data_get($payload, 'claim_persisted'));
        $this->assertFalse(data_get($payload, 'reservation_persisted'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001', data_get($payload, 'instruction.selected_packet_id'));
        $this->assertSame('continue_parallel_preview_with_packet_scoped_bootstrap', data_get($payload, 'instruction.safe_next_instruction'));
        $this->assertStringContainsString('Continue exactly one Self-Construction session', data_get($payload, 'instruction.operator_instruction'));
        $this->assertContains('php artisan atlas:ai:self-construction --ai-session-bootstrap --packet=AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001 --json', data_get($payload, 'instruction.required_first_commands'));
        $this->assertContains('docs/engineering-knowledge-base/atlas-ai-self-construction-os.md', data_get($payload, 'instruction.allowed_files'));
        $this->assertContains('php artisan atlas:ai:self-construction --scope-validator --packet=AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001 --json', data_get($payload, 'instruction.required_gates'));
        $this->assertContains('php artisan atlas:ai:architecture-validate --json', data_get($payload, 'instruction.required_gates'));
        $this->assertContains('scope_validator_output', data_get($payload, 'instruction.required_evidence'));
        $this->assertNotContains('durable_reservation_ledger_missing', data_get($payload, 'instruction.stop_conditions'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'instruction_hash'));
        $this->assertContains('single_session_instruction_packet_does_not_start_session', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('single_session_instruction_packet_does_not_persist_claim', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('single_session_instruction_packet_does_not_enable_execution', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_single_session_instruction_packet(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--single-session-instruction-packet' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Selected packet', $output);
        $this->assertStringContainsString('Safe next', $output);
        $this->assertStringContainsString('Instruction hash', $output);
        $this->assertStringContainsString('Single-session instruction packet is ready', $output);
    }

    public function test_command_returns_durable_reservation_ledger_plan_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-ledger-plan' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_durable_reservation_ledger_implementation_plan.v1', data_get($payload, 'schema_version'));
        $this->assertSame('durable_reservation_ledger_plan_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_durable_reservation_ledger_implementation_plan', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse(data_get($payload, 'migration_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('durable_reservation_ledger_missing', data_get($payload, 'plan.blocker_removed_when_complete'));
        $this->assertSame(3, data_get($payload, 'plan.storage_object_count'));
        $this->assertContains('atlas_self_construction_reservations', array_column(data_get($payload, 'plan.storage_objects'), 'name'));
        $this->assertContains('claimed', data_get($payload, 'plan.claim_states'));
        $this->assertContains('reject_allowed_file_overlap_with_active_reservations', data_get($payload, 'plan.atomic_claim_sequence'));
        $this->assertContains('one_active_reservation_per_packet', data_get($payload, 'plan.required_invariants'));
        $this->assertContains('blocks_duplicate_packet_claim', data_get($payload, 'plan.required_tests'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'plan.forbidden_scopes'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'plan_hash'));
        $this->assertContains('durable_reservation_ledger_plan_does_not_create_migrations', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_ledger_plan_does_not_write_ledger', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_ledger_plan_does_not_dispatch_work', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_durable_reservation_ledger_plan(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-ledger-plan' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Storage objects', $output);
        $this->assertStringContainsString('Ledger write allowed', $output);
        $this->assertStringContainsString('Plan hash', $output);
        $this->assertStringContainsString('Durable reservation ledger implementation plan is ready', $output);
    }

    public function test_command_returns_durable_reservation_ap_candidate_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-ap-candidate' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_durable_reservation_ap_candidate.v1', data_get($payload, 'schema_version'));
        $this->assertSame('durable_reservation_ap_candidate_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_durable_reservation_ap_candidate', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'storage_write_allowed'));
        $this->assertFalse(data_get($payload, 'migration_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertTrue(data_get($payload, 'operator_approval_required'));
        $this->assertSame('durable_reservation_ledger_missing', data_get($payload, 'candidate.blocker_target'));
        $this->assertSame(4, data_get($payload, 'candidate.packet_count'));
        $this->assertContains('DR-AP-STORAGE-0001', array_column(data_get($payload, 'candidate.packets'), 'packet_id'));
        $this->assertContains('DR-AP-READINESS-0004', array_column(data_get($payload, 'candidate.packets'), 'packet_id'));
        $this->assertContains('dispatch_still_disabled', data_get($payload, 'candidate.promotion_gates'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'candidate.global_forbidden_scopes'));
        $this->assertSame('review_ap_candidate_before_any_storage_or_migration_work', data_get($payload, 'candidate.next_safe_action'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'candidate_hash'));
        $this->assertContains('durable_reservation_ap_candidate_does_not_create_migrations', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_ap_candidate_does_not_dispatch_work', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_ap_candidate_requires_operator_approval', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_durable_reservation_ap_candidate(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-ap-candidate' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('AP id', $output);
        $this->assertStringContainsString('Packet count', $output);
        $this->assertStringContainsString('Candidate hash', $output);
        $this->assertStringContainsString('Durable reservation AP candidate is ready', $output);
    }

    public function test_command_returns_durable_reservation_approval_request_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-approval-request' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_durable_reservation_approval_request.v1', data_get($payload, 'schema_version'));
        $this->assertSame('durable_reservation_approval_request_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_durable_reservation_approval_request', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'storage_write_allowed'));
        $this->assertFalse(data_get($payload, 'migration_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('not_approved_read_only_request', data_get($payload, 'request.approval_status'));
        $this->assertSame(4, data_get($payload, 'request.required_signer_count'));
        $this->assertContains('architecture_governor', data_get($payload, 'request.required_signers'));
        $this->assertContains('approve_storage_and_migration_scope', data_get($payload, 'request.operator_decisions_required'));
        $this->assertContains('dispatch_enabled_in_same_ap', data_get($payload, 'request.approval_blockers'));
        $this->assertContains('architecture_validate_output', data_get($payload, 'request.required_evidence'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'request.forbidden_scopes'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'request_hash'));
        $this->assertContains('durable_reservation_approval_request_does_not_grant_approval', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_approval_request_does_not_write_storage', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_approval_request_does_not_dispatch_work', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_durable_reservation_approval_request(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-approval-request' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Approval status', $output);
        $this->assertStringContainsString('Required signers', $output);
        $this->assertStringContainsString('Request hash', $output);
        $this->assertStringContainsString('Durable reservation approval request is ready', $output);
    }

    public function test_command_returns_durable_reservation_approval_decision_template_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-approval-decision' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_durable_reservation_approval_decision_template.v1', data_get($payload, 'schema_version'));
        $this->assertSame('durable_reservation_approval_decision_template_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_durable_reservation_approval_decision_template', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'decision_signed'));
        $this->assertFalse(data_get($payload, 'storage_write_allowed'));
        $this->assertFalse(data_get($payload, 'migration_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('template_not_signed', data_get($payload, 'decision.decision_status'));
        $this->assertContains('approved_for_scoped_implementation', data_get($payload, 'decision.allowed_decisions'));
        $this->assertSame(4, data_get($payload, 'decision.signer_slot_count'));
        $this->assertContains('dispatch_requires_separate_future_ap', data_get($payload, 'decision.post_decision_limits'));
        $this->assertContains('request_hash_changed', data_get($payload, 'decision.expiry_checks'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'decision.forbidden_scope'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'decision_hash'));
        $this->assertContains('durable_reservation_approval_decision_template_does_not_grant_approval', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_approval_decision_template_does_not_sign_decision', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_approval_decision_template_does_not_dispatch_work', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_durable_reservation_approval_decision_template(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-approval-decision' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Decision status', $output);
        $this->assertStringContainsString('Signer slots', $output);
        $this->assertStringContainsString('Decision hash', $output);
        $this->assertStringContainsString('Durable reservation approval decision template is ready', $output);
    }

    public function test_command_returns_durable_reservation_post_approval_preflight_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-post-approval-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_durable_reservation_post_approval_preflight.v1', data_get($payload, 'schema_version'));
        $this->assertSame('durable_reservation_post_approval_preflight_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_durable_reservation_post_approval_preflight', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'approval_granted'));
        $this->assertFalse(data_get($payload, 'preflight_passed'));
        $this->assertFalse(data_get($payload, 'storage_write_allowed'));
        $this->assertFalse(data_get($payload, 'migration_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('blocked_until_signed_approval', data_get($payload, 'preflight.decision'));
        $this->assertSame(2, data_get($payload, 'preflight.blocking_check_count'));
        $this->assertContains('signed_approval_decision', data_get($payload, 'preflight.required_before_implementation'));
        $this->assertContains('dispatch_remains_disabled', data_get($payload, 'preflight.implementation_limits_after_pass'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'preflight.forbidden_scopes'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'preflight_hash'));
        $this->assertContains('durable_reservation_post_approval_preflight_does_not_create_migrations', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_post_approval_preflight_does_not_write_storage', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_post_approval_preflight_does_not_dispatch_work', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_durable_reservation_post_approval_preflight(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-post-approval-preflight' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Preflight decision', $output);
        $this->assertStringContainsString('Blocking checks', $output);
        $this->assertStringContainsString('Preflight hash', $output);
        $this->assertStringContainsString('Durable reservation post-approval preflight is ready', $output);
    }

    public function test_command_returns_durable_reservation_implementation_packet_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-implementation-packet' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_durable_reservation_implementation_packet.v1', data_get($payload, 'schema_version'));
        $this->assertSame('durable_reservation_implementation_packet_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_durable_reservation_implementation_packet', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'implementation_allowed_now'));
        $this->assertFalse(data_get($payload, 'storage_write_allowed'));
        $this->assertFalse(data_get($payload, 'migration_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('blocked_until_post_approval_preflight_passes', data_get($payload, 'packet.packet_status'));
        $this->assertSame(4, data_get($payload, 'packet.work_packet_count'));
        $this->assertContains('php artisan atlas:ai:self-construction --durable-reservation-post-approval-preflight --json', data_get($payload, 'packet.required_first_commands'));
        $this->assertContains('post_approval_preflight_not_passed', data_get($payload, 'packet.stop_conditions'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'packet.forbidden_scopes'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'packet_hash'));
        $this->assertContains('durable_reservation_implementation_packet_does_not_create_migrations', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_implementation_packet_does_not_write_storage', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_implementation_packet_requires_passed_preflight', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_durable_reservation_implementation_packet(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-implementation-packet' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Packet status', $output);
        $this->assertStringContainsString('Work packets', $output);
        $this->assertStringContainsString('Packet hash', $output);
        $this->assertStringContainsString('Durable reservation implementation packet is ready', $output);
    }

    public function test_command_returns_durable_reservation_storage_schema_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-storage-schema' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_durable_reservation_storage_schema.v1', data_get($payload, 'schema_version'));
        $this->assertSame('durable_reservation_storage_schema_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_durable_reservation_storage_schema', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'implementation_allowed_now'));
        $this->assertFalse(data_get($payload, 'migration_allowed_now'));
        $this->assertFalse(data_get($payload, 'storage_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('blocked_until_post_approval_preflight_passes', data_get($payload, 'storage_schema.schema_status'));
        $this->assertSame(2, data_get($payload, 'storage_schema.table_count'));
        $this->assertContains('atlas_self_construction_reservation_events', array_column(data_get($payload, 'storage_schema.tables'), 'name'));
        $this->assertContains('atlas_self_construction_reservations', array_column(data_get($payload, 'storage_schema.tables'), 'name'));
        $this->assertContains('one_active_claim_per_packet', data_get($payload, 'storage_schema.invariants'));
        $this->assertContains('active_allowed_file_overlap_blocks_new_claim', data_get($payload, 'storage_schema.invariants'));
        $this->assertContains('storage_schema_command_does_not_create_migrations_or_write_storage', data_get($payload, 'storage_schema.required_tests'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'storage_schema.forbidden_scopes'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'schema_hash'));
        $this->assertContains('durable_reservation_storage_schema_does_not_create_migrations', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_storage_schema_does_not_write_storage', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_durable_reservation_storage_schema(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-storage-schema' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Schema status', $output);
        $this->assertStringContainsString('Tables', $output);
        $this->assertStringContainsString('Schema hash', $output);
        $this->assertStringContainsString('Durable reservation storage schema is ready', $output);
    }

    public function test_command_returns_durable_reservation_repository_contract_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-repository-contract' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_durable_reservation_repository_contract.v1', data_get($payload, 'schema_version'));
        $this->assertSame('durable_reservation_repository_contract_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_durable_reservation_repository_contract', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'implementation_allowed_now'));
        $this->assertFalse(data_get($payload, 'repository_write_allowed_now'));
        $this->assertFalse(data_get($payload, 'storage_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('blocked_until_storage_schema_and_preflight_pass', data_get($payload, 'repository_contract.contract_status'));
        $this->assertSame(9, data_get($payload, 'repository_contract.method_count'));
        $this->assertContains('claim', array_column(data_get($payload, 'repository_contract.methods'), 'name'));
        $this->assertContains('complete', array_column(data_get($payload, 'repository_contract.methods'), 'name'));
        $this->assertContains('packet_already_claimed', data_get($payload, 'repository_contract.errors'));
        $this->assertContains('event_chain_mismatch', data_get($payload, 'repository_contract.errors'));
        $this->assertContains('append_event_before_projection_update', data_get($payload, 'repository_contract.transaction_rules'));
        $this->assertContains('repository_contract_command_does_not_persist_claims_or_write_storage', data_get($payload, 'repository_contract.required_tests'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'repository_contract.forbidden_scopes'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'contract_hash'));
        $this->assertContains('durable_reservation_repository_contract_does_not_create_repository', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_repository_contract_does_not_write_storage', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_durable_reservation_repository_contract(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-repository-contract' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Contract status', $output);
        $this->assertStringContainsString('Methods', $output);
        $this->assertStringContainsString('Contract hash', $output);
        $this->assertStringContainsString('Durable reservation repository contract is ready', $output);
    }

    public function test_command_returns_durable_reservation_collision_guard_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-collision-guard' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_durable_reservation_collision_guard.v1', data_get($payload, 'schema_version'));
        $this->assertSame('durable_reservation_collision_guard_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_durable_reservation_collision_guard', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'implementation_allowed_now'));
        $this->assertFalse(data_get($payload, 'claim_allowed_now'));
        $this->assertFalse(data_get($payload, 'storage_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('blocked_until_repository_and_projection_exist', data_get($payload, 'collision_guard.guard_status'));
        $this->assertSame(6, data_get($payload, 'collision_guard.blocking_decision_count'));
        $this->assertContains('candidate_allowed_files', data_get($payload, 'collision_guard.inputs'));
        $this->assertContains('hot_scope_forbidden', data_get($payload, 'collision_guard.blocking_decisions'));
        $this->assertContains('active_file_overlap', data_get($payload, 'collision_guard.blocking_decisions'));
        $this->assertContains('require_human_review', data_get($payload, 'collision_guard.decision_states'));
        $this->assertContains('collision_guard_command_does_not_persist_claims_or_write_storage', data_get($payload, 'collision_guard.required_tests'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'collision_guard.forbidden_scopes'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'guard_hash'));
        $this->assertContains('durable_reservation_collision_guard_does_not_create_detector', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_collision_guard_does_not_write_storage', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_durable_reservation_collision_guard(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-collision-guard' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Guard status', $output);
        $this->assertStringContainsString('Blocking decisions', $output);
        $this->assertStringContainsString('Guard hash', $output);
        $this->assertStringContainsString('Durable reservation collision guard is ready', $output);
    }

    public function test_command_returns_durable_reservation_lease_lifecycle_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-lease-lifecycle' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_durable_reservation_lease_lifecycle.v1', data_get($payload, 'schema_version'));
        $this->assertSame('durable_reservation_lease_lifecycle_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_durable_reservation_lease_lifecycle', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'implementation_allowed_now'));
        $this->assertFalse(data_get($payload, 'claim_allowed_now'));
        $this->assertFalse(data_get($payload, 'storage_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('blocked_until_repository_projection_and_collision_guard_exist', data_get($payload, 'lease_lifecycle.lifecycle_status'));
        $this->assertSame(7, data_get($payload, 'lease_lifecycle.state_count'));
        $this->assertContains('claimed', data_get($payload, 'lease_lifecycle.states'));
        $this->assertContains('expired', data_get($payload, 'lease_lifecycle.states'));
        $this->assertContains('claimed_to_renewed', data_get($payload, 'lease_lifecycle.transitions'));
        $this->assertContains('expired_or_released_leases_cannot_complete', data_get($payload, 'lease_lifecycle.timing_rules'));
        $this->assertContains('lease_lifecycle_command_does_not_persist_claims_or_write_storage', data_get($payload, 'lease_lifecycle.required_tests'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'lease_lifecycle.forbidden_scopes'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'lifecycle_hash'));
        $this->assertContains('durable_reservation_lease_lifecycle_does_not_create_lifecycle_runtime', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_lease_lifecycle_does_not_write_storage', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_durable_reservation_lease_lifecycle(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-lease-lifecycle' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Lifecycle status', $output);
        $this->assertStringContainsString('States', $output);
        $this->assertStringContainsString('Lifecycle hash', $output);
        $this->assertStringContainsString('Durable reservation lease lifecycle is ready', $output);
    }

    public function test_command_returns_durable_reservation_readiness_projection_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-readiness-projection' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_durable_reservation_readiness_projection.v1', data_get($payload, 'schema_version'));
        $this->assertSame('durable_reservation_readiness_projection_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_durable_reservation_readiness_projection', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'implementation_allowed_now'));
        $this->assertFalse(data_get($payload, 'claim_allowed_now'));
        $this->assertFalse(data_get($payload, 'storage_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('blocked_until_durable_projection_exists', data_get($payload, 'readiness_projection.projection_status'));
        $this->assertSame(7, data_get($payload, 'readiness_projection.queue_state_count'));
        $this->assertContains('active_reservations', data_get($payload, 'readiness_projection.inputs'));
        $this->assertContains('blocked_by_collision', data_get($payload, 'readiness_projection.queue_states'));
        $this->assertContains('safe_single_session_fallback_instruction', data_get($payload, 'readiness_projection.outputs'));
        $this->assertContains('multi_session_readiness_gate', data_get($payload, 'readiness_projection.integration_targets'));
        $this->assertContains('readiness_projection_command_does_not_persist_claims_or_write_storage', data_get($payload, 'readiness_projection.required_tests'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'readiness_projection.forbidden_scopes'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'projection_hash'));
        $this->assertContains('durable_reservation_readiness_projection_does_not_create_projection_runtime', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_readiness_projection_does_not_write_storage', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_durable_reservation_readiness_projection(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-readiness-projection' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Projection status', $output);
        $this->assertStringContainsString('Queue states', $output);
        $this->assertStringContainsString('Projection hash', $output);
        $this->assertStringContainsString('Durable reservation readiness projection is ready', $output);
    }

    public function test_command_returns_durable_reservation_implementation_preflight_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-implementation-preflight' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $contractHashIds = array_column(data_get($payload, 'implementation_preflight.contract_hashes'), 'id');

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_durable_reservation_implementation_preflight.v1', data_get($payload, 'schema_version'));
        $this->assertSame('durable_reservation_implementation_preflight_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_durable_reservation_implementation_preflight', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'implementation_allowed_now'));
        $this->assertFalse(data_get($payload, 'migration_allowed_now'));
        $this->assertFalse(data_get($payload, 'storage_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('blocked_until_signed_approval_and_contract_hashes_pass', data_get($payload, 'implementation_preflight.preflight_status'));
        $this->assertSame(7, data_get($payload, 'implementation_preflight.contract_hash_count'));
        $this->assertContains('post_approval_preflight', $contractHashIds);
        $this->assertContains('implementation_packet', $contractHashIds);
        $this->assertContains('storage_schema', $contractHashIds);
        $this->assertContains('repository_contract', $contractHashIds);
        $this->assertContains('collision_guard', $contractHashIds);
        $this->assertContains('lease_lifecycle', $contractHashIds);
        $this->assertContains('readiness_projection', $contractHashIds);
        $this->assertContains('php artisan atlas:ai:self-construction --traceability --json', data_get($payload, 'implementation_preflight.required_gates'));
        $this->assertContains('dispatch_requested', data_get($payload, 'implementation_preflight.blocking_conditions'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'implementation_preflight.forbidden_scopes'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'preflight_hash'));
        $this->assertContains('durable_reservation_implementation_preflight_does_not_create_migrations', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_implementation_preflight_does_not_write_storage', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_durable_reservation_implementation_preflight(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-implementation-preflight' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Preflight status', $output);
        $this->assertStringContainsString('Contract hashes', $output);
        $this->assertStringContainsString('Preflight hash', $output);
        $this->assertStringContainsString('Durable reservation implementation preflight is ready', $output);
    }

    public function test_command_returns_durable_reservation_migration_blueprint_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-migration-blueprint' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $tableNames = array_column(data_get($payload, 'migration_blueprint.tables'), 'name');

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_durable_reservation_migration_blueprint.v1', data_get($payload, 'schema_version'));
        $this->assertSame('durable_reservation_migration_blueprint_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_durable_reservation_migration_blueprint', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'implementation_allowed_now'));
        $this->assertFalse(data_get($payload, 'migration_allowed_now'));
        $this->assertFalse(data_get($payload, 'storage_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('blocked_until_signed_preflight_and_migration_scope_approval', data_get($payload, 'migration_blueprint.blueprint_status'));
        $this->assertSame(2, data_get($payload, 'migration_blueprint.table_count'));
        $this->assertContains('atlas_self_construction_reservation_events', $tableNames);
        $this->assertContains('atlas_self_construction_reservations', $tableNames);
        $this->assertContains('create_atlas_self_construction_reservation_events_table', data_get($payload, 'migration_blueprint.migration_files'));
        $this->assertContains('drop_atlas_self_construction_reservations', data_get($payload, 'migration_blueprint.rollback_order'));
        $this->assertContains('migration_blueprint_command_does_not_create_migrations_or_write_storage', data_get($payload, 'migration_blueprint.required_tests'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'migration_blueprint.forbidden_scopes'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'blueprint_hash'));
        $this->assertContains('durable_reservation_migration_blueprint_does_not_create_migration_files', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_migration_blueprint_does_not_write_storage', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_durable_reservation_migration_blueprint(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-migration-blueprint' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Blueprint status', $output);
        $this->assertStringContainsString('Tables', $output);
        $this->assertStringContainsString('Blueprint hash', $output);
        $this->assertStringContainsString('Durable reservation migration blueprint is ready', $output);
    }

    public function test_command_returns_durable_reservation_repository_blueprint_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-repository-blueprint' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_durable_reservation_repository_blueprint.v1', data_get($payload, 'schema_version'));
        $this->assertSame('durable_reservation_repository_blueprint_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_durable_reservation_repository_blueprint', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'implementation_allowed_now'));
        $this->assertFalse(data_get($payload, 'migration_allowed_now'));
        $this->assertFalse(data_get($payload, 'runtime_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'storage_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('blocked_until_migration_blueprint_and_repository_scope_approval', data_get($payload, 'repository_blueprint.blueprint_status'));
        $this->assertSame(7, data_get($payload, 'repository_blueprint.class_count'));
        $this->assertSame(9, data_get($payload, 'repository_blueprint.method_count'));
        $this->assertContains('App\Services\Ai\SelfConstruction\Reservations\DurableReservationRepository', data_get($payload, 'repository_blueprint.classes'));
        $this->assertContains('claim', data_get($payload, 'repository_blueprint.methods'));
        $this->assertContains('packet_hash_stale', data_get($payload, 'repository_blueprint.error_codes'));
        $this->assertContains('repository_methods_never_dispatch_work', data_get($payload, 'repository_blueprint.transaction_rules'));
        $this->assertContains('repository_blueprint_command_does_not_create_php_files_or_write_storage', data_get($payload, 'repository_blueprint.required_tests'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'repository_blueprint.forbidden_scopes'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'blueprint_hash'));
        $this->assertContains('durable_reservation_repository_blueprint_does_not_create_php_files', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_repository_blueprint_does_not_write_storage', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_durable_reservation_repository_blueprint(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-repository-blueprint' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Blueprint status', $output);
        $this->assertStringContainsString('Classes', $output);
        $this->assertStringContainsString('Blueprint hash', $output);
        $this->assertStringContainsString('Durable reservation repository blueprint is ready', $output);
    }

    public function test_command_returns_durable_reservation_collision_guard_blueprint_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-collision-guard-blueprint' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_durable_reservation_collision_guard_blueprint.v1', data_get($payload, 'schema_version'));
        $this->assertSame('durable_reservation_collision_guard_blueprint_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_durable_reservation_collision_guard_blueprint', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'implementation_allowed_now'));
        $this->assertFalse(data_get($payload, 'runtime_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'claim_allowed_now'));
        $this->assertFalse(data_get($payload, 'storage_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('blocked_until_repository_blueprint_and_guard_scope_approval', data_get($payload, 'collision_guard_blueprint.blueprint_status'));
        $this->assertSame(6, data_get($payload, 'collision_guard_blueprint.blocker_count'));
        $this->assertSame(7, data_get($payload, 'collision_guard_blueprint.output_count'));
        $this->assertContains('candidate_allowed_files', data_get($payload, 'collision_guard_blueprint.inputs'));
        $this->assertContains('hot_scope_forbidden', data_get($payload, 'collision_guard_blueprint.blockers'));
        $this->assertContains('active_file_overlap', data_get($payload, 'collision_guard_blueprint.blockers'));
        $this->assertContains('allow_claim', data_get($payload, 'collision_guard_blueprint.decision_states'));
        $this->assertContains('conflicting_file_paths', data_get($payload, 'collision_guard_blueprint.outputs'));
        $this->assertContains('collision_guard_blueprint_command_does_not_create_php_files_or_write_storage', data_get($payload, 'collision_guard_blueprint.required_tests'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'collision_guard_blueprint.forbidden_scopes'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'blueprint_hash'));
        $this->assertContains('durable_reservation_collision_guard_blueprint_does_not_create_php_files', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_collision_guard_blueprint_does_not_write_storage', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_durable_reservation_collision_guard_blueprint(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-collision-guard-blueprint' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Blueprint status', $output);
        $this->assertStringContainsString('Blockers', $output);
        $this->assertStringContainsString('Blueprint hash', $output);
        $this->assertStringContainsString('Durable reservation collision guard blueprint is ready', $output);
    }

    public function test_command_returns_durable_reservation_lease_lifecycle_blueprint_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-lease-lifecycle-blueprint' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_durable_reservation_lease_lifecycle_blueprint.v1', data_get($payload, 'schema_version'));
        $this->assertSame('durable_reservation_lease_lifecycle_blueprint_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_durable_reservation_lease_lifecycle_blueprint', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'implementation_allowed_now'));
        $this->assertFalse(data_get($payload, 'runtime_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'claim_allowed_now'));
        $this->assertFalse(data_get($payload, 'storage_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('blocked_until_collision_guard_blueprint_and_lifecycle_scope_approval', data_get($payload, 'lease_lifecycle_blueprint.blueprint_status'));
        $this->assertSame(7, data_get($payload, 'lease_lifecycle_blueprint.state_count'));
        $this->assertSame(10, data_get($payload, 'lease_lifecycle_blueprint.transition_count'));
        $this->assertContains('claimed', data_get($payload, 'lease_lifecycle_blueprint.states'));
        $this->assertContains('released_or_expired_to_claimed_by_new_owner', data_get($payload, 'lease_lifecycle_blueprint.transitions'));
        $this->assertContains('completion_requires_same_owner_active_lease_and_passing_completion_gate', data_get($payload, 'lease_lifecycle_blueprint.timing_rules'));
        $this->assertContains('lease_lifecycle_blueprint_command_does_not_create_php_files_or_write_storage', data_get($payload, 'lease_lifecycle_blueprint.required_tests'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'lease_lifecycle_blueprint.forbidden_scopes'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'blueprint_hash'));
        $this->assertContains('durable_reservation_lease_lifecycle_blueprint_does_not_create_php_files', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_lease_lifecycle_blueprint_does_not_write_storage', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_durable_reservation_lease_lifecycle_blueprint(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-lease-lifecycle-blueprint' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Blueprint status', $output);
        $this->assertStringContainsString('States', $output);
        $this->assertStringContainsString('Blueprint hash', $output);
        $this->assertStringContainsString('Durable reservation lease lifecycle blueprint is ready', $output);
    }

    public function test_command_returns_durable_reservation_readiness_projection_blueprint_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-readiness-projection-blueprint' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_durable_reservation_readiness_projection_blueprint.v1', data_get($payload, 'schema_version'));
        $this->assertSame('durable_reservation_readiness_projection_blueprint_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_durable_reservation_readiness_projection_blueprint', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'implementation_allowed_now'));
        $this->assertFalse(data_get($payload, 'runtime_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'claim_allowed_now'));
        $this->assertFalse(data_get($payload, 'storage_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('blocked_until_lease_lifecycle_blueprint_and_projection_scope_approval', data_get($payload, 'readiness_projection_blueprint.blueprint_status'));
        $this->assertSame(7, data_get($payload, 'readiness_projection_blueprint.queue_state_count'));
        $this->assertSame(7, data_get($payload, 'readiness_projection_blueprint.output_count'));
        $this->assertContains('active_reservations', data_get($payload, 'readiness_projection_blueprint.inputs'));
        $this->assertContains('blocked_by_dependency', data_get($payload, 'readiness_projection_blueprint.queue_states'));
        $this->assertContains('safe_single_session_fallback_instruction', data_get($payload, 'readiness_projection_blueprint.outputs'));
        $this->assertContains('multi_session_readiness_gate', data_get($payload, 'readiness_projection_blueprint.integration_targets'));
        $this->assertContains('readiness_projection_blueprint_command_does_not_create_php_files_or_write_storage', data_get($payload, 'readiness_projection_blueprint.required_tests'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'readiness_projection_blueprint.forbidden_scopes'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'blueprint_hash'));
        $this->assertContains('durable_reservation_readiness_projection_blueprint_does_not_create_php_files', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_readiness_projection_blueprint_does_not_write_storage', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_durable_reservation_readiness_projection_blueprint(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-readiness-projection-blueprint' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Blueprint status', $output);
        $this->assertStringContainsString('Queue states', $output);
        $this->assertStringContainsString('Blueprint hash', $output);
        $this->assertStringContainsString('Durable reservation readiness projection blueprint is ready', $output);
    }

    public function test_command_returns_durable_reservation_runtime_build_packet_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-runtime-build-packet' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_durable_reservation_runtime_build_packet.v1', data_get($payload, 'schema_version'));
        $this->assertSame('durable_reservation_runtime_build_packet_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_durable_reservation_runtime_build_packet', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertFalse(data_get($payload, 'implementation_allowed_now'));
        $this->assertFalse(data_get($payload, 'runtime_file_creation_allowed'));
        $this->assertFalse(data_get($payload, 'migration_creation_allowed'));
        $this->assertFalse(data_get($payload, 'claim_allowed_now'));
        $this->assertFalse(data_get($payload, 'storage_write_allowed'));
        $this->assertFalse(data_get($payload, 'dispatch_allowed'));
        $this->assertSame('blocked_until_signed_approval_preflight_and_runtime_scope_approval', data_get($payload, 'runtime_build_packet.build_status'));
        $this->assertSame(5, data_get($payload, 'runtime_build_packet.source_blueprint_count'));
        $this->assertSame(8, data_get($payload, 'runtime_build_packet.slice_count'));
        $this->assertSame(8, data_get($payload, 'runtime_build_packet.future_file_count'));
        $this->assertContains('readiness_projection_blueprint', array_column(data_get($payload, 'runtime_build_packet.source_blueprints'), 'id'));
        $this->assertContains('durable_reservation_repository', data_get($payload, 'runtime_build_packet.implementation_slices'));
        $this->assertContains('app/Services/Ai/SelfConstruction/Reservations/DurableReservationRepository.php', data_get($payload, 'runtime_build_packet.future_files'));
        $this->assertContains('php artisan atlas:ai:architecture-validate --json', data_get($payload, 'runtime_build_packet.required_gates'));
        $this->assertContains('blueprint_hash_drift', data_get($payload, 'runtime_build_packet.stop_conditions'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'runtime_build_packet.forbidden_scopes'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'build_packet_hash'));
        $this->assertContains('durable_reservation_runtime_build_packet_does_not_create_migrations', data_get($payload, 'non_execution_guarantees'));
        $this->assertContains('durable_reservation_runtime_build_packet_does_not_create_php_files', data_get($payload, 'non_execution_guarantees'));
    }

    public function test_command_human_output_lists_durable_reservation_runtime_build_packet(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--durable-reservation-runtime-build-packet' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Build status', $output);
        $this->assertStringContainsString('Slices', $output);
        $this->assertStringContainsString('Build hash', $output);
        $this->assertStringContainsString('Durable reservation runtime build packet is ready', $output);
    }
}
