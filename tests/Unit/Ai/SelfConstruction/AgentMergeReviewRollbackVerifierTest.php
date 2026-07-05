<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentMergeReviewRollbackVerifier;
use Tests\TestCase;

/**
 * Unit tests for the hardened AgentMergeReviewRollbackVerifier:
 * missing rollback steps, migration risk without recovery, stale proof,
 * autonomous_safe output, and blockers.
 */
final class AgentMergeReviewRollbackVerifierTest extends TestCase
{
    private function packet(array $overrides = []): array
    {
        return array_merge([
            'packet' => [
                'files' => [
                    [
                        'path' => 'app/Foo.php',
                        'change_kind' => 'modified',
                        'content_hash' => 'abc123',
                    ],
                ],
            ],
        ], $overrides);
    }

    private function promotionDryRun(array $overrides = []): array
    {
        return array_merge([
            'plan' => [
                'rollback_steps' => [
                    [
                        'name' => 'revert Foo.php',
                        'path' => 'app/Foo.php',
                        'change_kind' => 'modified',
                    ],
                ],
            ],
        ], $overrides);
    }

    private function verifier(): AgentMergeReviewRollbackVerifier
    {
        return new AgentMergeReviewRollbackVerifier;
    }

    // ── AC: verify() rejects with missing rollback steps ───────────────────

    public function test_verify_rejects_promotion_with_missing_rollback_steps(): void
    {
        $result = $this->verifier()->verify(
            $this->packet(),
            $this->promotionDryRun(['plan' => ['rollback_steps' => []]]),
        );

        $this->assertSame(0, $result['verification']['verified_step_count']);
        $this->assertSame(0.0, (float) $result['verification']['rollback_coverage_ratio']);
    }

    public function test_verify_rejects_unverifiable_recovery_without_coverage(): void
    {
        $packet = $this->packet(['packet' => ['files' => [
            ['path' => 'app/Foo.php', 'change_kind' => 'modified', 'content_hash' => 'abc'],
            ['path' => 'app/Bar.php', 'change_kind' => 'modified', 'content_hash' => 'def'],
        ]]]);
        $dryRun = $this->promotionDryRun(['plan' => ['rollback_steps' => [
            ['name' => 'revert Foo.php', 'path' => 'app/Foo.php', 'change_kind' => 'modified'],
            // Bar.php missing from rollback steps
        ]]]);

        $result = $this->verifier()->verify($packet, $dryRun);

        $this->assertSame(1, $result['verification']['verified_step_count']);
        // Coverage is < 1.0 because Bar.php has no rollback step
        $this->assertLessThan(1.0, $result['verification']['rollback_coverage_ratio']);
        $this->assertTrue($result['verification']['all_steps_reversible']); // all STEPS are reversible, but coverage is incomplete
    }

    // ── AC: consent_required(migration risk without explicit recovery ────────

    public function test_classify_migration_risk_without_recovery_requires_manual_plan(): void
    {
        $packet = [
            'packet' => [
                'files' => [
                    ['path' => 'database/migrations/2026_01_01_create_foo_table.php', 'change_kind' => 'created', 'content_hash' => 'abc'],
                ],
            ],
        ];

        $result = $this->verifier()->classifyRollbackReadiness($packet, $this->promotionDryRun());

        $this->assertNotSame(AgentMergeReviewRollbackVerifier::DECISION_AUTONOMOUS_SAFE, $result['decision']);
        $this->assertContains('db_backup_snapshot_ref', $result['required_recovery_evidence']);
        $this->assertContains('missing_recovery_evidence', $result['blockers']);
    }

    // ── AC: stale proof ──────────────────────────────────────────────────────

    public function test_stale_proof_detected_and_blockers_returned(): void
    {
        $packet = [
            'packet' => [
                'files' => [
                    ['path' => 'app/Foo.php', 'change_kind' => 'modified', 'content_hash' => 'abc', 'changed_at' => '2026-07-05T10:00:00Z'],
                ],
            ],
        ];
        $dryRun = $this->promotionDryRun([
            'proof_generated_at' => '2026-07-04T10:00:00Z', // older than change → stale
        ]);

        $result = $this->verifier()->classifyRollbackReadiness($packet, $dryRun);

        $this->assertNotSame(AgentMergeReviewRollbackVerifier::DECISION_AUTONOMOUS_SAFE, $result['decision']);
        $hasStaleBlocker = false;
        foreach ($result['blockers'] as $blocker) {
            if (str_contains($blocker, 'stale_proof')) {
                $hasStaleBlocker = true;
            }
        }
        $this->assertTrue($hasStaleBlocker, 'blockers must include stale_proof');
    }

    public function test_fresh_proof_allows_autonomous_safe(): void
    {
        $packet = [
            'packet' => [
                'files' => [
                    ['path' => 'app/Foo.php', 'change_kind' => 'modified', 'content_hash' => 'abc', 'changed_at' => '2026-07-04T10:00:00Z'],
                ],
            ],
        ];
        $dryRun = $this->promotionDryRun([
            'proof_generated_at' => '2026-07-05T10:00:00Z', // after change → fresh
        ]);

        $result = $this->verifier()->classifyRollbackReadiness($packet, $dryRun);

        $this->assertSame(AgentMergeReviewRollbackVerifier::DECISION_AUTONOMOUS_SAFE, $result['decision']);
        $this->assertSame([], $result['blockers']);
    }

    // ── AC: autonomous_safe only when evidence covers every file ──────────────

    public function test_autonomous_safe_requires_every_file_covered(): void
    {
        $packet = [
            'packet' => [
                'files' => [
                    ['path' => 'app/Foo.php', 'change_kind' => 'modified', 'content_hash' => 'abc'],
                    ['path' => 'app/Bar.php', 'change_kind' => 'modified', 'content_hash' => 'def'],
                ],
            ],
        ];
        $dryRun = $this->promotionDryRun(['plan' => ['rollback_steps' => [
            ['name' => 'revert Foo.php', 'path' => 'app/Foo.php', 'change_kind' => 'modified'],
        ]]]);

        $result = $this->verifier()->classifyRollbackReadiness($packet, $dryRun);

        $this->assertNotSame(AgentMergeReviewRollbackVerifier::DECISION_AUTONOMOUS_SAFE, $result['decision']);
    }

    // ── AC: output fields ─────────────────────────────────────────────────────

    public function test_classify_output_has_decision_reason_evidence_and_blockers(): void
    {
        $result = $this->verifier()->classifyRollbackReadiness(
            $this->packet(),
            $this->promotionDryRun(),
        );

        $this->assertArrayHasKey('decision', $result);
        $this->assertArrayHasKey('rollback_reason', $result);
        $this->assertArrayHasKey('required_recovery_evidence', $result);
        $this->assertArrayHasKey('blockers', $result);
    }

    public function test_verify_output_has_required_fields(): void
    {
        $result = $this->verifier()->verify($this->packet(), $this->promotionDryRun());

        $this->assertArrayHasKey('verification_hash', $result);
        $this->assertArrayHasKey('verification', $result);
        $this->assertArrayHasKey('verified_step_count', $result['verification']);
        $this->assertArrayHasKey('unverified_step_count', $result['verification']);
        $this->assertArrayHasKey('rollback_coverage_ratio', $result['verification']);
        $this->assertArrayHasKey('confidence', $result['verification']);
        $this->assertArrayHasKey('all_steps_reversible', $result['verification']);
    }
}
