<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeOfflineHarnessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeReplayDiffService;
use Tests\TestCase;

final class AtlasSelfConstructionRealProviderSmokeOfflineHarnessTest extends TestCase
{
    public function test_harness_generates_deterministic_dry_run_scenario(): void
    {
        $service = new AtlasSelfConstructionRealProviderSmokeOfflineHarnessService;
        $a = $service->build();
        $b = $service->build();

        $this->assertSame(AtlasSelfConstructionRealProviderSmokeOfflineHarnessService::SCHEMA_VERSION, $a['schema_version']);
        $this->assertSame('ready_for_operator_real_provider_smoke', $a['status']);
        $this->assertTrue((bool) $a['dry_run_only']);
        $this->assertSame($a['harness_hash'], $b['harness_hash']);
        $this->assertContains('provider_run_id', $a['required_evidence_fields']);
        $this->assertContains('provider_called_by_atlas', $a['forbidden_flags']);
    }

    public function test_verifier_rejects_missing_operator_evidence_ack(): void
    {
        $payload = $this->validSmoke(['operator_supplied_evidence' => false]);
        $payload['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($payload);

        $result = $this->verifier()->verify($payload);

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'operator_supplied_evidence_ack_missing');
    }

    public function test_verifier_rejects_hash_mismatch(): void
    {
        $payload = $this->validSmoke(['smoke_hash' => str_repeat('0', 64)]);

        $result = $this->verifier()->verify($payload);

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'smoke_hash_mismatch');
    }

    public function test_verifier_rejects_provider_called_by_atlas(): void
    {
        $payload = $this->validSmoke(['provider_called_by_atlas' => true]);
        $payload['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($payload);

        $result = $this->verifier()->verify($payload);

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'atlas_runtime_flag_forbidden_in_operator_smoke');
    }

    public function test_verifier_rejects_token_spent_by_atlas(): void
    {
        $payload = $this->validSmoke(['token_spent_by_atlas' => true]);
        $payload['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($payload);

        $result = $this->verifier()->verify($payload);

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'atlas_runtime_flag_forbidden_in_operator_smoke');
    }

    public function test_verifier_rejects_dispatch_allowed(): void
    {
        $payload = $this->validSmoke(['dispatch_allowed' => true]);
        $payload['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($payload);

        $result = $this->verifier()->verify($payload);

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'atlas_runtime_flag_forbidden_in_operator_smoke');
    }

    public function test_verifier_rejects_adapter_execution_allowed(): void
    {
        $payload = $this->validSmoke(['adapter_execution_allowed' => true]);
        $payload['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($payload);

        $result = $this->verifier()->verify($payload);

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'atlas_runtime_flag_forbidden_in_operator_smoke');
    }

    public function test_verifier_accepts_valid_operator_supplied_smoke(): void
    {
        $result = $this->verifier()->verify($this->validSmoke());

        $this->assertSame('verified_operator_supplied_real_provider_smoke_evidence', $result['status']);
        $this->assertSame(0, $result['violation_count']);
        $this->assertTrue((bool) $result['completion_criterion_green']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['verification_hash']);
    }

    public function test_replay_diff_detects_protected_field_mutation(): void
    {
        $before = $this->validSmoke();
        $after = $before;
        $after['provider_response_hash'] = str_repeat('b', 64);

        $result = $this->replayDiff()->compare($before, $after);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('provider_response_hash', $result['mutations'][0]['field']);
    }

    public function test_replay_diff_detects_forbidden_runtime_flag(): void
    {
        $before = $this->validSmoke();
        $after = $before;
        $after['dispatch_allowed'] = true;

        $result = $this->replayDiff()->compare($before, $after);

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'forbidden_runtime_flag_true_after_replay', 'mutations');
    }

    public function test_replay_diff_accepts_allowed_metadata_addition(): void
    {
        $before = $this->validSmoke();
        $after = $before + ['operator_note' => 'Reviewed in terminal recording.'];

        $result = $this->replayDiff()->compare($before, $after);

        $this->assertSame('passed', $result['status']);
        $this->assertTrue((bool) $result['replay_diff_green']);
    }

    private function verifier(): AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService
    {
        return new AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService;
    }

    private function replayDiff(): AtlasSelfConstructionRealProviderSmokeReplayDiffService
    {
        return new AtlasSelfConstructionRealProviderSmokeReplayDiffService;
    }

    /** @param array<string, mixed> $overrides */
    private function validSmoke(array $overrides = []): array
    {
        $payload = array_merge([
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => 'provider-run-001',
            'task_packet_id' => 'task-packet-001',
            'observed_by' => 'Vitore Operator',
            'approval_reason' => 'Operator observed the bounded real provider smoke.',
            'smoke_hash' => '',
            'operator_approval_receipt_hash' => str_repeat('1', 64),
            'evidence_ledger_hash' => str_repeat('2', 64),
            'work_product_manifest_hash' => str_repeat('3', 64),
            'cost_event_hash' => str_repeat('4', 64),
            'continuation_summary_hash' => str_repeat('5', 64),
            'provider_response_hash' => str_repeat('6', 64),
            'provider_call_observed' => true,
            'token_spend_observed' => true,
            'claim_to_completion_observed' => true,
            'work_product_collected' => true,
            'operator_supplied_evidence' => true,
            'real_provider_run_observed_by_operator' => true,
            'provider_called_by_atlas' => false,
            'token_spent_by_atlas' => false,
            'dispatch_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_promoted_without_receipt' => false,
        ], $overrides);
        if (! array_key_exists('smoke_hash', $overrides)) {
            $payload['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($payload);
        }

        return $payload;
    }

    private function assertViolation(array $result, string $code, string $key = 'violations'): void
    {
        $codes = array_map(static fn (array $violation): string => (string) ($violation['code'] ?? ''), (array) $result[$key]);

        $this->assertContains($code, $codes);
    }
}
