<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionHumanCompletionReceiptClosureExecutionPackService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReservationRepository;
use Tests\TestCase;

final class AtlasSelfConstructionHumanCompletionReceiptClosureExecutionPackTest extends TestCase
{
    public function test_closure_pack_is_blocked_when_runtime_and_smoke_are_missing(): void
    {
        $pack = $this->buildPack(audit: $this->auditWith(runtime: false, smoke: false, humanReceipt: false), evidence: $this->evidence(allRuntimeY: false, smokeStatus: 'blocked'));

        $this->assertSame('atlas.self_construction.human_completion_receipt_closure_execution_pack.v1', $pack['schema_version']);
        $this->assertSame('blocked_runtime_and_smoke_required', $pack['status']);
        $this->assertFalse($pack['completion_claim_allowed']);
        $this->assertFalse((bool) data_get($pack, 'current_prerequisites.runtime_gap_matrix_all_runtime_y.green'));
        $this->assertFalse((bool) data_get($pack, 'current_prerequisites.end_to_end_real_provider_smoke_green.green'));
        $this->assertContains('human_completion_receipt_closure_pack_does_not_persist_receipts', $pack['non_execution_guarantees']);
        $this->assertContains('human_completion_receipt_closure_pack_does_not_sign_for_operator', $pack['non_execution_guarantees']);
    }

    public function test_closure_pack_requires_human_signature_when_only_human_receipt_missing(): void
    {
        $pack = $this->buildPack(audit: $this->auditWith(runtime: true, smoke: true, humanReceipt: false), evidence: $this->evidence(allRuntimeY: true, smokeStatus: 'passed'));

        $this->assertSame('blocked_human_signature_required', $pack['status']);
        $this->assertTrue((bool) data_get($pack, 'current_prerequisites.runtime_gap_matrix_all_runtime_y.green'));
        $this->assertTrue((bool) data_get($pack, 'current_prerequisites.end_to_end_real_provider_smoke_green.green'));
        $this->assertFalse((bool) data_get($pack, 'persistence_preflight.can_persist'));
        $this->assertSame('blocked', (string) data_get($pack, 'verification_result.status'));
    }

    public function test_closure_pack_exposes_receipt_template_with_evidence_context_hashes(): void
    {
        $pack = $this->buildPack(audit: $this->auditWith(runtime: true, smoke: true, humanReceipt: false), evidence: $this->evidence(allRuntimeY: true, smokeStatus: 'passed'));

        $template = (array) data_get($pack, 'receipt_template', []);
        $this->assertSame('<operator_name>', $template['signed_by']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $template['completion_audit_hash']);
        $this->assertFalse($template['execution_allowed']);
        $this->assertFalse($template['completion_autopromoted']);
    }

    public function test_closure_pack_ordered_operator_steps_and_exact_commands_are_present(): void
    {
        $pack = $this->buildPack();

        $stepIds = array_column((array) data_get($pack, 'ordered_operator_steps', []), 'id');
        $this->assertContains('refresh_completion_audit', $stepIds);
        $this->assertContains('ensure_runtime_promotion_receipt_persisted', $stepIds);
        $this->assertContains('ensure_real_provider_smoke_persisted', $stepIds);
        $this->assertContains('persist_human_completion_receipt', $stepIds);
        $this->assertContains('rerun_completion_audit', $stepIds);
        $this->assertArrayHasKey('persist_human_completion_receipt', (array) data_get($pack, 'exact_commands', []));
        $this->assertStringContainsString('--persist-completion-evidence', (string) data_get($pack, 'exact_commands.persist_human_completion_receipt'));
    }

    public function test_closure_pack_anti_cheat_policy_lists_forbidden_signers_and_flags(): void
    {
        $pack = $this->buildPack();
        $forbiddenSigners = (array) data_get($pack, 'anti_cheat_policy.forbidden_signers', []);
        $forbiddenFlags = (array) data_get($pack, 'anti_cheat_policy.forbidden_flags', []);

        $this->assertContains('codex', $forbiddenSigners);
        $this->assertContains('<operator>', $forbiddenSigners);
        $this->assertContains('execution_allowed', $forbiddenFlags);
        $this->assertContains('completion_autopromoted', $forbiddenFlags);
        $this->assertSame(32, (int) data_get($pack, 'anti_cheat_policy.minimum_reason_length'));
    }

    public function test_closure_pack_does_not_promote_completion_even_with_full_test_evidence_until_verifier_passes(): void
    {
        $pack = $this->buildPack(audit: $this->auditWith(runtime: true, smoke: true, humanReceipt: false), evidence: $this->evidence(allRuntimeY: true, smokeStatus: 'passed'), receipt: $this->fakeReceipt());

        $this->assertFalse($pack['completion_claim_allowed']);
        $this->assertFalse($pack['execution_allowed']);
        $this->assertFalse($pack['provider_call_allowed']);
        $this->assertFalse($pack['token_spend_allowed']);
    }

