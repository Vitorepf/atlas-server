<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\AtlasContextStringListNormalizer;
use PHPUnit\Framework\TestCase;

final class AtlasContextStringListNormalizerTest extends TestCase
{
    public function test_strings_from_array_cast_preserves_raw_context_refs(): void
    {
        $this->assertSame(
            [' app/Foo.php ', '', '0'],
            AtlasContextStringListNormalizer::stringsFromArrayCast([' app/Foo.php ', 42, '', null, '0']),
        );

        $this->assertSame(['scalar-ref'], AtlasContextStringListNormalizer::stringsFromArrayCast('scalar-ref'));
    }

    public function test_unique_trimmed_strings_ignores_non_scalars_and_preserves_first_seen_order(): void
    {
        $this->assertSame(
            ['Alpha', '42', 'beta'],
            AtlasContextStringListNormalizer::uniqueTrimmedStrings([' Alpha ', ['ignored'], 42, '', 'beta', 'Alpha']),
        );
    }

    public function test_unique_trimmed_strings_can_lowercase(): void
    {
        $this->assertSame(
            ['alpha', 'beta'],
            AtlasContextStringListNormalizer::uniqueTrimmedStrings([' Alpha ', 'BETA', 'alpha'], lowercase: true),
        );
    }

    public function test_unique_mapped_strings_uses_callback_and_ignores_non_scalar_results(): void
    {
        $this->assertSame(
            ['doc', 'memory'],
            AtlasContextStringListNormalizer::uniqueMappedStrings(
                [
                    ['source' => ' doc '],
                    ['source' => ['ignored']],
                    ['source' => 'memory'],
                    ['source' => 'DOC'],
                ],
                static fn (mixed $value): mixed => is_array($value) ? ($value['source'] ?? null) : null,
                lowercase: true,
            ),
        );
    }
}
