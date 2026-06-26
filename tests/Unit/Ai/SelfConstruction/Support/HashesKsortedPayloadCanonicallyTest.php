<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\Support\HashesKsortedPayloadCanonically;
use Tests\TestCase;

final class HashesKsortedPayloadCanonicallyTest extends TestCase
{
    public function test_stable_hash_returns_a_64_hex_sha256_string(): void
    {
        $host = $this->makeHost();

        $hash = $host->call(['a' => 1, 'b' => 2]);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_stable_hash_is_deterministic(): void
    {
        $host = $this->makeHost();

        $this->assertSame($host->call(['x' => 1, 'y' => 2]), $host->call(['x' => 1, 'y' => 2]));
    }

    public function test_stable_hash_is_key_order_insensitive_at_top_level(): void
    {
        $host = $this->makeHost();

        $a = $host->call(['x' => 1, 'y' => 2, 'z' => 3]);
        $b = $host->call(['z' => 3, 'y' => 2, 'x' => 1]);

        $this->assertSame($a, $b);
    }

    public function test_stable_hash_is_key_order_insensitive_in_nested_maps(): void
    {
        $host = $this->makeHost();

        $a = $host->call([
            'outer' => ['z' => 1, 'y' => 2, 'x' => 3],
            'extra' => ['k' => 'v'],
        ]);
        $b = $host->call([
            'extra' => ['k' => 'v'],
            'outer' => ['x' => 3, 'y' => 2, 'z' => 1],
        ]);

        $this->assertSame($a, $b);
    }

    public function test_stable_hash_diffs_on_value_change(): void
    {
        $host = $this->makeHost();

        $this->assertNotSame(
            $host->call(['a' => 1, 'b' => 2]),
            $host->call(['a' => 1, 'b' => 3])
        );
    }

    public function test_stable_hash_preserves_list_order(): void
    {
        $host = $this->makeHost();

        $a = $host->call(['items' => [3, 1, 2]]);
        $b = $host->call(['items' => [1, 2, 3]]);

        $this->assertNotSame($a, $b, 'lists are ordered by position, so reordering must change the hash');
    }

    public function test_stable_hash_handles_deeply_nested_mixed_structures(): void
    {
        $host = $this->makeHost();

        $input = [
            'z' => [
                'inner_list' => [10, 20, 30],
                'inner_map' => ['y' => 1, 'x' => 2],
            ],
            'a' => 'value',
        ];

        $hash = $host->call($input);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);

        // Same shape, different ordering at every map level → same hash.
        $reordered = [
            'a' => 'value',
            'z' => [
                'inner_map' => ['x' => 2, 'y' => 1],
                'inner_list' => [10, 20, 30],
            ],
        ];
        $this->assertSame($hash, $host->call($reordered));
    }

    private function makeHost(): object
    {
        return new class
        {
            use HashesKsortedPayloadCanonically;

            public function call(array $payload): string
            {
                return $this->doHash($payload);
            }

            private function doHash(array $payload): string
            {
                return $this->stableHash($payload);
            }

            private function recursivelyKsort(array $value): array
            {
                if (array_is_list($value)) {
                    foreach ($value as $index => $item) {
                        if (is_array($item)) {
                            $value[$index] = $this->recursivelyKsort($item);
                        }
                    }

                    return $value;
                }
                ksort($value);
                foreach ($value as $key => $item) {
                    if (is_array($item)) {
                        $value[$key] = $this->recursivelyKsort($item);
                    }
                }

                return $value;
            }
        };
    }
}
