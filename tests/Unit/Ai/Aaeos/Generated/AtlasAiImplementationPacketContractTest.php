<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiImplementationPacketContractService as Svc;
use Tests\TestCase;

/**
 * Pins the documented AI Implementation Packet admission + completion rules:
 * Packet Schema closed sets, Hard Invariants, Rejected-Packet rules and the
 * Completion Criteria.
 *
 * @see docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md
 */
class AtlasAiImplementationPacketContractTest extends TestCase
{
    private function svc(): Svc
    {
        return new Svc();
    }

    /** A well-formed packet (the doc's Safe Packet Example shape) is admitted. */
    private function safePacket(array $override = []): array
    {
        return array_merge([
            'packet_id' => 'AIP-20260601-0001',
            'status' => Svc::STATUS_AVAILABLE,
            'lane' => 'docs',
            'risk_level' => 'low',
            'execution_allowed' => false,
            'allowed_files' => ['docs/engineering-knowledge-base/self-construction/scope-validator-contract.md'],
            'forbidden_files' => ['runtimes/python/voice_realtime/**'],
            'required_gates' => ['docs-health'],
            'acceptance_criteria' => ['Doc linked from AP-691.'],
            'required_evidence' => ['docs-health status ok'],
            'provider_contract' => ['provider_profile' => 'generic', 'normalized_final_response_required' => true],
            'structural_contract_exists' => true,
            'has_critical_ap' => false,
            'hot_files' => [],
        ], $override);
    }

    public function test_safe_packet_is_admitted(): void
    {
        $r = $this->svc()->validateAdmission($this->safePacket());

        $this->assertSame(Svc::VERDICT_ADMITTED, $r['verdict']);
        $this->assertTrue($r['admissible']);
        $this->assertFalse($r['execution_allowed']); // never flipped true.
        $this->assertSame([], $r['rejections']);
        $this->assertSame([], $r['blocks']);
        $this->assertSame(0, $r['summary']['rejection_count']);
    }

    /**
     * Rejected Packet: "has no allowed_files" AND Hard Invariant
     * "allowed_files is the maximum write set" => reject with missing_allowed_files.
     */
    public function test_missing_allowed_files_is_rejected(): void
    {
        $r = $this->svc()->validateAdmission($this->safePacket(['allowed_files' => []]));

        $this->assertSame(Svc::VERDICT_REJECTED, $r['verdict']);
        $this->assertFalse($r['admissible']);
        $codes = array_column($r['rejections'], 'code');
        $this->assertContains('missing_allowed_files', $codes);
    }

    /**
     * Rejected Packet: "permits broad directories such as app/** without
     * sub-scope" => reject. Also exercises the missing-gates rule on the same
     * packet (omits required_gates).
     */
    public function test_broad_scope_and_missing_gates_are_rejected(): void
    {
        $r = $this->svc()->validateAdmission($this->safePacket([
            'lane' => 'runtime_scoped',
            'allowed_files' => ['app/**'],
            'required_gates' => [],
        ]));

        $this->assertSame(Svc::VERDICT_REJECTED, $r['verdict']);
        $codes = array_column($r['rejections'], 'code');
        $this->assertContains('broad_scope_without_subscope', $codes);
        $this->assertContains('missing_gates', $codes);
    }

    /**
     * Hard Invariant: "forbidden_files always wins over allowed_files." A path in
     * BOTH lists is a contradictory packet => reject with forbidden_overlaps_allowed.
     */
    public function test_forbidden_overlapping_allowed_is_rejected(): void
    {
        $r = $this->svc()->validateAdmission($this->safePacket([
            'allowed_files' => ['docs/foo.md', 'docs/secret.md'],
            'forbidden_files' => ['docs/secret.md'],
        ]));

        $this->assertSame(Svc::VERDICT_REJECTED, $r['verdict']);
        $this->assertContains('forbidden_overlaps_allowed', array_column($r['rejections'], 'code'));
    }

    /**
     * Hard Invariant: "execution_allowed=false until a governed receipt permits
     * execution." A packet arriving with execution_allowed=true is an invariant
     * failure, and the emitted execution_allowed stays false.
     */
    public function test_execution_allowed_true_is_invariant_failure(): void
    {
        $r = $this->svc()->validateAdmission($this->safePacket(['execution_allowed' => true]));

        $this->assertSame(Svc::VERDICT_REJECTED, $r['verdict']);
        $this->assertFalse($r['execution_allowed']);
        $this->assertContains('execution_allowed_true_without_receipt', array_column($r['invariant_failures'], 'code'));
    }

    /**
     * Rejected Packet: "changes security, payment, provider, daemon, memory or
     * runtime policy without explicit critical AP + receipt." Sensitive scope
     * with has_critical_ap=false => reject; with =true the block clears.
     */
    public function test_sensitive_scope_requires_critical_ap(): void
    {
        $base = [
            'lane' => 'runtime_scoped',
            'allowed_files' => ['app/Services/Payment/RefundService.php'],
        ];

        $rejected = $this->svc()->validateAdmission($this->safePacket($base + ['has_critical_ap' => false]));
        $this->assertSame(Svc::VERDICT_REJECTED, $rejected['verdict']);
        $this->assertContains('sensitive_scope_without_critical_ap', array_column($rejected['rejections'], 'code'));

        $admitted = $this->svc()->validateAdmission($this->safePacket($base + ['has_critical_ap' => true]));
        $this->assertNotContains('sensitive_scope_without_critical_ap', array_column($admitted['rejections'], 'code'));
    }

