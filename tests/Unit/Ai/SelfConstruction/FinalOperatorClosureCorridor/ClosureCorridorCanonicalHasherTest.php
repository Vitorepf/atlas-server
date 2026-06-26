<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\FinalOperatorClosureCorridor;

use App\Services\Ai\SelfConstruction\FinalOperatorClosureCorridor\ClosureCorridorCanonicalHasher;
use Tests\TestCase;

class ClosureCorridorCanonicalHasherTest extends TestCase
{
    public function test_storage_app_path_normalizes_slashes(): void
    {
        self::assertSame('storage/app/foo/bar.json', ClosureCorridorCanonicalHasher::storageAppPath('/foo/bar.json'));
        self::assertSame('storage/app/foo/bar.json', ClosureCorridorCanonicalHasher::storageAppPath('foo/bar.json'));
        self::assertSame('storage/app/foo', ClosureCorridorCanonicalHasher::storageAppPath('foo/'));
    }

    public function test_storage_app_path_returns_empty_for_empty(): void
    {
        self::assertSame('', ClosureCorridorCanonicalHasher::storageAppPath(''));
        self::assertSame('', ClosureCorridorCanonicalHasher::storageAppPath('/'));
    }

    public function test_private_storage_app_path_normalizes(): void
    {
        self::assertSame('storage/app/private/foo/bar.json', ClosureCorridorCanonicalHasher::privateStorageAppPath('foo/bar.json'));
    }

    public function test_private_storage_app_path_returns_empty_for_empty(): void
    {
        self::assertSame('', ClosureCorridorCanonicalHasher::privateStorageAppPath(''));
    }

    public function test_strip_volatile_keys_removes_known_keys(): void
    {
        $payload = [
            'generated_at' => '2026-01-01',
            'audited_at' => '2026-01-02',
            'verified_at' => '2026-01-03',
            'certified_at' => '2026-01-04',
            'persisted_at' => '2026-01-05',
            'assessed_at' => '2026-01-06',
            'closure_corridor_hash' => 'abc',
            'operator_submission_envelopes_hash' => 'def',
            'stable' => 'value',
        ];

        $result = ClosureCorridorCanonicalHasher::stripVolatileKeys($payload);

        self::assertArrayNotHasKey('generated_at', $result);
        self::assertArrayNotHasKey('audited_at', $result);
        self::assertArrayNotHasKey('verified_at', $result);
        self::assertArrayNotHasKey('certified_at', $result);
        self::assertArrayNotHasKey('persisted_at', $result);
        self::assertArrayNotHasKey('assessed_at', $result);
        self::assertArrayNotHasKey('closure_corridor_hash', $result);
        self::assertArrayNotHasKey('operator_submission_envelopes_hash', $result);
        self::assertSame('value', $result['stable']);
    }

    public function test_strip_volatile_keys_is_recursive(): void
    {
        $payload = [
            'nested' => [
                'generated_at' => '2026-01-01',
                'data' => 'preserved',
            ],
        ];

        $result = ClosureCorridorCanonicalHasher::stripVolatileKeys($payload);

        self::assertArrayNotHasKey('generated_at', $result['nested']);
        self::assertSame('preserved', $result['nested']['data']);
    }

    public function test_ksort_recursive_sorts_associative(): void
    {
        $input = ['c' => 1, 'a' => 2, 'b' => 3];
        $result = ClosureCorridorCanonicalHasher::ksortRecursive($input);

        self::assertSame(['a' => 2, 'b' => 3, 'c' => 1], $result);
    }

    public function test_ksort_recursive_preserves_sequential(): void
    {
        $input = [3, 1, 2];
        $result = ClosureCorridorCanonicalHasher::ksortRecursive($input);

        self::assertSame([3, 1, 2], $result);
    }

    public function test_ksort_recursive_is_nested(): void
    {
        $input = ['outer' => ['z' => 1, 'a' => 2]];
        $result = ClosureCorridorCanonicalHasher::ksortRecursive($input);

        self::assertSame(['outer' => ['a' => 2, 'z' => 1]], $result);
    }

    public function test_stable_hash_is_deterministic(): void
    {
        $payload = ['b' => 2, 'a' => 1];
        $h1 = ClosureCorridorCanonicalHasher::stableHash($payload);
        $h2 = ClosureCorridorCanonicalHasher::stableHash($payload);

        self::assertSame($h1, $h2);
    }

    public function test_stable_hash_is_order_independent(): void
    {
        $h1 = ClosureCorridorCanonicalHasher::stableHash(['b' => 2, 'a' => 1]);
        $h2 = ClosureCorridorCanonicalHasher::stableHash(['a' => 1, 'b' => 2]);

        self::assertSame($h1, $h2);
    }

    public function test_stable_hash_strips_volatile_keys(): void
    {
        $withVolatile = ['stable' => 'x', 'generated_at' => '2026-01-01', 'closure_corridor_hash' => 'old'];
        $withoutVolatile = ['stable' => 'x'];

        self::assertSame(
            ClosureCorridorCanonicalHasher::stableHash($withVolatile),
            ClosureCorridorCanonicalHasher::stableHash($withoutVolatile),
        );
    }

    public function test_stable_hash_returns_sha256_hex(): void
    {
        $hash = ClosureCorridorCanonicalHasher::stableHash(['test' => true]);

        self::assertSame(64, strlen($hash));
        self::assertTrue(ctype_xdigit($hash));
    }
}
