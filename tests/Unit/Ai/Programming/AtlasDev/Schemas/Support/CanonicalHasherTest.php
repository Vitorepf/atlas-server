<?php

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Support;

use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use PHPUnit\Framework\TestCase;

final class CanonicalHasherTest extends TestCase
{
    public function test_hash_matches_sha256_of_canonical_json(): void
    {
        $payload = ['b' => 2, 'a' => 1];

        $expected = hash('sha256', CanonicalJson::encode($payload));

        $this->assertSame($expected, CanonicalHasher::hash($payload));
    }

    public function test_hash_is_stable_regardless_of_input_order(): void
    {
        $first = CanonicalHasher::hash(['b' => 2, 'a' => 1, 'c' => 3]);
        $second = CanonicalHasher::hash(['a' => 1, 'c' => 3, 'b' => 2]);

        $this->assertSame($first, $second);
    }

    public function test_hash_changes_when_any_value_changes(): void
    {
        $baseline = CanonicalHasher::hash(['a' => 1, 'b' => 2]);
        $changed = CanonicalHasher::hash(['a' => 1, 'b' => 3]);

        $this->assertNotSame($baseline, $changed);
    }

    public function test_hash_without_excludes_named_field(): void
    {
        $withSelf = CanonicalHasher::hash(['a' => 1, 'self_hash' => 'whatever']);
        $expected = CanonicalHasher::hash(['a' => 1]);

        $without = CanonicalHasher::hashWithout(['a' => 1, 'self_hash' => 'whatever'], 'self_hash');

        $this->assertSame($expected, $without);
        $this->assertNotSame($withSelf, $without);
    }

    public function test_hash_is_64_hex_characters(): void
    {
        $hash = CanonicalHasher::hash(['a' => 1]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }
}
