<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolOutputNormalizer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainProviderPoolOutputNormalizerTest extends TestCase
{
    private function normalizer(): AtlasExternalBrainProviderPoolOutputNormalizer
    {
        return new AtlasExternalBrainProviderPoolOutputNormalizer;
    }

    private function verifiedOutput(array $overrides = []): array
    {
        return array_merge([
            'provider_id' => 'codex',
            'claimed_success' => true,
            'tests_reported' => ['phpunit tests/FooTest.php'],
            'evidence_refs' => ['evidence-1'],
        ], $overrides);
    }

    // ── AC2: local, subscription_ui and internal_runtime normalize to the same envelope shape ──

    public function test_local_subscription_ui_and_internal_runtime_share_identical_envelope_shape(): void
    {
        $local = $this->normalizer()->normalize(['outputs' => [$this->verifiedOutput(['client_class' => 'local'])]]);
        $subscriptionUi = $this->normalizer()->normalize(['outputs' => [$this->verifiedOutput(['client_class' => 'subscription_ui'])]]);
        $internalRuntime = $this->normalizer()->normalize(['outputs' => [$this->verifiedOutput(['client_class' => 'internal_runtime'])]]);

        $localKeys = array_keys($local['normalized_outputs'][0]);
        $subscriptionUiKeys = array_keys($subscriptionUi['normalized_outputs'][0]);
        $internalRuntimeKeys = array_keys($internalRuntime['normalized_outputs'][0]);

        sort($localKeys);
        sort($subscriptionUiKeys);
        sort($internalRuntimeKeys);

        $this->assertSame($localKeys, $subscriptionUiKeys);
        $this->assertSame($subscriptionUiKeys, $internalRuntimeKeys);

        $this->assertSame('local', $local['normalized_outputs'][0]['client_class']);
        $this->assertSame('subscription_ui', $subscriptionUi['normalized_outputs'][0]['client_class']);
        $this->assertSame('internal_runtime', $internalRuntime['normalized_outputs'][0]['client_class']);
    }

    public function test_unrecognized_client_class_normalizes_to_unknown(): void
    {
        $result = $this->normalizer()->normalize(['outputs' => [$this->verifiedOutput(['client_class' => 'some_weird_client'])]]);

        $this->assertSame('unknown', $result['normalized_outputs'][0]['client_class']);
    }

    public function test_missing_client_class_normalizes_to_unknown(): void
    {
        $result = $this->normalizer()->normalize(['outputs' => [$this->verifiedOutput()]]);

        $this->assertSame('unknown', $result['normalized_outputs'][0]['client_class']);
    }

    // ── AC3: weak/malformed outputs include repairable_failure reasons and proof hints ──

    public function test_unclaimed_output_includes_repairable_failure_reasons_and_proof_hints(): void
    {
        $result = $this->normalizer()->normalize(['outputs' => [['provider_id' => 'hermes']]]);
        $row = $result['normalized_outputs'][0];

        $this->assertSame('failed', $row['status']);
        $this->assertTrue($row['repairable_failure']);
        $this->assertContains('provider_did_not_claim_success', $row['repairable_failure_reasons']);
        $this->assertContains('missing_tests_reported', $row['repairable_failure_reasons']);
        $this->assertContains('missing_evidence_refs', $row['repairable_failure_reasons']);
        $this->assertNotEmpty($row['proof_hints']);
        $this->assertContains('have_the_provider_explicitly_report_claimed_success_true_with_proof', $row['proof_hints']);
    }

    public function test_pending_verification_output_includes_repairable_failure_reasons(): void
    {
        $result = $this->normalizer()->normalize(['outputs' => [[
            'provider_id' => 'cursor',
            'claimed_success' => true,
            'tests_reported' => [],
            'evidence_refs' => [],
        ]]]);
        $row = $result['normalized_outputs'][0];

        $this->assertSame('pending_verification', $row['status']);
        $this->assertTrue($row['repairable_failure']);
        $this->assertContains('missing_tests_reported', $row['repairable_failure_reasons']);
        $this->assertContains('missing_evidence_refs', $row['repairable_failure_reasons']);
        $this->assertContains('attach_a_runnable_test_command_to_tests_reported', $row['proof_hints']);
    }

    public function test_malformed_changed_files_field_is_named_in_repairable_failure_reasons(): void
    {
        $result = $this->normalizer()->normalize(['outputs' => [[
            'provider_id' => 'codex',
            'changed_files' => 'not-an-array',
        ]]]);
        $row = $result['normalized_outputs'][0];

        $this->assertContains('malformed_changed_files_field', $row['repairable_failure_reasons']);
        $this->assertContains('resubmit_changed_files_as_a_list_of_file_paths', $row['proof_hints']);
    }

    public function test_verified_success_output_has_no_repairable_failure_reasons(): void
    {
        $result = $this->normalizer()->normalize(['outputs' => [$this->verifiedOutput()]]);
        $row = $result['normalized_outputs'][0];

        $this->assertFalse($row['repairable_failure']);
        $this->assertSame([], $row['repairable_failure_reasons']);
        $this->assertSame([], $row['proof_hints']);
    }

    // ── AC4: provider-specific metadata is redacted from the normalized envelope ──

    public function test_sensitive_keys_inside_provider_specific_fields_are_redacted(): void
    {
        $result = $this->normalizer()->normalize(['outputs' => [$this->verifiedOutput([
            'metadata' => [
                'api_key' => 'sk-should-not-leak',
                'raw_prompt' => 'the full raw prompt',
                'note' => 'harmless',
            ],
            'auth_token' => 'bearer-xyz',
        ])]]);
        $row = $result['normalized_outputs'][0];

        $this->assertArrayNotHasKey('api_key', $row['provider_specific_fields']['metadata']);
        $this->assertArrayNotHasKey('raw_prompt', $row['provider_specific_fields']['metadata']);
        $this->assertSame('harmless', $row['provider_specific_fields']['metadata']['note']);
        $this->assertArrayNotHasKey('auth_token', $row['provider_specific_fields']);
        $this->assertContains('api_key', $row['redacted_provider_fields']);
        $this->assertContains('raw_prompt', $row['redacted_provider_fields']);
        $this->assertContains('auth_token', $row['redacted_provider_fields']);
    }

    public function test_bounded_provider_specific_top_level_keys_remain_present_when_not_sensitive(): void
    {
        $result = $this->normalizer()->normalize(['outputs' => [$this->verifiedOutput([
            'cursor_internal_flag' => true,
            'metadata' => ['harmless_note' => 'ok'],
        ])]]);
        $row = $result['normalized_outputs'][0];

        $this->assertArrayHasKey('cursor_internal_flag', $row['provider_specific_fields']);
        $this->assertArrayHasKey('metadata', $row['provider_specific_fields']);
        $this->assertSame([], $row['redacted_provider_fields']);
    }

    public function test_no_sensitive_fields_reports_empty_redacted_provider_fields(): void
    {
        $result = $this->normalizer()->normalize(['outputs' => [$this->verifiedOutput()]]);
        $row = $result['normalized_outputs'][0];

        $this->assertSame([], $row['redacted_provider_fields']);
    }

    // ── determinism ────────────────────────────────────────────────────────────

    public function test_normalize_is_deterministic(): void
    {
        $facts = ['outputs' => [$this->verifiedOutput(['client_class' => 'local', 'metadata' => ['token' => 'x']])]];

        $this->assertSame(
            $this->normalizer()->normalize($facts),
            $this->normalizer()->normalize($facts),
        );
    }
}
