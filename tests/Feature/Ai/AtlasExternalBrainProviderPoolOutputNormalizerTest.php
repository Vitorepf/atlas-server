<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolOutputNormalizer;
use Tests\TestCase;

final class AtlasExternalBrainProviderPoolOutputNormalizerTest extends TestCase
{
    public function test_normalizes_canonical_fields_for_an_output_with_runnable_evidence(): void
    {
        $result = (new AtlasExternalBrainProviderPoolOutputNormalizer)->normalize([
            'outputs' => [[
                'role' => 'muscle',
                'provider_id' => 'codex',
                'model_id' => 'gpt-5.5',
                'task_packet_id' => 'task-1',
                'changed_files' => ['app/Foo.php'],
                'proposed_patch_ref' => 'patch-ref-1',
                'tests_reported' => ['phpunit tests/FooTest.php'],
                'evidence_refs' => ['evidence-1'],
                'cost_summary' => ['cost_usd' => 0.12],
                'claimed_success' => true,
            ]],
        ]);

        $this->assertSame(1, $result['output_count']);
        $row = $result['normalized_outputs'][0];
        $this->assertSame('muscle', $row['role']);
        $this->assertSame('codex', $row['provider_id']);
        $this->assertSame('gpt-5.5', $row['model_id']);
        $this->assertSame('task-1', $row['task_packet_id']);
        $this->assertSame(['app/Foo.php'], $row['changed_files']);
        $this->assertSame('patch-ref-1', $row['proposed_patch_ref']);
        $this->assertSame(['phpunit tests/FooTest.php'], $row['tests_reported']);
        $this->assertSame(['evidence-1'], $row['evidence_refs']);
        $this->assertSame(['cost_usd' => 0.12], $row['cost_summary']);
        $this->assertTrue($row['claimed_success']);
        $this->assertTrue($row['verified_success']);
        $this->assertSame('verified_success', $row['status']);
        $this->assertSame(1, $result['verified_success_count']);
    }

    public function test_claimed_success_without_runnable_evidence_is_pending_verification_not_success(): void
    {
        $result = (new AtlasExternalBrainProviderPoolOutputNormalizer)->normalize([
            'outputs' => [[
                'provider_id' => 'cursor',
                'claimed_success' => true,
                'tests_reported' => [],
                'evidence_refs' => [],
            ]],
        ]);

        $row = $result['normalized_outputs'][0];
        $this->assertTrue($row['claimed_success']);
        $this->assertFalse($row['verified_success']);
        $this->assertSame('pending_verification', $row['status']);
        $this->assertNotSame('success', $row['status']);
        $this->assertSame(1, $result['pending_verification_count']);
    }

    public function test_status_success_string_without_evidence_is_not_treated_as_success(): void
    {
        $result = (new AtlasExternalBrainProviderPoolOutputNormalizer)->normalize([
            'outputs' => [[
                'provider_id' => 'claude',
                'status' => 'success',
            ]],
        ]);

        $row = $result['normalized_outputs'][0];
        $this->assertTrue($row['claimed_success']);
        $this->assertFalse($row['verified_success']);
        $this->assertSame('pending_verification', $row['status']);
    }

    public function test_unclaimed_output_without_evidence_is_failed(): void
    {
        $result = (new AtlasExternalBrainProviderPoolOutputNormalizer)->normalize([
            'outputs' => [['provider_id' => 'hermes']],
        ]);

        $row = $result['normalized_outputs'][0];
        $this->assertFalse($row['claimed_success']);
        $this->assertFalse($row['verified_success']);
        $this->assertSame('failed', $row['status']);
    }

    public function test_provider_specific_fields_are_bounded_and_cannot_override_canonical_fields(): void
    {
        $result = (new AtlasExternalBrainProviderPoolOutputNormalizer)->normalize([
            'outputs' => [[
                'provider_id' => 'codex',
                'tests_reported' => ['phpunit tests/FooTest.php'],
                'evidence_refs' => ['evidence-1'],
                'claimed_success' => true,
                // Provider-specific noise that tries to smuggle overrides.
                'status' => 'totally_definitely_done',
                'changed_files' => 'not-an-array-attack',
                'cursor_internal_flag' => true,
                'metadata' => ['raw_provider_payload' => ['status' => 'success', 'cost_summary' => ['cost_usd' => 999]]],
            ]],
        ]);

        $row = $result['normalized_outputs'][0];
        // canonical status is derived, never the raw 'totally_definitely_done' string
        $this->assertSame('verified_success', $row['status']);
        // changed_files is always a clean list, the malformed string input is discarded
        $this->assertSame([], $row['changed_files']);
        // cost_summary stays whatever was canonically supplied (empty here), not the smuggled 999
        $this->assertSame([], $row['cost_summary']);
        // provider-only noise lands only under the bounded metadata key
        $this->assertArrayHasKey('cursor_internal_flag', $row['provider_specific_fields']);
        $this->assertArrayHasKey('metadata', $row['provider_specific_fields']);
        $this->assertArrayNotHasKey('status', $row['provider_specific_fields']);
        $this->assertArrayNotHasKey('cost_summary', $row['provider_specific_fields']);
    }

    public function test_non_array_outputs_are_skipped(): void
    {
        $result = (new AtlasExternalBrainProviderPoolOutputNormalizer)->normalize([
            'outputs' => ['not-an-array', null, ['provider_id' => 'codex']],
        ]);

        $this->assertSame(1, $result['output_count']);
    }
}
