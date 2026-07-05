<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentMergeReviewScopeVerifier;
use Tests\TestCase;

final class AgentMergeReviewScopeVerifierTest extends TestCase
{
    private function verifier(): AgentMergeReviewScopeVerifier
    {
        return new AgentMergeReviewScopeVerifier();
    }

    private function packet(array $files): array
    {
        return ['packet' => ['files' => $files]];
    }

    private function file(string $path, string $kind = 'modified'): array
    {
        return ['path' => $path, 'change_kind' => $kind];
    }

    // ── AC: overlap with active worker files returns conflict_with_active_worker ──

    public function test_overlap_with_active_worker_returns_conflict(): void
    {
        $r = $this->verifier()->verify(
            $this->packet([$this->file('app/Services/Foo.php')]),
            [
                'allowed_files' => ['app/Services/Foo.php'],
                'own_lease_id' => 'lease-A',
                'active_lease_scopes' => [
                    ['lease_id' => 'lease-B', 'write_set' => ['app/Services/Foo.php']],
                ],
            ],
        );

        $this->assertSame(AgentMergeReviewScopeVerifier::DECISION_CONFLICT_WITH_ACTIVE_WORKER, $r['decision']);
        $this->assertNotEmpty($r['verification']['active_worker_conflicts']);
    }

    public function test_no_conflict_when_own_lease_matches(): void
    {
        $r = $this->verifier()->verify(
            $this->packet([$this->file('app/Services/Foo.php')]),
            [
                'allowed_files' => ['app/Services/Foo.php'],
                'own_lease_id' => 'lease-A',
                'active_lease_scopes' => [
                    ['lease_id' => 'lease-A', 'write_set' => ['app/Services/Foo.php']],
                ],
            ],
        );

        $this->assertSame(AgentMergeReviewScopeVerifier::DECISION_SCOPE_CLEAN, $r['decision']);
    }

    // ── AC: unsafe prefixes and undeclared changed files return scope_violation ──

    public function test_unsafe_prefix_returns_scope_violation(): void
    {
        $r = $this->verifier()->verify(
            $this->packet([$this->file('../etc/passwd')]),
            ['allowed_files' => ['../etc/passwd']],
        );

        $this->assertSame(AgentMergeReviewScopeVerifier::DECISION_SCOPE_VIOLATION, $r['decision']);
        $this->assertNotEmpty($r['verification']['unsafe_path_violations']);
    }

    public function test_undeclared_file_returns_scope_violation(): void
    {
        $r = $this->verifier()->verify(
            $this->packet([$this->file('app/Services/Undeclared.php')]),
            ['allowed_files' => ['app/Services/Declared.php']],
        );

        $this->assertSame(AgentMergeReviewScopeVerifier::DECISION_SCOPE_VIOLATION, $r['decision']);
        $this->assertGreaterThan(0, $r['verification']['out_of_scope_count']);
    }

    public function test_cross_axis_violation_returns_scope_violation(): void
    {
        $r = $this->verifier()->verify(
            $this->packet([$this->file('app/Services/Ai/SelfImprovement/Foo.php')]),
            ['allowed_files' => ['app/Services/Ai/SelfImprovement/Foo.php']],
        );

        $this->assertSame(AgentMergeReviewScopeVerifier::DECISION_SCOPE_VIOLATION, $r['decision']);
        $this->assertNotEmpty($r['verification']['cross_axis_violations']);
    }

    public function test_forbidden_file_returns_scope_violation(): void
    {
        $r = $this->verifier()->verify(
            $this->packet([$this->file('app/Services/Foo.php')]),
            [
                'allowed_files' => ['app/Services/Foo.php'],
                'forbidden_files' => ['app/Services/Foo.php'],
            ],
        );

        $this->assertSame(AgentMergeReviewScopeVerifier::DECISION_SCOPE_VIOLATION, $r['decision']);
        $this->assertNotEmpty($r['verification']['forbidden_violations']);
    }

    // ── AC: clean declared scope returns scope_clean and retains non_execution_guarantees ──

    public function test_clean_declared_scope_returns_scope_clean(): void
    {
        $r = $this->verifier()->verify(
            $this->packet([$this->file('app/Services/Foo.php')]),
            ['allowed_files' => ['app/Services/Foo.php']],
        );

        $this->assertSame(AgentMergeReviewScopeVerifier::DECISION_SCOPE_CLEAN, $r['decision']);
        $this->assertSame(0, $r['verification']['violation_count']);
    }

    public function test_clean_scope_retains_non_execution_guarantees(): void
    {
        $r = $this->verifier()->verify(
            $this->packet([$this->file('app/Services/Foo.php')]),
            ['allowed_files' => ['app/Services/Foo.php']],
        );

        $this->assertNotEmpty($r['non_execution_guarantees']);
        $this->assertFalse($r['apply_patch_allowed']);
        $this->assertFalse($r['real_file_write_allowed']);
    }

    // ── schema and structure ──

    public function test_output_has_required_keys(): void
    {
        $r = $this->verifier()->verify($this->packet([]), []);

        $this->assertSame(AgentMergeReviewScopeVerifier::SCHEMA_VERSION, $r['schema_version']);
        $this->assertArrayHasKey('decision', $r);
        $this->assertArrayHasKey('verification', $r);
        $this->assertArrayHasKey('verification_hash', $r);
    }

    public function test_verification_is_deterministic(): void
    {
        $packet = $this->packet([$this->file('app/Services/Foo.php')]);
        $scope = ['allowed_files' => ['app/Services/Foo.php']];

        $a = $this->verifier()->verify($packet, $scope);
        $b = $this->verifier()->verify($packet, $scope);

        $this->assertSame($a['verification_hash'], $b['verification_hash']);
    }
}
