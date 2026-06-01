<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowRunbookV1Part02Service;
use Tests\TestCase;

/**
 * Pins the implementation-slice invariants from Atlas Dev Efficient Programming
 * Flow Runbook v1 · Parte 2 (6.2 PR plan, 6.3 Fatia 0 DoD). Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-02.md
 */
class AtlasDevEfficientProgrammingFlowRunbookV1Part02Test extends TestCase
{
    private function service(): AtlasDevEfficientProgrammingFlowRunbookV1Part02Service
    {
        return new AtlasDevEfficientProgrammingFlowRunbookV1Part02Service;
    }

    /**
     * PR 0.2 invariant 1 (worked example, doc lines 216-226): a CompactSdd at
     * R4 with mode=patch is invalid and emits the exact documented violation;
     * R4 with mode=escalate_preview is valid; R2 (below the threshold) has no
     * mode constraint.
     */
    public function test_compact_sdd_r4_r5_force_escalate_preview_mode(): void
    {
        $service = $this->service();

        $r4Patch = $service->evaluateCompactSddRiskMode('R4', 'patch');
        $this->assertFalse($r4Patch['valid']);
        $this->assertContains('R4|R5 requires mode=escalate_preview', $r4Patch['violations']);
        $this->assertSame('escalate_preview', $r4Patch['forced_mode']);

        $r5Patch = $service->evaluateCompactSddRiskMode('R5', 'fast_path');
        $this->assertFalse($r5Patch['valid']);
        $this->assertContains('R4|R5 requires mode=escalate_preview', $r5Patch['violations']);

        $r4Ok = $service->evaluateCompactSddRiskMode('R4', 'escalate_preview');
        $this->assertTrue($r4Ok['valid']);
        $this->assertSame([], $r4Ok['violations']);

        // R2 is below the high-risk threshold: any mode is allowed.
        $r2 = $service->evaluateCompactSddRiskMode('R2', 'patch');
        $this->assertTrue($r2['valid']);
        $this->assertNull($r2['forced_mode']);
    }

    /**
     * PR 0.2 DoD: exactly the 4 canonical surface_ids are accepted; an unknown
     * surface is non-canonical.
     */
    public function test_operation_envelope_has_exactly_four_canonical_surfaces(): void
    {
        $service = $this->service();

        foreach (['atlas_desktop_ai', 'atlas_cli_dev', 'atlas_app', 'atlas_api_interaction'] as $surface) {
            $this->assertTrue($service->evaluateSurfaceId($surface)['canonical'], $surface.' must be canonical');
        }

        $bogus = $service->evaluateSurfaceId('atlas_unknown_surface');
        $this->assertFalse($bogus['canonical']);
        $this->assertCount(4, $bogus['allowed']);
    }

    /**
     * PR 0.4 VerificationReceipt invariant 1: passed requires all required gates
     * passed AND scope_guard passed AND (tests ran OR a no_patch_reason). A
     * missing gate or a failed scope_guard blocks `passed`.
     */
    public function test_verification_passed_requires_all_gates_scope_and_evidence(): void
    {
        $service = $this->service();

        $required = ['mini_spec_before_code_gate', 'scope_guard_light', 'verification_gate'];
        $allPassed = [
            'mini_spec_before_code_gate' => 'passed',
            'scope_guard_light' => 'passed',
            'verification_gate' => 'passed',
        ];

        $clean = $service->evaluateVerificationCompletion($required, $allPassed, 'passed', true);
        $this->assertTrue($clean['valid']);
        $this->assertSame('passed', $clean['status']);
        $this->assertSame([], $clean['violations']);

        // A required gate not passed -> cannot be passed, gate is named.
        $missingGate = $service->evaluateVerificationCompletion(
            $required,
            ['mini_spec_before_code_gate' => 'passed', 'scope_guard_light' => 'passed', 'verification_gate' => 'failed'],
            'passed',
            true,
        );
        $this->assertFalse($missingGate['valid']);
        $this->assertContains('verification_gate', $missingGate['missing_gates']);

        // scope_guard not passed -> blocked.
        $scopeBad = $service->evaluateVerificationCompletion($required, $allPassed, 'needs_review', true);
        $this->assertFalse($scopeBad['valid']);
        $this->assertContains('scope_guard_not_passed', $scopeBad['violations']);

        // No tests and no no_patch_reason -> blocked; but a no_patch_reason rescues it.
        $noEvidence = $service->evaluateVerificationCompletion($required, $allPassed, 'passed', false, null);
        $this->assertFalse($noEvidence['valid']);
        $this->assertContains('no_tests_and_no_no_patch_reason', $noEvidence['violations']);

        $readOnly = $service->evaluateVerificationCompletion($required, $allPassed, 'passed', false, 'read_only_no_patch_needed');
        $this->assertTrue($readOnly['valid']);
    }

