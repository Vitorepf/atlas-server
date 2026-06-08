<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compression;

use App\Services\Ai\Compression\Support\VolatileTokenRelocator;
use PHPUnit\Framework\TestCase;

final class VolatileTokenRelocatorTest extends TestCase
{
    public function test_relocates_uuid_and_timestamp_out_of_prefix_into_tail(): void
    {
        $relocator = new VolatileTokenRelocator;
        $prompt = 'Current date: 2026-06-08T10:00:00Z trace 550e8400-e29b-41d4-a716-446655440000 — do the work.';

        $result = $relocator->relocate($prompt);

        $this->assertGreaterThan(0, $result['relocated']);
        // Values are PRESERVED (moved, never dropped).
        $this->assertStringContainsString('2026-06-08T10:00:00Z', $result['prompt']);
        $this->assertStringContainsString('550e8400-e29b-41d4-a716-446655440000', $result['prompt']);
        // They now live in the labelled tail, not inline in the prefix.
        $this->assertStringContainsString('[atlas:context cache-stable aliases', $result['prompt']);
        $this->assertStringContainsString('<<ctx1>>', $result['prompt']);
    }

    public function test_two_prompts_differing_only_in_volatile_values_get_an_identical_prefix(): void
    {
        $relocator = new VolatileTokenRelocator;
        $a = $relocator->relocate('run 2026-06-08T10:00:00Z id 550e8400-e29b-41d4-a716-446655440000 now');
        $b = $relocator->relocate('run 2026-06-09T23:59:59Z id 550e8400-e29b-41d4-a716-999999999999 now');

        $prefixA = substr($a['prompt'], 0, (int) strpos($a['prompt'], '[atlas:context'));
        $prefixB = substr($b['prompt'], 0, (int) strpos($b['prompt'], '[atlas:context'));

        // The whole point of CacheAligner: the aliased prefix is now byte-stable
        // across calls that differ only in volatile values -> the cache can hit.
        $this->assertSame($prefixA, $prefixB);
    }

    public function test_is_deterministic(): void
    {
        $relocator = new VolatileTokenRelocator;
        $prompt = 'id 550e8400-e29b-41d4-a716-446655440000 at 2026-06-08T10:00:00Z';

        $this->assertSame($relocator->relocate($prompt), $relocator->relocate($prompt));
    }

    public function test_returns_unchanged_when_no_volatile_tokens(): void
    {
        $relocator = new VolatileTokenRelocator;
        $prompt = 'Plain instructions with no volatile identifiers at all.';

        $result = $relocator->relocate($prompt);

        $this->assertSame(0, $result['relocated']);
        $this->assertSame($prompt, $result['prompt']);
    }

    public function test_empty_prompt_is_safe(): void
    {
        $result = (new VolatileTokenRelocator)->relocate('');
        $this->assertSame('', $result['prompt']);
        $this->assertSame(0, $result['relocated']);
    }
}
