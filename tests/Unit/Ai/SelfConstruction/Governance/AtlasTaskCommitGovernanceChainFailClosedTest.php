<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Governance;

use App\Services\Ai\SelfConstruction\Governance\AtlasTaskCommitGovernanceChain;
use Error;
use PHPUnit\Framework\TestCase;

final class AtlasTaskCommitGovernanceChainFailClosedTest extends TestCase
{
    /**
     * The organs (RiskClassifier, RollbackPlanGate, AdmissionPolicy, FalseGreenDetector) are all
     * `final`, so they can't be subclassed into a throwing stub. Instead, a non-stringable object
     * in `changed_files` makes `normalizeFiles()`'s `(string) $f` cast throw a Throwable early
     * inside govern()'s try block — a reliable, collaborator-independent way to simulate "a
     * governance-internal step crashed", and it fires regardless of whether task_packet_id is set.
     */
    private function contextWithUnstringableChangedFile(array $overrides = []): array
    {
        return array_merge([
            'changed_files' => [new class
            {
                // Deliberately no __toString(): casting this to string throws.
            }],
            'verification' => ['passed' => true],
        ], $overrides);
    }

    private function chain(string $mode): AtlasTaskCommitGovernanceChain
    {
        return new AtlasTaskCommitGovernanceChain(modeOverride: $mode);
    }

    // ── (a) observe mode still admits and records the error reason ──────────

    public function test_observe_mode_admits_and_records_error_reason_on_internal_exception(): void
    {
        $result = $this->chain(AtlasTaskCommitGovernanceChain::MODE_OBSERVE)->govern(
            $this->contextWithUnstringableChangedFile(['task_packet_id' => 'task-observe-1']),
        );

        $this->assertTrue($result['admitted']);
        $this->assertFalse($result['enforced_block']);
        $this->assertSame('fail_open_error', $result['decision']);
        $this->assertNotNull($result['error']);
        $this->assertSame('observe', $result['mode']);
    }

    // ── (b) enforce mode fails closed ────────────────────────────────────────

    public function test_enforce_mode_fails_closed_with_dedicated_decision_and_exception_class_in_blockers(): void
    {
        $result = $this->chain(AtlasTaskCommitGovernanceChain::MODE_ENFORCE)->govern(
            $this->contextWithUnstringableChangedFile(['task_packet_id' => 'task-enforce-1']),
        );

        $this->assertFalse($result['admitted']);
        $this->assertTrue($result['enforced_block']);
        $this->assertSame('governance_error_fail_closed', $result['decision']);
        $this->assertContains(Error::class, $result['blockers']);
        $this->assertSame(AtlasTaskCommitGovernanceChain::MODE_ENFORCE, $result['mode']);
    }

    public function test_enforce_mode_fail_closed_envelope_shape_matches_normal_envelope(): void
    {
        $result = $this->chain(AtlasTaskCommitGovernanceChain::MODE_ENFORCE)->govern(
            $this->contextWithUnstringableChangedFile(['task_packet_id' => 'task-enforce-2']),
        );

        foreach (['schema', 'mode', 'ran', 'decision', 'admitted', 'enforced_block', 'risk_level', 'blockers', 'recorded', 'replay_verdict', 'error'] as $key) {
            $this->assertArrayHasKey($key, $result, "envelope must still include '{$key}'");
        }
    }

    // ── (c) off mode is untouched ────────────────────────────────────────────

    public function test_off_mode_is_untouched_by_internal_exception(): void
    {
        $result = $this->chain(AtlasTaskCommitGovernanceChain::MODE_OFF)->govern(
            $this->contextWithUnstringableChangedFile(['task_packet_id' => 'task-off-1']),
        );

        $this->assertSame('off', $result['mode']);
        $this->assertFalse($result['ran']);
        $this->assertTrue($result['admitted']);
        $this->assertFalse($result['enforced_block']);
        $this->assertSame('skipped', $result['decision']);
    }

    public function test_enforce_mode_fail_closed_never_throws_even_when_task_id_missing(): void
    {
        // No task_packet_id -> the best-effort ledger record must be skipped safely (record()
        // early-returns for empty task id) without breaking the fail-closed decision itself.
        $result = $this->chain(AtlasTaskCommitGovernanceChain::MODE_ENFORCE)->govern(
            $this->contextWithUnstringableChangedFile(),
        );

        $this->assertTrue($result['enforced_block']);
        $this->assertSame('governance_error_fail_closed', $result['decision']);
    }
}
