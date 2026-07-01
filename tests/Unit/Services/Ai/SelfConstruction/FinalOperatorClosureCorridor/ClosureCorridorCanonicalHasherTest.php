<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\FinalOperatorClosureCorridor;

use App\Services\Ai\SelfConstruction\FinalOperatorClosureCorridor\ClosureCorridorCanonicalHasher;
use Tests\TestCase;

final class ClosureCorridorCanonicalHasherTest extends TestCase
{
    // ── path helpers ──────────────────────────────────────────────────────────────

    public function test_storage_app_path_strips_leading_and_trailing_slashes_exactly(): void
    {
        self::assertSame('storage/app/a/b.json', ClosureCorridorCanonicalHasher::storageAppPath('/a/b.json/'));
    }

    public function test_private_storage_app_path_strips_leading_and_trailing_slashes_exactly(): void
    {
        self::assertSame('storage/app/private/a/b.json', ClosureCorridorCanonicalHasher::privateStorageAppPath('/a/b.json/'));
    }

    public function test_private_storage_app_path_returns_empty_when_only_slashes(): void
    {
        self::assertSame('', ClosureCorridorCanonicalHasher::privateStorageAppPath('///'));
    }

    // ── stableHash volatility exclusion, recursively ────────────────────────────────

    public function test_stable_hash_excludes_all_named_volatile_keys_at_top_level(): void
    {
        $base = ['facts' => 'stable'];
        $withVolatile = array_merge($base, [
            'generated_at' => 't1',
            'audited_at' => 't2',
            'verified_at' => 't3',
            'certified_at' => 't4',
            'persisted_at' => 't5',
            'assessed_at' => 't6',
            'closure_corridor_hash' => 'h1',
            'operator_submission_envelopes_hash' => 'h2',
        ]);

        self::assertSame(
            ClosureCorridorCanonicalHasher::stableHash($base),
            ClosureCorridorCanonicalHasher::stableHash($withVolatile),
        );
    }

    public function test_stable_hash_excludes_volatile_keys_nested_inside_sub_arrays(): void
    {
        $base = ['record' => ['facts' => 'stable']];
        $withNestedVolatile = ['record' => [
            'facts' => 'stable',
            'generated_at' => 't1',
            'certified_at' => 't2',
            'closure_corridor_hash' => 'h',
        ]];

        self::assertSame(
            ClosureCorridorCanonicalHasher::stableHash($base),
            ClosureCorridorCanonicalHasher::stableHash($withNestedVolatile),
        );
    }

    // ── substantive field sensitivity ────────────────────────────────────────────

    public function test_stable_hash_changes_when_a_non_volatile_field_changes(): void
    {
        $a = ClosureCorridorCanonicalHasher::stableHash(['facts' => 'one']);
        $b = ClosureCorridorCanonicalHasher::stableHash(['facts' => 'two']);

        self::assertNotSame($a, $b);
    }

    public function test_stable_hash_changes_when_a_nested_non_volatile_field_changes(): void
    {
        $a = ClosureCorridorCanonicalHasher::stableHash(['record' => ['status' => 'ok']]);
        $b = ClosureCorridorCanonicalHasher::stableHash(['record' => ['status' => 'blocked']]);

        self::assertNotSame($a, $b);
    }

    // ── associative-key ordering stability vs list order preservation ───────────────

    public function test_stable_hash_is_associative_key_order_independent(): void
    {
        $a = ClosureCorridorCanonicalHasher::stableHash(['zeta' => 1, 'alpha' => 2]);
        $b = ClosureCorridorCanonicalHasher::stableHash(['alpha' => 2, 'zeta' => 1]);

        self::assertSame($a, $b);
    }

    public function test_ksort_recursive_preserves_list_order_while_sorting_nested_assoc_keys(): void
    {
        $input = [
            'items' => ['third', 'first', 'second'],
            'meta' => ['z' => 1, 'a' => 2],
        ];

        $result = ClosureCorridorCanonicalHasher::ksortRecursive($input);

        self::assertSame(['third', 'first', 'second'], $result['items']);
        self::assertSame(['a' => 2, 'z' => 1], $result['meta']);
    }

    public function test_stable_hash_is_sensitive_to_list_order_changes(): void
    {
        $a = ClosureCorridorCanonicalHasher::stableHash(['items' => ['one', 'two']]);
        $b = ClosureCorridorCanonicalHasher::stableHash(['items' => ['two', 'one']]);

        self::assertNotSame($a, $b, 'list element order is semantic and must not be normalized away');
    }
}
