<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\Support\HashesPayloadCanonically;
use Tests\TestCase;

final class HashesPayloadCanonicallyTest extends TestCase
{
    public function test_stable_hash_returns_64_hex_sha256(): void
    {
        $host = $this->makeHost();

        $hash = $host->call(['a' => 1, 'b' => 2]);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_stable_hash_is_deterministic_for_same_input(): void
    {
        $host = $this->makeHost();

        $this->assertSame(
            $host->call(['x' => 1, 'y' => 2]),
            $host->call(['x' => 1, 'y' => 2])
        );
    }

    public function test_stable_hash_changes_when_top_level_keys_reorder(): void
    {
        $host = $this->makeHost();

        $a = $host->call(['x' => 1, 'y' => 2]);
        $b = $host->call(['y' => 2, 'x' => 1]);

        $this->assertNotSame(
            $a,
            $b,
            'the pure trait does NOT ksort, so top-level key reordering must change the hash'
        );
    }

    public function test_stable_hash_diffs_on_value_change(): void
    {
        $host = $this->makeHost();

        $this->assertNotSame(
            $host->call(['a' => 1, 'b' => 2]),
            $host->call(['a' => 1, 'b' => 3])
        );
    }

    public function test_stable_hash_handles_empty_array_and_nested(): void
    {
        $host = $this->makeHost();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $host->call([]));

        $deep = ['outer' => ['inner' => ['deep' => ['value' => true]]]];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $host->call($deep));
    }

    public function test_stable_hash_uses_unscaped_unicode_and_slashes(): void
    {
        $host = $this->makeHost();

        // We can verify the flag set by checking that a unicode + slash payload
        // round-trips losslessly through the encoded body (no \uXXXX escapes,
        // no backslash-escaped slashes).
        $payload = ['label' => 'Atlas — configuração', 'path' => 'atlas/self/x.json'];
        $hash = $host->call($payload);

        // The raw encoded JSON body of the canonical hash is the SHA-256 input;
        // recomputing it with the same flags must reproduce the hash. This proves
        // the trait encodes with JSON_UNESCAPED_UNICODE + JSON_UNESCAPED_SLASHES.
        $expected = hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame($expected, $hash);
    }

    private function makeHost(): object
    {
        return new class
        {
            use HashesPayloadCanonically;

            public function call(array $payload): string
            {
                return $this->doHash($payload);
            }

            private function doHash(array $payload): string
            {
                return $this->stableHash($payload);
            }
        };
    }
}
