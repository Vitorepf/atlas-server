<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\TerminalLoopProof\TerminalLoopProofCanonicalizer;
use Tests\TestCase;

class TerminalLoopProofCanonicalizerTest extends TestCase
{
    public function test_safe_token_keeps_safe_chars(): void
    {
        self::assertSame('alice', TerminalLoopProofCanonicalizer::safeToken('alice', 'fb'));
    }

    public function test_safe_token_lowercases(): void
    {
        self::assertSame('alice', TerminalLoopProofCanonicalizer::safeToken('ALICE', 'fb'));
    }

    public function test_safe_token_replaces_unsafe(): void
    {
        self::assertSame('al-ice', TerminalLoopProofCanonicalizer::safeToken('al ice', 'fb'));
        self::assertSame('alice-bob', TerminalLoopProofCanonicalizer::safeToken('alice!@#bob', 'fb'));
    }

    public function test_safe_token_trims_dashes(): void
    {
        self::assertSame('alice', TerminalLoopProofCanonicalizer::safeToken('---alice---', 'fb'));
    }

    public function test_safe_token_returns_fallback_when_empty(): void
    {
        self::assertSame('fb', TerminalLoopProofCanonicalizer::safeToken('', 'fb'));
        self::assertSame('fb', TerminalLoopProofCanonicalizer::safeToken('!!!', 'fb'));
        self::assertSame('fb', TerminalLoopProofCanonicalizer::safeToken('---', 'fb'));
    }

    public function test_string_list_filters_empty_and_trims(): void
    {
        $result = TerminalLoopProofCanonicalizer::stringList(['  a  ', 'b', '', '  ', 'c']);

        self::assertSame(['a', 'b', 'c'], $result);
    }

    public function test_string_list_empty_input(): void
    {
        self::assertSame([], TerminalLoopProofCanonicalizer::stringList([]));
    }

    public function test_string_list_handles_non_strings(): void
    {
        $result = TerminalLoopProofCanonicalizer::stringList([42, 'x', 0, '', 'y']);

        self::assertSame(['42', 'x', '0', 'y'], $result);
    }

    public function test_ksort_recursive_orders_assoc(): void
    {
        $input = ['b' => 2, 'a' => 1];

        self::assertSame(['a' => 1, 'b' => 2], TerminalLoopProofCanonicalizer::ksortRecursive($input));
    }

    public function test_ksort_recursive_preserves_list(): void
    {
        self::assertSame(['c', 'a', 'b'], TerminalLoopProofCanonicalizer::ksortRecursive(['c', 'a', 'b']));
    }

    public function test_ksort_recursive_handles_nested(): void
    {
        $input = ['b' => ['y' => 1, 'x' => 2], 'a' => 0];

        self::assertSame(['a' => 0, 'b' => ['x' => 2, 'y' => 1]], TerminalLoopProofCanonicalizer::ksortRecursive($input));
    }

    public function test_ksort_recursive_handles_empty(): void
    {
        self::assertSame([], TerminalLoopProofCanonicalizer::ksortRecursive([]));
    }

    public function test_stable_hash_deterministic(): void
    {
        $a = TerminalLoopProofCanonicalizer::stableHash(['x' => 1, 'y' => 2]);
        $b = TerminalLoopProofCanonicalizer::stableHash(['y' => 2, 'x' => 1]);

        self::assertSame($a, $b);
        self::assertSame(64, strlen($a));
    }

    public function test_stable_hash_strips_generated_at(): void
    {
        $a = TerminalLoopProofCanonicalizer::stableHash(['x' => 1, 'generated_at' => 1234]);
        $b = TerminalLoopProofCanonicalizer::stableHash(['x' => 1, 'generated_at' => 5678]);

        self::assertSame($a, $b);
    }

    public function test_stable_hash_strips_terminal_loop_proof_hash(): void
    {
        $a = TerminalLoopProofCanonicalizer::stableHash(['x' => 1, 'terminal_loop_operational_proof_hash' => 'aaa']);
        $b = TerminalLoopProofCanonicalizer::stableHash(['x' => 1, 'terminal_loop_operational_proof_hash' => 'bbb']);

        self::assertSame($a, $b);
    }

    public function test_stable_hash_strips_binding_packet(): void
    {
        $a = TerminalLoopProofCanonicalizer::stableHash(['x' => 1, 'completion_audit_binding_packet' => ['p']]);
        $b = TerminalLoopProofCanonicalizer::stableHash(['x' => 1, 'completion_audit_binding_packet' => ['q']]);

        self::assertSame($a, $b);
    }

    public function test_stable_hash_strips_binding_packet_hash(): void
    {
        $a = TerminalLoopProofCanonicalizer::stableHash(['x' => 1, 'completion_audit_binding_packet_hash' => 'aaa']);
        $b = TerminalLoopProofCanonicalizer::stableHash(['x' => 1, 'completion_audit_binding_packet_hash' => 'bbb']);

        self::assertSame($a, $b);
    }

    public function test_stable_hash_changes_with_data(): void
    {
        $a = TerminalLoopProofCanonicalizer::stableHash(['x' => 1]);
        $b = TerminalLoopProofCanonicalizer::stableHash(['x' => 2]);

        self::assertNotSame($a, $b);
    }

    public function test_has_secret_or_placeholder_detects_secret_key_name(): void
    {
        self::assertTrue(TerminalLoopProofCanonicalizer::hasSecretOrPlaceholder(['API_KEY' => 'real-value']));
        self::assertTrue(TerminalLoopProofCanonicalizer::hasSecretOrPlaceholder(['db_password' => 'x']));
        self::assertTrue(TerminalLoopProofCanonicalizer::hasSecretOrPlaceholder(['auth_token' => 'y']));
    }

    public function test_has_secret_or_placeholder_detects_placeholder_value(): void
    {
        self::assertTrue(TerminalLoopProofCanonicalizer::hasSecretOrPlaceholder(['task_id' => '<todo>']));
        self::assertTrue(TerminalLoopProofCanonicalizer::hasSecretOrPlaceholder(['field' => '__incomplete']));
        self::assertTrue(TerminalLoopProofCanonicalizer::hasSecretOrPlaceholder(['x' => 'fake-hash']));
    }

    public function test_has_secret_or_placeholder_detects_nested(): void
    {
        self::assertTrue(TerminalLoopProofCanonicalizer::hasSecretOrPlaceholder([
            'meta' => ['TOKEN' => 'abc'],
        ]));
        self::assertTrue(TerminalLoopProofCanonicalizer::hasSecretOrPlaceholder([
            'context' => ['task_id' => 'placeholder-value'],
        ]));
    }

    public function test_has_secret_or_placeholder_returns_false_for_clean(): void
    {
        self::assertFalse(TerminalLoopProofCanonicalizer::hasSecretOrPlaceholder([
            'task_id' => 'real-task-abc123',
            'status' => 'done',
            'count' => 5,
        ]));
    }

    public function test_has_secret_or_placeholder_returns_false_for_empty(): void
    {
        self::assertFalse(TerminalLoopProofCanonicalizer::hasSecretOrPlaceholder([]));
    }
}