<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionAntiFraudMatrixService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceLockfileService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionFinalEvidenceBundleService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionFinalEvidenceReplayService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Tests\TestCase;

final class AtlasSelfConstructionFinalEvidenceReplayAntiFraudTest extends TestCase
{
    public function test_final_evidence_replay_passes_valid_bundle(): void
    {
        $bundle = $this->bundle();
        $replay = (new AtlasSelfConstructionFinalEvidenceReplayService)->replay($bundle);

        $this->assertSame('atlas.self_construction.final_evidence_replay.v1', $replay['schema_version']);
        $this->assertSame('passed', $replay['status']);
        $this->assertTrue($replay['replay_green']);
        $this->assertSame(0, $replay['violation_count']);
        $this->assertSame($bundle['bundle_identity']['bundle_hash'], $replay['expected_bundle_hash']);
        $this->assertFalse($replay['execution_allowed']);
        $this->assertFalse($replay['dispatch_allowed']);
        $this->assertFalse($replay['provider_call_allowed']);
    }

    public function test_final_evidence_replay_blocks_bundle_hash_mismatch(): void
    {
        $bundle = $this->bundle();
        $bundle['bundle_identity']['bundle_hash'] = str_repeat('0', 64);

        $replay = (new AtlasSelfConstructionFinalEvidenceReplayService)->replay($bundle);

        $this->assertSame('blocked', $replay['status']);
        $this->assertContains('final_bundle_hash_mismatch', array_column($replay['violations'], 'code'));
    }

    public function test_final_evidence_replay_blocks_missing_required_component(): void
    {
        $bundle = $this->bundle();
        $bundle['component_registry']['runtime_gap_matrix']['available'] = false;
        $this->rehashBundle($bundle);

        $replay = (new AtlasSelfConstructionFinalEvidenceReplayService)->replay($bundle);

        $this->assertSame('blocked', $replay['status']);
        $this->assertContains('required_final_bundle_component_missing', array_column($replay['violations'], 'code'));
    }

    public function test_final_evidence_replay_blocks_safety_false(): void
    {
        $bundle = $this->bundle();
        $bundle['safety_invariants']['no_dispatch'] = false;
        $this->rehashBundle($bundle);

        $replay = (new AtlasSelfConstructionFinalEvidenceReplayService)->replay($bundle);

        $this->assertSame('blocked', $replay['status']);
        $this->assertContains('final_bundle_safety_invariant_false', array_column($replay['violations'], 'code'));
    }

    public function test_final_evidence_replay_blocks_completion_allowed_with_failed_criteria(): void
    {
        $bundle = $this->bundle([
            'runtime_all_y' => true,
            'human_receipt_passed' => true,
            'real_provider_smoke_passed' => true,
            'completion_audit_complete' => true,
            'failed_criteria' => [],
        ]);
        $bundle['evidence_dependencies']['failed_criteria'] = ['human_signed_os_complete_receipt_present'];
        $this->rehashBundle($bundle);

        $replay = (new AtlasSelfConstructionFinalEvidenceReplayService)->replay($bundle);

        $this->assertSame('blocked', $replay['status']);
        $this->assertContains('final_completion_allowed_with_failed_criteria', array_column($replay['violations'], 'code'));
    }

