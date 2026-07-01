<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientResultVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasExternalBrainLocalClientResultVerifier: a local client's claimed_green is only
 * trusted when backed by one of the 4 real-evidence kinds (artifact_refs, runnable test proof,
 * task_report, structured_receipt) — never by transcript narrative, UI screenshots, or stale
 * evidence.
 */
final class AtlasExternalBrainLocalClientResultVerifierTest extends TestCase
{
    private function verifier(): AtlasExternalBrainLocalClientResultVerifier
    {
        return new AtlasExternalBrainLocalClientResultVerifier;
    }

    private function baseFacts(array $overrides = []): array
    {
        return array_merge([
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'acceptance_criteria' => ['foo works'],
            'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
            'provided_evidence' => [
                'tests_or_gates_result' => '5 passed',
                'implementation_notes' => 'added Foo logic',
            ],
            'claimed_changed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'claimed_green' => true,
        ], $overrides);
    }

    // ── AC1: artifact-backed result ─────────────────────────────────────────────

    public function test_artifact_backed_result_is_verified_green(): void
    {
        $result = $this->verifier()->verify($this->baseFacts([
            'artifact_refs' => ['app/Foo.php@sha256:abc123'],
        ]));

        $this->assertSame('verified_green', $result['verifier_status']);
        $this->assertTrue($result['verified_green']);
        $this->assertTrue($result['safe_to_report_success']);
        $this->assertSame([], $result['blockers']);
        $this->assertTrue($result['evidence_kinds']['artifact']);
    }

    // ── AC1: test-backed result ──────────────────────────────────────────────────

    public function test_test_backed_result_is_verified_green(): void
    {
        $result = $this->verifier()->verify($this->baseFacts([
            'reported_test_commands' => [
                ['command' => 'php artisan test FooTest', 'executed' => true, 'passed' => true],
            ],
        ]));

        $this->assertSame('verified_green', $result['verifier_status']);
        $this->assertTrue($result['evidence_kinds']['test_output']);
    }

    public function test_task_report_backed_result_is_verified_green(): void
    {
        $result = $this->verifier()->verify($this->baseFacts([
            'task_report' => ['task_packet_id' => 'pkt-1', 'outcome' => 'success'],
        ]));

        $this->assertSame('verified_green', $result['verifier_status']);
        $this->assertTrue($result['evidence_kinds']['task_report']);
    }

    public function test_structured_receipt_backed_result_is_verified_green(): void
    {
        $result = $this->verifier()->verify($this->baseFacts([
            'structured_receipt' => ['receipt_hash' => 'abc123def456'],
        ]));

        $this->assertSame('verified_green', $result['verifier_status']);
        $this->assertTrue($result['evidence_kinds']['structured_receipt']);
    }

    // ── AC2: rejects transcript-only claims ─────────────────────────────────────

    public function test_transcript_only_claim_is_rejected(): void
    {
        $result = $this->verifier()->verify($this->baseFacts([
            'transcript_claim' => 'I ran the tests and everything passed, all good!',
        ]));

        $this->assertSame('blocked', $result['verifier_status']);
        $this->assertFalse($result['safe_to_report_success']);
        $this->assertContains('transcript_only_claim_rejected', $result['blockers']);
    }

    // ── AC2: rejects UI-only claims ──────────────────────────────────────────────

    public function test_ui_only_claim_is_rejected(): void
    {
        $result = $this->verifier()->verify($this->baseFacts([
            'ui_claim' => true,
        ]));

        $this->assertSame('blocked', $result['verifier_status']);
        $this->assertContains('ui_only_claim_rejected', $result['blockers']);
    }

    // ── AC2: rejects unparseable claims (no accepted evidence, no narrative flag) ──

    public function test_unparseable_claim_with_no_evidence_is_rejected(): void
    {
        $result = $this->verifier()->verify($this->baseFacts());

        $this->assertSame('blocked', $result['verifier_status']);
        $this->assertNotEmpty($result['blockers']);
    }

    // ── AC5: stale evidence rejection ───────────────────────────────────────────

    public function test_stale_evidence_is_rejected_even_with_artifact_refs(): void
    {
        $result = $this->verifier()->verify($this->baseFacts([
            'artifact_refs' => ['app/Foo.php@sha256:abc123'],
            'evidence_age_seconds' => 200000,
            'max_evidence_age_seconds' => 86400,
        ]));

        $this->assertSame('blocked', $result['verifier_status']);
        $this->assertContains('stale_evidence_rejected', $result['blockers']);
        $this->assertFalse($result['verified_green']);
    }

    public function test_fresh_evidence_within_max_age_is_accepted(): void
    {
        $result = $this->verifier()->verify($this->baseFacts([
            'artifact_refs' => ['app/Foo.php@sha256:abc123'],
            'evidence_age_seconds' => 100,
            'max_evidence_age_seconds' => 86400,
        ]));

        $this->assertSame('verified_green', $result['verifier_status']);
    }

    // ── claimed_green false never demands evidence ──────────────────────────────

    public function test_no_claim_of_success_does_not_require_evidence(): void
    {
        $result = $this->verifier()->verify($this->baseFacts(['claimed_green' => false]));

        $this->assertFalse($result['claimed_green']);
        $this->assertFalse($result['verified_green']);
        $this->assertNotContains('claimed_success_without_runnable_test_proof', $result['blockers']);
    }

    // ── evidence_kinds shape ─────────────────────────────────────────────────────

    public function test_evidence_kinds_key_present_and_false_by_default(): void
    {
        $result = $this->verifier()->verify($this->baseFacts(['claimed_green' => false]));

        $this->assertArrayHasKey('evidence_kinds', $result);
        $this->assertFalse($result['evidence_kinds']['artifact']);
        $this->assertFalse($result['evidence_kinds']['test_output']);
        $this->assertFalse($result['evidence_kinds']['task_report']);
        $this->assertFalse($result['evidence_kinds']['structured_receipt']);
    }
}
