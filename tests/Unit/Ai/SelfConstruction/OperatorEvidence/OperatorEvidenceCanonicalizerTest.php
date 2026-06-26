<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\OperatorEvidence;

use App\Services\Ai\SelfConstruction\OperatorEvidence\OperatorEvidenceCanonicalizer;
use Tests\TestCase;

class OperatorEvidenceCanonicalizerTest extends TestCase
{
    public function test_placeholder_fields_extracts_angle_brackets(): void
    {
        $fields = OperatorEvidenceCanonicalizer::placeholderFieldsFromCommand('cmd --opt=<task>');
        self::assertSame(['<task>'], $fields);
    }

    public function test_placeholder_fields_extracts_path_templates(): void
    {
        $fields = OperatorEvidenceCanonicalizer::placeholderFieldsFromCommand('cmd @/path/to/file.json');
        self::assertSame(['@/path/to/file.json'], $fields);
    }

    public function test_placeholder_fields_deduplicates(): void
    {
        $fields = OperatorEvidenceCanonicalizer::placeholderFieldsFromCommand('cmd <a> <b> <a>');
        self::assertSame(['<a>', '<b>'], $fields);
    }

    public function test_placeholder_fields_empty_for_no_match(): void
    {
        self::assertSame([], OperatorEvidenceCanonicalizer::placeholderFieldsFromCommand('plain command'));
    }

    public function test_normalize_storage_path_strips_prefixes(): void
    {
        self::assertSame('foo/bar.json', OperatorEvidenceCanonicalizer::normalizeStoragePath('storage/app/private/foo/bar.json'));
        self::assertSame('foo/bar.json', OperatorEvidenceCanonicalizer::normalizeStoragePath('storage/app/foo/bar.json'));
        self::assertSame('foo/bar.json', OperatorEvidenceCanonicalizer::normalizeStoragePath('foo/bar.json'));
    }

    public function test_normalize_storage_path_strips_slashes(): void
    {
        self::assertSame('foo', OperatorEvidenceCanonicalizer::normalizeStoragePath('/foo/'));
    }

    public function test_normalize_storage_path_empty_for_dotdot(): void
    {
        self::assertSame('', OperatorEvidenceCanonicalizer::normalizeStoragePath('../etc/passwd'));
    }

    public function test_normalize_storage_path_empty_for_empty(): void
    {
        self::assertSame('', OperatorEvidenceCanonicalizer::normalizeStoragePath(''));
    }

    public function test_empty_verification_structure(): void
    {
        $result = OperatorEvidenceCanonicalizer::emptyVerification('missing_payload');

        self::assertSame('not_supplied', $result['status']);
        self::assertSame('missing_payload', $result['reason']);
        self::assertSame([], $result['violations']);
        self::assertSame(0, $result['violation_count']);
    }

    public function test_stable_hash_is_deterministic(): void
    {
        $a = OperatorEvidenceCanonicalizer::stableHash(['b' => 2, 'a' => 1]);
        $b = OperatorEvidenceCanonicalizer::stableHash(['b' => 2, 'a' => 1]);
        self::assertSame($a, $b);
    }

    public function test_stable_hash_order_independent(): void
    {
        $a = OperatorEvidenceCanonicalizer::stableHash(['b' => 2, 'a' => 1]);
        $b = OperatorEvidenceCanonicalizer::stableHash(['a' => 1, 'b' => 2]);
        self::assertSame($a, $b);
    }

    public function test_stable_hash_strips_volatile_keys(): void
    {
        $withVolatile = ['stable' => 'x', 'generated_at' => '2026-01-01', 'submission_readiness_hash' => 'old'];
        $withoutVolatile = ['stable' => 'x'];

        self::assertSame(
            OperatorEvidenceCanonicalizer::stableHash($withVolatile),
            OperatorEvidenceCanonicalizer::stableHash($withoutVolatile),
        );
    }

    public function test_stable_hash_strips_diagnostic_violations(): void
    {
        $withViolations = [
            'diagnostics' => [
                'runtime_promotion_receipt' => ['violations' => ['err'], 'data' => 'x'],
                'real_provider_smoke' => ['violations' => ['err2'], 'data' => 'y'],
                'human_completion_receipt' => ['violations' => ['err3'], 'data' => 'z'],
            ],
        ];
        $withoutViolations = [
            'diagnostics' => [
                'runtime_promotion_receipt' => ['data' => 'x'],
                'real_provider_smoke' => ['data' => 'y'],
                'human_completion_receipt' => ['data' => 'z'],
            ],
        ];

        self::assertSame(
            OperatorEvidenceCanonicalizer::stableHash($withViolations),
            OperatorEvidenceCanonicalizer::stableHash($withoutViolations),
        );
    }

    public function test_ksort_recursive_sorts_associative(): void
    {
        self::assertSame(['a' => 1, 'b' => 2], OperatorEvidenceCanonicalizer::ksortRecursive(['b' => 2, 'a' => 1]));
    }

    public function test_ksort_recursive_preserves_sequential(): void
    {
        self::assertSame([3, 1, 2], OperatorEvidenceCanonicalizer::ksortRecursive([3, 1, 2]));
    }
}