    /**
     * Hard Invariant: "One AI claims one packet at a time." A claimed packet
     * owned by a different session => blocked (recoverable), not admitted.
     */
    public function test_packet_claimed_by_other_session_is_blocked(): void
    {
        $r = $this->svc()->validateAdmission($this->safePacket([
            'status' => Svc::STATUS_CLAIMED,
            'claimed_by' => 'session-A',
            'session_id' => 'session-B',
        ]));

        $this->assertSame(Svc::VERDICT_BLOCKED, $r['verdict']);
        $this->assertContains('claimed_by_other_session', array_column($r['blocks'], 'code'));
    }

    /**
     * Rejected Packet: code lane "asks for implementation before its structural
     * contract exists" => reject.
     */
    public function test_code_lane_without_structural_contract_is_rejected(): void
    {
        $r = $this->svc()->validateAdmission($this->safePacket([
            'lane' => 'runtime_scoped',
            'allowed_files' => ['app/Services/Ai/Aaeos/Generated/FooService.php'],
            'structural_contract_exists' => false,
        ]));

        $this->assertContains('structural_contract_missing', array_column($r['rejections'], 'code'));
        $this->assertSame(Svc::VERDICT_REJECTED, $r['verdict']);
    }

    /**
     * Completion Criteria: when ALL hold (acceptance met, gates pass, scope clean,
     * evidence attached, residual documented, no unrelated files, normalized
     * response) => complete + final_status=completed.
     */
    public function test_completion_complete_when_all_criteria_hold(): void
    {
        $r = $this->svc()->judgeCompletion([
            'packet_id' => 'AIP-20260601-0001',
            'acceptance_criteria' => ['c1', 'c2'],
            'satisfied_criteria' => ['c1', 'c2'],
            'gates' => [['name' => 'docs-health', 'passed' => true]],
            'scope_validator_status' => 'pass',
            'evidence' => ['docs-health.report.json'],
            'residual_risk_documented' => true,
            'unrelated_files_changed' => [],
            'normalized_final_response' => true,
        ]);

        $this->assertTrue($r['complete']);
        $this->assertSame(Svc::COMPLETION_COMPLETE, $r['verdict']);
        $this->assertSame(Svc::STATUS_COMPLETED, $r['final_status']);
        $this->assertSame([], $r['criteria_missing']);
        $this->assertSame(2, $r['criteria_satisfied']);
    }

    /**
     * Completion Criteria: a failed gate that is NOT explicitly external blocks
     * completion, but an external failure does NOT. Also pins that an unmet
     * acceptance criterion blocks completion.
     */
    public function test_completion_gate_external_vs_internal_and_unmet_criteria(): void
    {
        // External failure tolerated; unmet acceptance criterion still blocks.
        $r = $this->svc()->judgeCompletion([
            'packet_id' => 'AIP-20260601-0002',
            'acceptance_criteria' => ['c1', 'c2'],
            'satisfied_criteria' => ['c1'],
            'gates' => [['name' => 'flaky-remote', 'passed' => false, 'external' => true]],
            'scope_validator_status' => 'pass',
            'evidence' => ['e.json'],
            'residual_risk_documented' => true,
            'unrelated_files_changed' => [],
            'normalized_final_response' => true,
        ]);

        $this->assertFalse($r['complete']);
        $codes = array_column($r['blockers'], 'code');
        $this->assertContains('acceptance_criteria_unmet', $codes);
        $this->assertNotContains('gate_failed_not_external', $codes); // external => tolerated.
        $this->assertSame(['c2'], $r['criteria_missing']);

        // Internal gate failure blocks completion.
        $r2 = $this->svc()->judgeCompletion([
            'packet_id' => 'AIP-20260601-0003',
            'acceptance_criteria' => ['c1'],
            'satisfied_criteria' => ['c1'],
            'gates' => [['name' => 'docs-health', 'passed' => false, 'external' => false]],
            'scope_validator_status' => 'pass',
            'evidence' => ['e.json'],
            'residual_risk_documented' => true,
            'unrelated_files_changed' => [],
            'normalized_final_response' => true,
        ]);
        $this->assertFalse($r2['complete']);
        $this->assertContains('gate_failed_not_external', array_column($r2['blockers'], 'code'));
    }

    /**
     * Completion Criteria: "Scope Validator reports no blocking violation" AND
     * "no unrelated file was changed by the packet owner". A non-pass scope
     * status and an unrelated file each block completion.
     */
    public function test_completion_blocked_by_scope_and_unrelated_files(): void
    {
        $r = $this->svc()->judgeCompletion([
            'packet_id' => 'AIP-20260601-0004',
            'acceptance_criteria' => ['c1'],
            'satisfied_criteria' => ['c1'],
            'gates' => [['name' => 'docs-health', 'passed' => true]],
            'scope_validator_status' => 'fail',
            'evidence' => ['e.json'],
            'residual_risk_documented' => true,
            'unrelated_files_changed' => ['app/Services/Other/Thing.php'],
            'normalized_final_response' => true,
        ]);

        $this->assertFalse($r['complete']);
        $codes = array_column($r['blockers'], 'code');
        $this->assertContains('scope_validator_not_clean', $codes);
        $this->assertContains('unrelated_files_changed', $codes);
        $this->assertSame(Svc::STATUS_CLAIMED, $r['final_status']); // not promoted to completed.
    }
}