    public function test_closure_pack_hash_is_deterministic(): void
    {
        $first = $this->buildPack();
        $second = $this->buildPack();

        $this->assertSame($first['closure_pack_hash'], $second['closure_pack_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $first['closure_pack_hash']);
    }

    public function test_closure_pack_payload_is_json_serializable(): void
    {
        $pack = $this->buildPack();

        $encoded = json_encode($pack, JSON_THROW_ON_ERROR);
        $this->assertJson($encoded);
    }

    private function service(): AtlasSelfConstructionHumanCompletionReceiptClosureExecutionPackService
    {
        return new AtlasSelfConstructionHumanCompletionReceiptClosureExecutionPackService(
            new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository),
        );
    }

    /**
     * @param  array<string, mixed>  $audit
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $receipt
     * @return array<string, mixed>
     */
    private function buildPack(array $audit = [], array $evidence = [], array $receipt = []): array
    {
        return $this->service()->build([
            'completion_audit' => $audit !== [] ? $audit : $this->auditWith(runtime: false, smoke: false, humanReceipt: false),
            'completion_evidence' => $evidence !== [] ? $evidence : $this->evidence(allRuntimeY: false, smokeStatus: 'blocked'),
            'completion_receipt' => $receipt,
        ]);
    }

    /** @return array<string, mixed> */
    private function auditWith(bool $runtime, bool $smoke, bool $humanReceipt): array
    {
        $hash = str_repeat('a', 64);
        $failed = array_values(array_filter([
            $runtime ? null : 'runtime_gap_matrix_all_runtime_y',
            $smoke ? null : 'end_to_end_real_provider_smoke_green',
            $humanReceipt ? null : 'human_signed_os_complete_receipt_present',
        ]));

        return [
            'status' => $failed === [] ? 'complete' : 'incomplete',
            'completion_audit_hash' => $hash,
            'failed_criteria' => $failed,
            'failed_count' => count($failed),
            'passed_count' => 9 - count($failed),
            'criteria' => [
                ['id' => 'runtime_gap_matrix_all_runtime_y', 'passed' => $runtime, 'evidence' => ['runtime_gap_matrix_hash' => $hash, 'runtime_promotion_receipt_hash' => $hash]],
                ['id' => 'release_dossier_green', 'passed' => true, 'evidence' => ['hash' => $hash, 'baseline_snapshot_capture_required' => false]],
                ['id' => 'replay_diff_against_completion_snapshot_green', 'passed' => true, 'evidence' => ['diff_hash' => $hash]],
                ['id' => 'promotion_gate_green', 'passed' => true, 'evidence' => []],
                ['id' => 'mutation_guard_green', 'passed' => true, 'evidence' => []],
                ['id' => 'human_signed_os_complete_receipt_present', 'passed' => $humanReceipt, 'evidence' => []],
                ['id' => 'end_to_end_real_provider_smoke_green', 'passed' => $smoke, 'evidence' => ['smoke_hash' => $hash]],
                ['id' => 'forge_self_improvement_integration_smoke_green', 'passed' => true, 'evidence' => []],
                ['id' => 'certification_status_batch_green', 'passed' => true, 'evidence' => ['hash' => $hash]],
            ],
            'operator_action_packet' => [
                'human_completion_receipt_template' => [
                    'runtime_promotion_receipt_hash' => $hash,
                    'real_provider_smoke_hash' => $hash,
                    'certification_status_batch_hash' => $hash,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function evidence(bool $allRuntimeY, string $smokeStatus): array
    {
        $hash = str_repeat('a', 64);

        return [
            'status' => $allRuntimeY && $smokeStatus === 'passed' ? 'ready' : 'blocked',
            'runtime_gap_matrix' => [
                'status' => $allRuntimeY ? 'passed' : 'blocked',
                'all_runtime_y' => $allRuntimeY,
                'runtime_gap_matrix_hash' => $hash,
                'runtime_promotion_receipt' => [
                    'status' => $allRuntimeY ? 'passed' : 'blocked',
                    'receipt_hash' => $hash,
                ],
            ],
            'real_provider_smoke' => [
                'status' => $smokeStatus,
                'smoke_hash' => $hash,
            ],
            'human_signed_completion_receipt' => [
                'status' => 'blocked_missing_operator_receipt',
                'receipt_hash' => '',
            ],
            'operator_action_packet' => [
                'human_completion_receipt_template' => [
                    'certification_status_batch_hash' => $hash,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function fakeReceipt(): array
    {
        $hash = str_repeat('a', 64);
        $receipt = [
            'receipt_id' => 'test-receipt-1',
            'signed_by' => 'Vitore Test Operator',
            'reason' => 'Operator reviewed the final audit, runtime, smoke, dossier and replay in this test context.',
            'completion_audit_hash' => $hash,
            'release_dossier_hash' => $hash,
            'replay_diff_hash' => $hash,
            'runtime_gap_matrix_hash' => $hash,
            'runtime_promotion_receipt_hash' => $hash,
            'real_provider_smoke_hash' => $hash,
            'certification_status_batch_hash' => $hash,
            'receipt_hash' => '',
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ];
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        return $receipt;
    }
}
