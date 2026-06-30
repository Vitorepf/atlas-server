<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativePostApplyVerifier;
use Tests\TestCase;

final class AtlasSelfConstructionNativePostApplyVerifierTest extends TestCase
{
    private function baseFacts(array $overrides = []): array
    {
        return $overrides + [
            'task_packet_id' => 'pkt-1',
            'expected_gates' => ['phpunit', 'phpstan'],
            'apply_receipts' => [
                ['path' => 'app/Foo.php', 'post_hash' => 'h1'],
                ['path' => 'tests/FooTest.php', 'post_hash' => 'h2'],
            ],
            'changed_files' => [
                ['path' => 'app/Foo.php', 'post_hash' => 'h1'],
                ['path' => 'tests/FooTest.php', 'post_hash' => 'h2'],
            ],
            'gate_results' => [
                ['gate' => 'phpunit', 'exit_code' => 0, 'evidence_hash' => 'ev_phpunit'],
                ['gate' => 'phpstan', 'exit_code' => 0, 'evidence_hash' => 'ev_phpstan'],
            ],
        ];
    }

    public function test_all_gates_passed_yields_passed_verdict(): void
    {
        $verdict = (new AtlasSelfConstructionNativePostApplyVerifier)->verify($this->baseFacts());

        $this->assertSame('passed', $verdict['verdict']);
        $this->assertSame('pkt-1', $verdict['task_packet_id']);
        $this->assertSame([], $verdict['blockers']);
        $this->assertSame(['phpunit', 'phpstan'], $verdict['gates_summary']['passed']);
        $this->assertSame('ev_phpunit', $verdict['evidence_hashes']['phpunit']);
        $this->assertSame('ev_phpstan', $verdict['evidence_hashes']['phpstan']);
        $this->assertSame(['app/Foo.php', 'tests/FooTest.php'], $verdict['changed_files']);
    }

    public function test_one_gate_failed_yields_failed_verdict(): void
    {
        $verdict = (new AtlasSelfConstructionNativePostApplyVerifier)->verify($this->baseFacts([
            'gate_results' => [
                ['gate' => 'phpunit', 'exit_code' => 0, 'evidence_hash' => 'ev_pu'],
                ['gate' => 'phpstan', 'exit_code' => 7, 'evidence_hash' => 'ev_ps'],
            ],
        ]));

        $this->assertSame('failed', $verdict['verdict']);
        $this->assertContains('gate_failed:phpstan:exit=7', $verdict['blockers']);
        $this->assertSame(['phpstan'], $verdict['gates_summary']['failed']);
        $this->assertSame(['phpunit'], $verdict['gates_summary']['passed']);
    }

    public function test_missing_gate_output_yields_rollback_required(): void
    {
        $verdict = (new AtlasSelfConstructionNativePostApplyVerifier)->verify($this->baseFacts([
            'gate_results' => [
                ['gate' => 'phpunit', 'exit_code' => 0, 'evidence_hash' => 'ev_pu'],
                // phpstan missing entirely
            ],
        ]));

        $this->assertSame('rollback_required', $verdict['verdict']);
        $this->assertContains('expected_gate_missing:phpstan', $verdict['blockers']);
        $this->assertSame(['phpstan'], $verdict['gates_summary']['missing']);
    }

    public function test_changed_file_mismatch_yields_rollback_required(): void
    {
        $verdict = (new AtlasSelfConstructionNativePostApplyVerifier)->verify($this->baseFacts([
            'changed_files' => [
                ['path' => 'app/Foo.php', 'post_hash' => 'DIFFERENT_HASH'],
                ['path' => 'tests/FooTest.php', 'post_hash' => 'h2'],
            ],
        ]));

        $this->assertSame('rollback_required', $verdict['verdict']);
        $this->assertContains('changed_files_mismatch_apply_receipts', $verdict['blockers']);
    }

    public function test_verdict_binds_task_packet_id_and_evidence_hashes(): void
    {
        $verdict = (new AtlasSelfConstructionNativePostApplyVerifier)->verify($this->baseFacts([
            'task_packet_id' => 'pkt-abc',
        ]));

        $this->assertSame('pkt-abc', $verdict['task_packet_id']);
        $this->assertArrayHasKey('phpunit', $verdict['evidence_hashes']);
        $this->assertArrayHasKey('phpstan', $verdict['evidence_hashes']);
    }

    public function test_changed_file_outside_allowed_scope_yields_rollback(): void
    {
        $verdict = (new AtlasSelfConstructionNativePostApplyVerifier)->verify($this->baseFacts([
            'allowed_files' => ['app/Foo.php'], // tests/FooTest.php not listed
        ]));

        $this->assertSame('rollback_required', $verdict['verdict']);
        $this->assertContains('changed_file_outside_scope:tests/FooTest.php', $verdict['blockers']);
    }

    public function test_forbidden_file_touched_yields_rollback(): void
    {
        $verdict = (new AtlasSelfConstructionNativePostApplyVerifier)->verify($this->baseFacts([
            'forbidden_files' => ['app/Foo.php'],
        ]));

        $this->assertSame('rollback_required', $verdict['verdict']);
        $this->assertContains('forbidden_file_touched:app/Foo.php', $verdict['blockers']);
    }

    public function test_evidence_hash_mismatch_yields_rollback(): void
    {
        $verdict = (new AtlasSelfConstructionNativePostApplyVerifier)->verify($this->baseFacts([
            'expected_evidence_hashes' => ['phpunit' => 'WRONG_HASH'],
        ]));

        $this->assertSame('rollback_required', $verdict['verdict']);
        $this->assertContains('evidence_hash_mismatch:phpunit', $verdict['blockers']);
    }

    public function test_exact_scoped_green_patch_passes(): void
    {
        $verdict = (new AtlasSelfConstructionNativePostApplyVerifier)->verify($this->baseFacts([
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'forbidden_files' => ['app/Secret.php'],
            'expected_evidence_hashes' => ['phpunit' => 'ev_phpunit', 'phpstan' => 'ev_phpstan'],
        ]));

        $this->assertSame('passed', $verdict['verdict']);
        $this->assertSame([], $verdict['blockers']);
    }

    public function test_no_allowed_files_supplied_skips_scope_check(): void
    {
        // When allowed_files is absent, the scope guard must not fire — backward compat.
        $verdict = (new AtlasSelfConstructionNativePostApplyVerifier)->verify($this->baseFacts());

        $this->assertSame('passed', $verdict['verdict']);
        $this->assertSame([], $verdict['blockers']);
    }
}
