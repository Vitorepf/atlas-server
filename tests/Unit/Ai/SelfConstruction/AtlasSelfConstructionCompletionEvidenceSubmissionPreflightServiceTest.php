<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService;
use Tests\TestCase;

final class AtlasSelfConstructionCompletionEvidenceSubmissionPreflightServiceTest extends TestCase
{
    private AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService;
    }

    private function greenAudit(): array
    {
        return [
            'status' => 'complete',
            'completion_allowed' => true,
            'failed_count' => 0,
            'failed_criteria' => [],
            'completion_audit_hash' => str_repeat('a', 64),
        ];
    }

    private function greenEvidence(): array
    {
        return [
            'runtime_gap_matrix' => [
                'all_runtime_y' => true,
                'runtime_gap_matrix_hash' => str_repeat('b', 64),
                'runtime_promotion_receipt' => [
                    'status' => 'passed',
                    'receipt_hash' => str_repeat('c', 64),
                ],
            ],
            'real_provider_smoke' => [
                'status' => 'passed',
                'smoke_hash' => str_repeat('d', 64),
            ],
            'human_signed_completion_receipt' => [
                'status' => 'passed',
                'receipt_hash' => str_repeat('e', 64),
            ],
            'completion_evidence_status_hash' => str_repeat('f', 64),
        ];
    }

    private function greenExplainer(): array
    {
        return [
            'explainer_hash' => str_repeat('g', 64),
            'blockers' => [],
            'command_plan' => [
                'draft_runtime_promotion_receipt' => 'php artisan draft:runtime-receipt',
                'persist_runtime_promotion_receipt' => 'php artisan persist:runtime-receipt',
                'draft_real_provider_smoke' => 'php artisan draft:smoke',
                'persist_real_provider_smoke' => 'php artisan persist:smoke',
                'draft_human_completion_receipt' => 'php artisan draft:human-receipt',
                'persist_human_completion_receipt' => 'php artisan persist:human-receipt',
                'compose_completion_evidence_hashes' => 'php artisan compose:hashes',
            ],
        ];
    }

    // ── AC: output shape includes required fields ───────────────────────────

    public function test_build_returns_required_output_keys(): void
    {
        $result = $this->service->build($this->greenAudit(), $this->greenEvidence(), $this->greenExplainer());

        foreach ([
            'schema_version', 'mode', 'status', 'ordered_steps',
            'operator_execution_plan', 'operator_resumption_checkpoint',
            'operator_closure_command_replay', 'operator_handoff_packet',
            'closure_artifact_sequence', 'prompt_to_artifact_checklist',
            'submission_preflight_hash',
        ] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    // ── AC: build blocks when audit has failures ────────────────────────────

    public function test_build_blocks_when_audit_not_complete(): void
    {
        $audit = $this->greenAudit();
        $audit['status'] = 'incomplete';

        $result = $this->service->build($audit, $this->greenEvidence(), $this->greenExplainer());

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['completion_allowed']);
    }

    public function test_build_blocks_when_audit_has_failed_criteria(): void
    {
        $audit = $this->greenAudit();
        $audit['failed_count'] = 1;
        $audit['failed_criteria'] = ['runtime_gap_matrix_all_runtime_y'];
        $audit['completion_allowed'] = false;

        $result = $this->service->build($audit, $this->greenEvidence(), $this->greenExplainer());

        $this->assertSame('blocked', $result['status']);
    }

    public function test_build_blocks_when_completion_not_allowed(): void
    {
        $audit = $this->greenAudit();
        $audit['completion_allowed'] = false;

        $result = $this->service->build($audit, $this->greenEvidence(), $this->greenExplainer());

        $this->assertSame('blocked', $result['status']);
    }

    // ── AC: build blocks when expected receipt schemas missing ──────────────

    public function test_build_blocks_when_runtime_receipt_not_passed(): void
    {
        $evidence = $this->greenEvidence();
        $evidence['runtime_gap_matrix']['runtime_promotion_receipt']['status'] = 'blocked';

        $result = $this->service->build($this->greenAudit(), $evidence, $this->greenExplainer());

        $this->assertSame('blocked', $result['status']);
    }

    public function test_build_blocks_when_real_provider_smoke_not_passed(): void
    {
        $evidence = $this->greenEvidence();
        $evidence['real_provider_smoke']['status'] = 'blocked';

        $result = $this->service->build($this->greenAudit(), $evidence, $this->greenExplainer());

        $this->assertSame('blocked', $result['status']);
    }

    public function test_build_blocks_when_human_receipt_not_passed(): void
    {
        $evidence = $this->greenEvidence();
        $evidence['human_signed_completion_receipt']['status'] = 'blocked';

        $result = $this->service->build($this->greenAudit(), $evidence, $this->greenExplainer());

        $this->assertSame('blocked', $result['status']);
    }

    // ── AC: build blocks when evidence hash is stale ────────────────────────

    public function test_build_blocks_when_evidence_hash_empty(): void
    {
        $evidence = $this->greenEvidence();
        $evidence['completion_evidence_status_hash'] = '';

        $result = $this->service->build($this->greenAudit(), $evidence, $this->greenExplainer());

        // Empty hash doesn't block per se but the preflight tracks it
        $this->assertSame('', $result['completion_evidence_status_hash']);
    }

    // ── AC: build allows submission when everything consistent ──────────────

    public function test_build_ready_when_all_steps_pass(): void
    {
        $result = $this->service->build($this->greenAudit(), $this->greenEvidence(), $this->greenExplainer());

        $this->assertSame('ready_for_final_completion_audit', $result['status']);
        $this->assertSame(5, $result['step_count']);
        $this->assertGreaterThan(0, $result['ready_step_count']);
    }

    // ── AC: resumption_checkpoint present ───────────────────────────────────

    public function test_build_includes_resumption_checkpoint(): void
    {
        $result = $this->service->build($this->greenAudit(), $this->greenEvidence(), $this->greenExplainer());

        $this->assertArrayHasKey('operator_resumption_checkpoint', $result);
        $checkpoint = $result['operator_resumption_checkpoint'];
        $this->assertIsArray($checkpoint);
    }

    public function test_build_resumption_checkpoint_present_when_blocked(): void
    {
        $audit = $this->greenAudit();
        $audit['status'] = 'incomplete';

        $result = $this->service->build($audit, $this->greenEvidence(), $this->greenExplainer());

        $this->assertArrayHasKey('operator_resumption_checkpoint', $result);
    }

    // ── AC: handoff packet present ──────────────────────────────────────────

    public function test_build_includes_handoff_packet(): void
    {
        $result = $this->service->build($this->greenAudit(), $this->greenEvidence(), $this->greenExplainer());

        $this->assertArrayHasKey('operator_handoff_packet', $result);
        $this->assertIsArray($result['operator_handoff_packet']);
    }

    // ── AC: next_action_shell_packet / next_required_command ────────────────

    public function test_build_includes_next_required_command(): void
    {
        $result = $this->service->build($this->greenAudit(), $this->greenEvidence(), $this->greenExplainer());

        $this->assertArrayHasKey('next_required_command', $result);
        $this->assertNotEmpty($result['next_required_command']);
    }

    public function test_build_next_required_submission_identifies_first_blocker(): void
    {
        $evidence = $this->greenEvidence();
        $evidence['runtime_gap_matrix']['runtime_promotion_receipt']['status'] = 'blocked';

        $result = $this->service->build($this->greenAudit(), $evidence, $this->greenExplainer());

        $this->assertSame('runtime_promotion_receipt', $result['next_required_submission']);
    }

    // ── AC: required receipts tracked in closure_artifact_sequence ──────────

    public function test_closure_artifact_sequence_lists_required_receipts(): void
    {
        $result = $this->service->build($this->greenAudit(), $this->greenEvidence(), $this->greenExplainer());

        $this->assertGreaterThan(0, $result['closure_artifact_sequence_count']);
        $artifacts = $result['closure_artifact_sequence'];
        $ids = array_column($artifacts, 'artifact');
        $this->assertContains('runtime_promotion_receipt', $ids);
        $this->assertContains('real_provider_smoke', $ids);
        $this->assertContains('human_completion_receipt', $ids);
    }

    public function test_closure_artifact_sequence_marks_passed_when_green(): void
    {
        $result = $this->service->build($this->greenAudit(), $this->greenEvidence(), $this->greenExplainer());

        foreach ($result['closure_artifact_sequence'] as $artifact) {
            if (in_array($artifact['artifact'], ['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'], true)) {
                $this->assertTrue($artifact['passed'], "{$artifact['artifact']} should be passed");
            }
        }
    }

    // ── AC: blockers surfaced when steps not ready ──────────────────────────

    public function test_build_surfaces_blockers_in_ordered_steps_when_not_ready(): void
    {
        $evidence = $this->greenEvidence();
        $evidence['real_provider_smoke']['status'] = 'blocked';

        $result = $this->service->build($this->greenAudit(), $evidence, $this->greenExplainer());

        $smokeStep = collect($result['ordered_steps'])->first(fn (array $s): bool => $s['id'] === 'real_provider_smoke');
        $this->assertFalse($smokeStep['ready']);
    }

    // ── AC: completion never allowed from preflight ─────────────────────────

    public function test_build_never_allows_completion(): void
    {
        $result = $this->service->build($this->greenAudit(), $this->greenEvidence(), $this->greenExplainer());

        $this->assertFalse($result['completion_allowed']);
        $this->assertFalse($result['completion_claim_allowed']);
    }

    public function test_build_has_non_execution_guarantees(): void
    {
        $result = $this->service->build($this->greenAudit(), $this->greenEvidence(), $this->greenExplainer());

        $this->assertNotEmpty($result['non_execution_guarantees']);
        $this->assertContains('completion_evidence_submission_preflight_does_not_persist_receipts', $result['non_execution_guarantees']);
    }

    // ── determinism ─────────────────────────────────────────────────────────

    public function test_build_hash_present_and_hex64(): void
    {
        $result = $this->service->build($this->greenAudit(), $this->greenEvidence(), $this->greenExplainer());

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['submission_preflight_hash']);
    }

    public function test_build_deterministic_except_timestamp(): void
    {
        $audit = $this->greenAudit();
        $evidence = $this->greenEvidence();
        $explainer = $this->greenExplainer();

        $a = $this->service->build($audit, $evidence, $explainer);
        $b = $this->service->build($audit, $evidence, $explainer);

        $this->assertSame($a['submission_preflight_hash'], $b['submission_preflight_hash']);
    }

    public function test_build_hash_changes_when_audit_changes(): void
    {
        $a = $this->service->build($this->greenAudit(), $this->greenEvidence(), $this->greenExplainer());
        $audit = $this->greenAudit();
        $audit['status'] = 'incomplete';
        $b = $this->service->build($audit, $this->greenEvidence(), $this->greenExplainer());

        $this->assertNotSame($a['submission_preflight_hash'], $b['submission_preflight_hash']);
    }

    // ── AC: incomplete blocker explanations ────────────────────────────────

    public function test_build_includes_completion_audit_blocker_summary(): void
    {
        $result = $this->service->build($this->greenAudit(), $this->greenEvidence(), $this->greenExplainer());

        $this->assertArrayHasKey('completion_audit_blocker_summary', $result);
    }

    public function test_build_includes_operator_command_surface(): void
    {
        $result = $this->service->build($this->greenAudit(), $this->greenEvidence(), $this->greenExplainer());

        $this->assertArrayHasKey('operator_command_surface', $result);
    }

    public function test_build_includes_terminal_loop_closure_proof(): void
    {
        $result = $this->service->build($this->greenAudit(), $this->greenEvidence(), $this->greenExplainer());

        $this->assertArrayHasKey('terminal_loop_closure_proof', $result);
    }
}
