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
}