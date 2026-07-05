<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ReadinessCompletionClaimAuthority;
use Tests\TestCase;

final class ReadinessCompletionClaimAuthorityTest extends TestCase
{
    // ── Schema version via external_completion_claim_policy_status ────

    public function test_aliases_contains_hash_with_sha256_format(): void
    {
        $result = ReadinessCompletionClaimAuthority::aliases([], 'operator-artifact-v1');

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['external_completion_claim_policy_hash']);
    }

    public function test_aliases_rejects_external_completion_claims(): void
    {
        $result = ReadinessCompletionClaimAuthority::aliases([], 'operator-artifact-v1');

        self::assertFalse($result['completion_claim_external_agent_claim_accepted']);
        self::assertFalse($result['completion_claim_external_agent_claim_can_mark_os_complete']);
        self::assertFalse($result['completion_claim_external_agent_claim_can_override_audit']);
        self::assertSame('reject_external_completion_claim', $result['external_completion_claim_policy_status']);
    }

    public function test_aliases_surfaces_required_operator_artifact(): void
    {
        $result = ReadinessCompletionClaimAuthority::aliases([], 'operator-signed-receipt-v2');

        self::assertSame(
            'operator-signed-receipt-v2',
            $result['external_completion_claim_policy_current_required_operator_artifact'],
        );
    }

    public function test_aliases_reports_failed_count(): void
    {
        $result = ReadinessCompletionClaimAuthority::aliases(
            ['runtime_gap_matrix_not_ready', 'human_receipt_missing'],
            'artifact',
        );

        self::assertSame(2, $result['external_completion_claim_policy_current_failed_count']);
    }

    public function test_aliases_reports_minimum_one_when_empty(): void
    {
        $result = ReadinessCompletionClaimAuthority::aliases([], 'artifact');

        // max(1, 0) = 1
        self::assertSame(1, $result['external_completion_claim_policy_current_failed_count']);
    }

    public function test_aliases_returns_completion_authority_name(): void
    {
        $result = ReadinessCompletionClaimAuthority::aliases([], 'artifact');

        self::assertSame(
            'atlas_self_construction_os_completion_audit',
            $result['completion_claim_authority'],
        );
        self::assertSame(
            'atlas_self_construction_os_completion_audit',
            $result['external_completion_claim_policy_completion_authority'],
        );
    }

    public function test_aliases_returns_required_completion_predicate(): void
    {
        $result = ReadinessCompletionClaimAuthority::aliases([], 'artifact');

        self::assertStringContainsString(
            'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            $result['completion_claim_required_completion_predicate'],
        );
    }

    // ── Hash stability and sensitivity ─────────────────────────────────

    public function test_aliases_hash_is_deterministic(): void
    {
        $a = ReadinessCompletionClaimAuthority::aliases([], 'artifact');
        $b = ReadinessCompletionClaimAuthority::aliases([], 'artifact');

        self::assertSame(
            $a['external_completion_claim_policy_hash'],
            $b['external_completion_claim_policy_hash'],
        );
    }

    public function test_aliases_hash_differs_for_different_failed_criteria(): void
    {
        $a = ReadinessCompletionClaimAuthority::aliases([], 'artifact');
        $b = ReadinessCompletionClaimAuthority::aliases(
            ['some_failed_criterion'],
            'artifact',
        );

        self::assertNotSame(
            $a['external_completion_claim_policy_hash'],
            $b['external_completion_claim_policy_hash'],
        );
    }

    public function test_aliases_hash_differs_for_different_operator_artifacts(): void
    {
        $a = ReadinessCompletionClaimAuthority::aliases([], 'artifact-v1');
        $b = ReadinessCompletionClaimAuthority::aliases([], 'artifact-v2');

        self::assertNotSame(
            $a['external_completion_claim_policy_hash'],
            $b['external_completion_claim_policy_hash'],
        );
    }
}
