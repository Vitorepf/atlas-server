<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\OperatorEvidence;

use App\Services\Ai\SelfConstruction\OperatorEvidence\OperatorEvidenceCanonicalizer;
use Tests\TestCase;

/**
 * Operator-supplied evidence must be visible for audit without being mistaken for native
 * steady-state proof: placeholder extraction from commands, storage path normalization and
 * traversal rejection, canonicalize redaction of raw provider/secret fields, forced
 * visibility_only=true, forced steady_state_proof=false, and stableHash volatility exclusion.
 */
final class OperatorEvidenceCanonicalizerTest extends TestCase
{
    // ── placeholder extraction from commands ─────────────────────────────────

    public function test_placeholder_fields_extracts_angle_brackets_and_path_templates(): void
    {
        self::assertSame(['<task>'], OperatorEvidenceCanonicalizer::placeholderFieldsFromCommand('cmd --opt=<task>'));
        self::assertSame(['@/path/to/file.json'], OperatorEvidenceCanonicalizer::placeholderFieldsFromCommand('cmd @/path/to/file.json'));
    }

    public function test_placeholder_fields_deduplicates_and_returns_empty_for_no_match(): void
    {
        self::assertSame(['<a>', '<b>'], OperatorEvidenceCanonicalizer::placeholderFieldsFromCommand('cmd <a> <b> <a>'));
        self::assertSame([], OperatorEvidenceCanonicalizer::placeholderFieldsFromCommand('plain command'));
    }

    // ── storage path normalization and traversal rejection ───────────────────

    public function test_normalize_storage_path_strips_known_prefixes_and_slashes(): void
    {
        self::assertSame('foo/bar.json', OperatorEvidenceCanonicalizer::normalizeStoragePath('storage/app/private/foo/bar.json'));
        self::assertSame('foo/bar.json', OperatorEvidenceCanonicalizer::normalizeStoragePath('storage/app/foo/bar.json'));
        self::assertSame('foo', OperatorEvidenceCanonicalizer::normalizeStoragePath('/foo/'));
    }

    public function test_normalize_storage_path_rejects_traversal_and_empty_input(): void
    {
        self::assertSame('', OperatorEvidenceCanonicalizer::normalizeStoragePath('../etc/passwd'));
        self::assertSame('', OperatorEvidenceCanonicalizer::normalizeStoragePath(''));
    }

    // ── canonicalize: redaction of raw provider/secret fields ────────────────

    public function test_canonicalize_removes_raw_prompt_provider_trace_raw_secret_and_secret(): void
    {
        $result = OperatorEvidenceCanonicalizer::canonicalize([
            'raw_prompt' => 'secret text',
            'provider_trace' => ['model' => 'opus'],
            'raw_secret' => 'sk-xxx',
            'secret' => 'tok',
            'x' => 1,
        ]);

        foreach (['raw_prompt', 'provider_trace', 'raw_secret', 'secret'] as $key) {
            self::assertArrayNotHasKey($key, $result, "canonicalize must remove {$key}");
        }
        self::assertSame(1, $result['x']);
    }

    // ── canonicalize: forced visibility_only / steady_state_proof ────────────

    public function test_canonicalize_forces_visibility_only_true_and_steady_state_proof_false(): void
    {
        $result = OperatorEvidenceCanonicalizer::canonicalize(['source' => 'operator']);

        self::assertTrue($result['visibility_only']);
        self::assertFalse($result['steady_state_proof']);
    }

    public function test_canonicalize_forces_flags_even_when_caller_tries_to_override_them(): void
    {
        $result = OperatorEvidenceCanonicalizer::canonicalize([
            'visibility_only' => false,
            'steady_state_proof' => true,
        ]);

        self::assertTrue($result['visibility_only'], 'operator evidence must never be able to disable visibility_only');
        self::assertFalse($result['steady_state_proof'], 'operator evidence must never claim native steady-state proof');
    }

    // ── stableHash: volatility exclusion ──────────────────────────────────────

    public function test_stable_hash_ignores_generated_at_submission_readiness_hash_and_diagnostic_violations(): void
    {
        $withVolatile = [
            'stable' => 'x',
            'generated_at' => '2026-01-01',
            'submission_readiness_hash' => 'old',
            'diagnostics' => [
                'runtime_promotion_receipt' => ['violations' => ['err'], 'data' => 'x'],
                'real_provider_smoke' => ['violations' => ['err2'], 'data' => 'y'],
                'human_completion_receipt' => ['violations' => ['err3'], 'data' => 'z'],
            ],
        ];
        $withoutVolatile = [
            'stable' => 'x',
            'diagnostics' => [
                'runtime_promotion_receipt' => ['data' => 'x'],
                'real_provider_smoke' => ['data' => 'y'],
                'human_completion_receipt' => ['data' => 'z'],
            ],
        ];

        self::assertSame(
            OperatorEvidenceCanonicalizer::stableHash($withVolatile),
            OperatorEvidenceCanonicalizer::stableHash($withoutVolatile),
        );
    }

    public function test_stable_hash_changes_when_substantive_evidence_fields_change(): void
    {
        $a = OperatorEvidenceCanonicalizer::stableHash(['stable' => 'x']);
        $b = OperatorEvidenceCanonicalizer::stableHash(['stable' => 'y']);

        self::assertNotSame($a, $b);
    }

    public function test_stable_hash_is_deterministic_and_order_independent(): void
    {
        self::assertSame(
            OperatorEvidenceCanonicalizer::stableHash(['b' => 2, 'a' => 1]),
            OperatorEvidenceCanonicalizer::stableHash(['a' => 1, 'b' => 2]),
        );
    }
}