    public function test_anti_fraud_matrix_passes_clean_evidence(): void
    {
        $matrix = (new AtlasSelfConstructionCompletionAntiFraudMatrixService)->evaluate($this->cleanEvidence());

        $this->assertSame('atlas.self_construction.completion_anti_fraud_matrix.v1', $matrix['schema_version']);
        $this->assertSame('passed', $matrix['status']);
        $this->assertSame(0, $matrix['failure_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $matrix['anti_fraud_matrix_hash']);
        $this->assertFalse($matrix['execution_allowed']);
    }

    public function test_anti_fraud_matrix_detects_fake_human_signature(): void
    {
        $matrix = (new AtlasSelfConstructionCompletionAntiFraudMatrixService)->evaluate($this->cleanEvidence(['signed_by' => 'codex']));

        $this->assertSame('blocked', $matrix['status']);
        $this->assertContains('fake_human_signature', array_column($matrix['failures'], 'code'));
    }

    public function test_anti_fraud_matrix_detects_fake_provider_smoke(): void
    {
        $matrix = (new AtlasSelfConstructionCompletionAntiFraudMatrixService)->evaluate($this->cleanEvidence(['provider_call_observed' => false]));

        $this->assertSame('blocked', $matrix['status']);
        $this->assertContains('fake_provider_smoke', array_column($matrix['failures'], 'code'));
    }

    public function test_anti_fraud_matrix_detects_runtime_autopromotion(): void
    {
        $matrix = (new AtlasSelfConstructionCompletionAntiFraudMatrixService)->evaluate($this->cleanEvidence(['runtime_autopromotion' => true]));

        $this->assertContains('runtime_autopromotion', array_column($matrix['failures'], 'code'));
    }

    public function test_anti_fraud_matrix_detects_completion_autopromotion(): void
    {
        $matrix = (new AtlasSelfConstructionCompletionAntiFraudMatrixService)->evaluate($this->cleanEvidence(['completion_autopromoted' => true]));

        $this->assertContains('completion_autopromotion', array_column($matrix['failures'], 'code'));
    }

    public function test_anti_fraud_matrix_detects_token_spend_without_evidence(): void
    {
        $matrix = (new AtlasSelfConstructionCompletionAntiFraudMatrixService)->evaluate($this->cleanEvidence([
            'token_spend_observed' => true,
            'cost_event_hash' => '',
        ]));

        $this->assertContains('token_spend_without_evidence', array_column($matrix['failures'], 'code'));
    }

    public function test_anti_fraud_matrix_detects_dispatch_without_signed_policy(): void
    {
        $matrix = (new AtlasSelfConstructionCompletionAntiFraudMatrixService)->evaluate($this->cleanEvidence([
            'dispatch_allowed' => true,
            'signed_dispatch_policy_hash' => '',
        ]));

        $this->assertContains('dispatch_without_signed_policy', array_column($matrix['failures'], 'code'));
    }

    public function test_anti_fraud_matrix_detects_process_started_by_atlas(): void
    {
        $matrix = (new AtlasSelfConstructionCompletionAntiFraudMatrixService)->evaluate($this->cleanEvidence(['process_started_by_atlas' => true]));

        $this->assertContains('process_started_by_atlas_when_forbidden', array_column($matrix['failures'], 'code'));
    }

    public function test_anti_fraud_matrix_detects_mutated_hashes(): void
    {
        $matrix = (new AtlasSelfConstructionCompletionAntiFraudMatrixService)->evaluate($this->cleanEvidence(['hash_mutation_detected' => true]));

        $this->assertContains('mutated_hashes', array_column($matrix['failures'], 'code'));
    }

    public function test_anti_fraud_matrix_detects_missing_before_snapshot(): void
    {
        $matrix = (new AtlasSelfConstructionCompletionAntiFraudMatrixService)->evaluate($this->cleanEvidence(['before_snapshot_id' => '']));

        $this->assertContains('missing_before_snapshot', array_column($matrix['failures'], 'code'));
    }

    public function test_anti_fraud_matrix_detects_missing_operator_reason(): void
    {
        $matrix = (new AtlasSelfConstructionCompletionAntiFraudMatrixService)->evaluate($this->cleanEvidence(['operator_reason' => '']));

        $this->assertContains('missing_operator_reason', array_column($matrix['failures'], 'code'));
    }

    public function test_anti_fraud_matrix_detects_replay_mismatch(): void
    {
        $matrix = (new AtlasSelfConstructionCompletionAntiFraudMatrixService)->evaluate($this->cleanEvidence(['replay_mismatch' => true]));

        $this->assertContains('replay_mismatch', array_column($matrix['failures'], 'code'));
    }

    public function test_lockfile_is_deterministic_for_same_evidence(): void
    {
        $service = new AtlasSelfConstructionCompletionEvidenceLockfileService;
        $first = $service->build($this->lockEvidence());
        $second = $service->build($this->lockEvidence());

        $this->assertSame('atlas.self_construction.completion_evidence_lockfile.v1', $first['schema_version']);
        $this->assertSame($first['lockfile_hash'], $second['lockfile_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['lockfile_hash']);
        $this->assertFalse($first['persistence_allowed']);
    }

    public function test_lockfile_verifier_passes_matching_lockfile(): void
    {
        $service = new AtlasSelfConstructionCompletionEvidenceLockfileService;
        $lockfile = $service->build($this->lockEvidence());

        $verification = $service->verify($lockfile, $this->lockEvidence());

        $this->assertSame('passed', $verification['status']);
        $this->assertSame(0, $verification['violation_count']);
        $this->assertFalse($verification['provider_call_allowed']);
    }

    public function test_lockfile_verifier_rejects_evidence_hash_mutation(): void
    {
        $service = new AtlasSelfConstructionCompletionEvidenceLockfileService;
        $lockfile = $service->build($this->lockEvidence());

        $verification = $service->verify($lockfile, $this->lockEvidence(['runtime_gap_matrix_hash' => str_repeat('9', 64)]));

        $this->assertSame('blocked', $verification['status']);
        $this->assertContains('lockfile_evidence_hashes_mismatch', array_column($verification['violations'], 'code'));
    }

    public function test_lockfile_verifier_rejects_blocker_mutation(): void
    {
        $service = new AtlasSelfConstructionCompletionEvidenceLockfileService;
        $lockfile = $service->build($this->lockEvidence());

        $verification = $service->verify($lockfile, $this->lockEvidence(['failed_criteria' => ['human_signed_os_complete_receipt_present']]));

        $this->assertSame('blocked', $verification['status']);
        $this->assertContains('lockfile_blockers_mismatch', array_column($verification['violations'], 'code'));
    }

    public function test_lockfile_verifier_ignores_generated_at_noise(): void
    {
        $service = new AtlasSelfConstructionCompletionEvidenceLockfileService;
        $lockfile = $service->build($this->lockEvidence());
        $lockfile['generated_at'] = '2099-01-01T00:00:00+00:00';

        $verification = $service->verify($lockfile, $this->lockEvidence());

        $this->assertSame('passed', $verification['status']);
    }

    /** @param array<string, mixed> $overrides */
    private function bundle(array $overrides = []): array
    {
        $hash = str_repeat('a', 64);
        $runtimeAllY = (bool) ($overrides['runtime_all_y'] ?? false);
        $humanReceiptPassed = (bool) ($overrides['human_receipt_passed'] ?? false);
        $realProviderSmokePassed = (bool) ($overrides['real_provider_smoke_passed'] ?? false);
        $completionAuditComplete = (bool) ($overrides['completion_audit_complete'] ?? false);
        $failedCriteria = (array) ($overrides['failed_criteria'] ?? [
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
            'end_to_end_real_provider_smoke_green',
        ]);

        return (new AtlasSelfConstructionFinalEvidenceBundleService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'runtime_gap_matrix' => [
                'schema_version' => 'atlas.self_construction.runtime_gap_matrix.v1',
                'status' => $runtimeAllY ? 'passed' : 'blocked',
                'all_runtime_y' => $runtimeAllY,
                'runtime_gap_matrix_hash' => $hash,
            ],
            'human_receipt' => [
                'schema_version' => 'atlas.self_construction.human_signed_completion_receipt.v1',
                'status' => $humanReceiptPassed ? 'passed' : 'blocked_missing_operator_receipt',
                'completion_claim_allowed' => $humanReceiptPassed,
            ],
            'real_provider_smoke_result' => [
                'schema_version' => 'atlas.self_construction.real_provider_smoke_certification.v1',
                'status' => $realProviderSmokePassed ? 'passed' : 'blocked_missing_real_provider_smoke',
                'completion_criterion_green' => $realProviderSmokePassed,
            ],
            'operator_action_packet' => [
                'schema_version' => 'atlas.self_construction.completion_operator_action_packet.v1',
                'status' => $failedCriteria === [] ? 'ready_for_operator_final_review' : 'operator_action_required',
            ],
            'completion_audit' => [
                'schema_version' => 'atlas.self_construction.os_completion_audit.v1',
                'status' => $completionAuditComplete ? 'complete' : 'incomplete',
                'completion_allowed' => $completionAuditComplete,
                'failed_criteria' => $failedCriteria,
                'criteria' => [
                    ['id' => 'release_dossier_green', 'passed' => true],
                ],
            ],
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function cleanEvidence(array $overrides = []): array
    {
        return array_merge([
            'signed_by' => 'Vitorepf Operator',
            'operator_reason' => 'Reviewed all final evidence and smoke transcript.',
            'before_snapshot_id' => 'snap_01KRNBJNB54VTV35A7DHYNC7VW',
            'kind' => 'real_provider_packet_claim_to_completion',
            'provider_call_observed' => true,
            'real_provider_run_observed_by_operator' => true,
            'provider_response_hash' => str_repeat('b', 64),
            'cost_event_hash' => str_repeat('c', 64),
            'signed_dispatch_policy_hash' => str_repeat('d', 64),
            'final_completion_allowed' => false,
            'runtime_autopromotion' => false,
            'completion_autopromoted' => false,
            'token_spend_observed' => false,
            'dispatch_allowed' => false,
            'process_started_by_atlas' => false,
            'hash_mutation_detected' => false,
            'replay_mismatch' => false,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function lockEvidence(array $overrides = []): array
    {
        return array_merge([
            'bundle_hash' => str_repeat('a', 64),
            'completion_audit_hash' => str_repeat('b', 64),
            'runtime_gap_matrix_hash' => str_repeat('c', 64),
            'receipt_hash' => str_repeat('d', 64),
            'smoke_hash' => str_repeat('e', 64),
            'release_dossier_hash' => str_repeat('f', 64),
            'replay_diff_hash' => str_repeat('1', 64),
            'failed_criteria' => [
                'runtime_gap_matrix_all_runtime_y',
                'human_signed_os_complete_receipt_present',
                'end_to_end_real_provider_smoke_green',
            ],
        ], $overrides);
    }

    /** @param array<string, mixed> $bundle */
    private function rehashBundle(array &$bundle): void
    {
        $hashable = $bundle;
        unset($hashable['generated_at']);
        unset($hashable['assessed_at'], $hashable['audited_at']);
        unset($hashable['bundle_identity']['bundle_hash'], $hashable['bundle_identity']['bundle_id']);
        unset($hashable['machine_status']['final_bundle_hash']);
        unset($hashable['promotion_receipt_preimage']['receipt_id']);
        unset($hashable['runtime_promotion_receipt_template']['receipt_id']);
        unset($hashable['human_completion_receipt_template']['receipt_id']);

        $hash = hash('sha256', (string) json_encode($this->ksortRecursive($hashable), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $bundle['bundle_identity']['bundle_hash'] = $hash;
        $bundle['bundle_identity']['bundle_id'] = 'final-evidence-bundle-'.substr($hash, 0, 16);
        $bundle['machine_status']['final_bundle_hash'] = $hash;
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