    /**
     * PR 0.4 VerificationReceipt invariant 2: passed + non-empty honesty_flags
     * is invalid.
     */
    public function test_verification_passed_with_honesty_flag_is_invalid(): void
    {
        $service = $this->service();

        $required = ['verification_gate'];
        $result = $service->evaluateVerificationCompletion(
            $required,
            ['verification_gate' => 'passed'],
            'passed',
            true,
            null,
            ['uncertain_about_edge_case'],
        );

        $this->assertFalse($result['valid']);
        $this->assertContains('passed_with_honesty_flag', $result['violations']);
        $this->assertFalse($result['honesty_clean']);
    }

    /**
     * PR 0.4 FailureCapsule: decision=retry only while attempt_index < max_attempts;
     * at the cap the only legal decision is escalate. And failure_signature is
     * deterministic for a given tuple.
     */
    public function test_failure_capsule_caps_retries_then_escalates(): void
    {
        $service = $this->service();

        $firstAttempt = $service->failureCapsuleDecision(0, 2);
        $this->assertTrue($firstAttempt['retry_allowed']);
        $this->assertSame('retry', $firstAttempt['decision']);
        $this->assertSame(2, $firstAttempt['attempts_remaining']);

        $lastBeforeCap = $service->failureCapsuleDecision(1, 2);
        $this->assertSame('retry', $lastBeforeCap['decision']);

        $atCap = $service->failureCapsuleDecision(2, 2);
        $this->assertFalse($atCap['retry_allowed']);
        $this->assertSame('escalate', $atCap['decision']);
        $this->assertSame(0, $atCap['attempts_remaining']);

        // Deterministic signature.
        $a = $service->failureSignature('verification_gate', 'assert_failed', 'phpunit');
        $b = $service->failureSignature('verification_gate', 'assert_failed', 'phpunit');
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $service->failureSignature('scope_guard', 'assert_failed', 'phpunit'));
    }

    /**
     * PR 0.2 test 5: the canonical hash ignores the DTO's own `<entity>_hash`
     * field — two identical payloads differing only in that field hash equal,
     * while a change to a real field changes the hash.
     */
    public function test_hash_ignores_self_hash_field_but_reacts_to_real_changes(): void
    {
        $service = $this->service();

        $base = ['risk_level' => 'R2', 'mode' => 'patch', 'sdd_hash' => 'aaaa'];
        $sameButDifferentSelfHash = ['risk_level' => 'R2', 'mode' => 'patch', 'sdd_hash' => 'zzzz'];

        $this->assertTrue(
            $service->hashesMatchIgnoringSelfHash($base, $sameButDifferentSelfHash, 'sdd_hash'),
            'payloads differing only in the self-hash field must hash equal',
        );

        $changedField = ['risk_level' => 'R3', 'mode' => 'patch', 'sdd_hash' => 'aaaa'];
        $this->assertNotSame(
            $service->canonicalHash($base, 'sdd_hash'),
            $service->canonicalHash($changedField, 'sdd_hash'),
            'changing a real field must change the hash',
        );
    }

    /**
     * 6.3 DoD da Fatia 0: green only when all 7 conditions are true; one unmet
     * condition keeps it not-green and is named.
     */
    public function test_fatia0_dod_is_green_only_when_all_seven_conditions_hold(): void
    {
        $service = $this->service();

        $all = [
            'tests_green' => true,
            'lint_clean' => true,
            'phpstan_clean' => true,
            'coverage_min_95' => true,
            'index_code_ok' => true,
            'provider_safe_memory_ok' => true,
            'no_real_provider' => true,
        ];
        $green = $service->evaluateFatia0Dod($all);
        $this->assertTrue($green['green']);
        $this->assertSame([], $green['unmet']);
        $this->assertCount(7, $green['required']);

        $oneMissing = $all;
        $oneMissing['coverage_min_95'] = false;
        $notGreen = $service->evaluateFatia0Dod($oneMissing);
        $this->assertFalse($notGreen['green']);
        $this->assertContains('coverage_min_95', $notGreen['unmet']);
    }
}
