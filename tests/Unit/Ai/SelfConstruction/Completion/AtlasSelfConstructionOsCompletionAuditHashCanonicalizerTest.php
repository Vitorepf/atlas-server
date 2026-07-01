<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionOsCompletionAuditHashCanonicalizer;
use Tests\TestCase;

class AtlasSelfConstructionOsCompletionAuditHashCanonicalizerTest extends TestCase
{
    public function test_ksort_recursive_orders_assoc(): void
    {
        $input = ['b' => 2, 'a' => 1];
        $result = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::ksortRecursive($input);

        self::assertSame(['a' => 1, 'b' => 2], $result);
    }

    public function test_ksort_recursive_preserves_list(): void
    {
        $input = ['c', 'a', 'b'];
        $result = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::ksortRecursive($input);

        self::assertSame(['c', 'a', 'b'], $result);
    }

    public function test_ksort_recursive_handles_nested(): void
    {
        $input = ['b' => ['y' => 1, 'x' => 2], 'a' => 0];
        $result = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::ksortRecursive($input);

        self::assertSame(['a' => 0, 'b' => ['x' => 2, 'y' => 1]], $result);
    }

    public function test_ksort_recursive_handles_empty(): void
    {
        self::assertSame([], AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::ksortRecursive([]));
    }

    public function test_stable_hash_deterministic(): void
    {
        $payload = ['a' => 1, 'b' => 2];

        self::assertSame(
            AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash($payload),
            AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['b' => 2, 'a' => 1]),
        );
    }

    public function test_stable_hash_strips_audited_at(): void
    {
        $a = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['a' => 1, 'audited_at' => 1234]);
        $b = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['a' => 1, 'audited_at' => 5678]);

        self::assertSame($a, $b);
    }

    public function test_stable_hash_strips_completion_audit_hash(): void
    {
        $a = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['a' => 1, 'completion_audit_hash' => 'h1']);
        $b = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['a' => 1, 'completion_audit_hash' => 'h2']);

        self::assertSame($a, $b);
    }

    public function test_stable_hash_returns_64_hex(): void
    {
        $hash = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['x' => 1]);

        self::assertSame(64, strlen($hash));
        self::assertTrue(ctype_xdigit($hash));
    }

    public function test_stable_hash_changes_with_data(): void
    {
        $a = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['x' => 1]);
        $b = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['x' => 2]);

        self::assertNotSame($a, $b);
    }

    public function test_associative_key_order_does_not_change_hash(): void
    {
        $a = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['z' => 3, 'a' => 1, 'm' => 2]);
        $b = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['a' => 1, 'm' => 2, 'z' => 3]);
        self::assertSame($a, $b, 'map key order must not affect the hash');
    }

    public function test_list_order_changes_hash(): void
    {
        $a = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['items' => ['x', 'y', 'z']]);
        $b = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['items' => ['z', 'y', 'x']]);
        self::assertNotSame($a, $b, 'list order must be preserved and affect the hash');
    }

    public function test_raw_prompt_is_stripped_before_hashing(): void
    {
        $a = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['x' => 1, 'raw_prompt' => 'secret prompt A']);
        $b = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['x' => 1, 'raw_prompt' => 'secret prompt B']);
        self::assertSame($a, $b, 'raw_prompt must be stripped before hashing');
    }

    public function test_provider_trace_is_stripped_before_hashing(): void
    {
        $a = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['x' => 1, 'provider_trace' => ['tok' => 500, 'model' => 'opus']]);
        $b = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['x' => 1, 'provider_trace' => ['tok' => 999, 'model' => 'sonnet']]);
        self::assertSame($a, $b, 'provider_trace must be stripped before hashing');
    }

    // ── AC3: substantive fields (evidence, verdict, blocker, proof_source) DO change the hash ──

    public function test_evidence_change_changes_hash(): void
    {
        $a = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['evidence' => ['tests_or_gates_result']]);
        $b = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['evidence' => ['implementation_notes']]);
        self::assertNotSame($a, $b, 'evidence change must change the hash');
    }

    public function test_verdict_change_changes_hash(): void
    {
        $a = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['verdict' => 'passed']);
        $b = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['verdict' => 'failed']);
        self::assertNotSame($a, $b, 'verdict change must change the hash');
    }

    public function test_blocker_change_changes_hash(): void
    {
        $a = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['blockers' => []]);
        $b = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['blockers' => ['missing_proof']]);
        self::assertNotSame($a, $b, 'blocker change must change the hash');
    }

    public function test_proof_source_change_changes_hash(): void
    {
        $a = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['proof_source' => 'phpunit']);
        $b = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['proof_source' => 'manual']);
        self::assertNotSame($a, $b, 'proof_source change must change the hash');
    }

    // ── AC3: caller-declared extra volatile fields are ignored (stable hash) ──────

    public function test_extra_volatile_field_is_ignored_when_declared(): void
    {
        $a = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(
            ['verdict' => 'passed', 'run_started_at' => 1000],
            ['run_started_at'],
        );
        $b = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(
            ['verdict' => 'passed', 'run_started_at' => 9999],
            ['run_started_at'],
        );
        self::assertSame($a, $b, 'declared extra volatile field must not affect the hash');
    }

    public function test_undeclared_field_still_affects_hash_even_if_it_would_be_volatile_elsewhere(): void
    {
        $a = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['verdict' => 'passed', 'run_started_at' => 1000]);
        $b = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash(['verdict' => 'passed', 'run_started_at' => 9999]);
        self::assertNotSame($a, $b, 'run_started_at is only volatile when explicitly declared');
    }

    // ── AC4: provider-sensitive fields are reported as excluded ───────────────────

    public function test_excluded_fields_names_the_fixed_volatile_fields_present_in_payload(): void
    {
        $excluded = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::excludedFields([
            'verdict' => 'passed',
            'audited_at' => 1234,
            'raw_prompt' => 'secret',
            'provider_trace' => ['tok' => 1],
        ]);

        self::assertContains('audited_at', $excluded);
        self::assertContains('raw_prompt', $excluded);
        self::assertContains('provider_trace', $excluded);
        self::assertNotContains('verdict', $excluded);
    }

    public function test_excluded_fields_omits_fixed_volatile_fields_absent_from_payload(): void
    {
        $excluded = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::excludedFields(['verdict' => 'passed']);

        self::assertSame([], $excluded);
    }

    public function test_excluded_fields_includes_declared_extra_volatile_fields_present_in_payload(): void
    {
        $excluded = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::excludedFields(
            ['verdict' => 'passed', 'run_started_at' => 1000],
            ['run_started_at'],
        );

        self::assertContains('run_started_at', $excluded);
    }
}