<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TerminalLoopProof;

use App\Services\Ai\SelfConstruction\TerminalLoopProof\TerminalLoopProofCanonicalizer;
use Tests\TestCase;

/**
 * Focused unit coverage: terminal-loop operational proof hashes stay stable, safe, and
 * insensitive to volatile completion-binding fields (generated_at, the proof hash itself, and
 * the completion audit binding packet + its hash never perturb stableHash); safeToken always
 * falls back to a safe default rather than an empty/unsafe token; ksortRecursive orders maps
 * deterministically while preserving list order; hasSecretOrPlaceholder recursively rejects
 * nested SECRET/TOKEN/API_KEY/PASSWORD keys and placeholder-like values.
 */
final class TerminalLoopProofCanonicalizerTest extends TestCase
{
    // ── safeToken fallback ────────────────────────────────────────────────────────

    public function test_safe_token_falls_back_when_value_normalizes_to_empty(): void
    {
        $this->assertSame('fallback', TerminalLoopProofCanonicalizer::safeToken('', 'fallback'));
        $this->assertSame('fallback', TerminalLoopProofCanonicalizer::safeToken('!!!', 'fallback'));
        $this->assertSame('fallback', TerminalLoopProofCanonicalizer::safeToken('---', 'fallback'));
    }

    public function test_safe_token_normalizes_a_real_value(): void
    {
        $this->assertSame('alice-bob', TerminalLoopProofCanonicalizer::safeToken('Alice!@# Bob', 'fallback'));
    }

    // ── recursive list-preserving ksort ──────────────────────────────────────────

    public function test_ksort_recursive_orders_assoc_keys_but_preserves_list_order(): void
    {
        $input = [
            'b' => ['z' => 1, 'y' => 2],
            'a' => ['list', 'of', 'values'],
        ];

        $result = TerminalLoopProofCanonicalizer::ksortRecursive($input);

        $this->assertSame(['a', 'b'], array_keys($result));
        $this->assertSame(['y', 'z'], array_keys($result['b']));
        $this->assertSame(['list', 'of', 'values'], $result['a'], 'list order must never be reordered');
    }

    // ── stableHash excludes volatile completion-binding fields ──────────────────

    public function test_stable_hash_is_insensitive_to_all_volatile_fields_simultaneously(): void
    {
        $a = TerminalLoopProofCanonicalizer::stableHash([
            'task_id' => 'abc',
            'generated_at' => 1111,
            'terminal_loop_operational_proof_hash' => 'hash-a',
            'completion_audit_binding_packet' => ['p' => 1],
            'completion_audit_binding_packet_hash' => 'bind-a',
        ]);
        $b = TerminalLoopProofCanonicalizer::stableHash([
            'task_id' => 'abc',
            'generated_at' => 9999,
            'terminal_loop_operational_proof_hash' => 'hash-b',
            'completion_audit_binding_packet' => ['p' => 2],
            'completion_audit_binding_packet_hash' => 'bind-b',
        ]);

        $this->assertSame($a, $b, 'volatile completion-binding fields must never perturb stableHash');
    }

    public function test_stable_hash_still_changes_when_real_data_changes(): void
    {
        $a = TerminalLoopProofCanonicalizer::stableHash(['task_id' => 'abc']);
        $b = TerminalLoopProofCanonicalizer::stableHash(['task_id' => 'xyz']);

        $this->assertNotSame($a, $b);
    }

    public function test_stable_hash_is_order_independent(): void
    {
        $a = TerminalLoopProofCanonicalizer::stableHash(['x' => 1, 'y' => 2]);
        $b = TerminalLoopProofCanonicalizer::stableHash(['y' => 2, 'x' => 1]);

        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a));
    }

    // ── recursive secret/placeholder detection ───────────────────────────────────

    public function test_nested_secret_keys_are_rejected(): void
    {
        $this->assertTrue(TerminalLoopProofCanonicalizer::hasSecretOrPlaceholder([
            'meta' => ['nested' => ['API_KEY' => 'x']],
        ]));
        $this->assertTrue(TerminalLoopProofCanonicalizer::hasSecretOrPlaceholder(['auth' => ['TOKEN' => 'y']]));
        $this->assertTrue(TerminalLoopProofCanonicalizer::hasSecretOrPlaceholder(['db' => ['PASSWORD' => 'z']]));
        $this->assertTrue(TerminalLoopProofCanonicalizer::hasSecretOrPlaceholder(['user' => ['SECRET' => 'w']]));
    }

    public function test_nested_placeholder_values_are_rejected(): void
    {
        $this->assertTrue(TerminalLoopProofCanonicalizer::hasSecretOrPlaceholder([
            'context' => ['nested' => ['field' => '<placeholder>']],
        ]));
        $this->assertTrue(TerminalLoopProofCanonicalizer::hasSecretOrPlaceholder(['a' => ['b' => 'TODO']]));
        $this->assertTrue(TerminalLoopProofCanonicalizer::hasSecretOrPlaceholder(['a' => ['b' => 'fake-value']]));
    }

    public function test_clean_nested_payload_passes(): void
    {
        $this->assertFalse(TerminalLoopProofCanonicalizer::hasSecretOrPlaceholder([
            'task' => ['id' => 'real-task-abc123', 'status' => 'done', 'meta' => ['count' => 5]],
        ]));
    }
}
