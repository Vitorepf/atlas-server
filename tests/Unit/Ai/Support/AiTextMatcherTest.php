<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Support;

use App\Services\Ai\Support\AiTextMatcher;
use Tests\TestCase;

final class AiTextMatcherTest extends TestCase
{
    public function test_contains_any_needle_preserves_empty_needle_semantics(): void
    {
        $this->assertTrue(AiTextMatcher::containsAnyNeedle('atlas', ['']));
        $this->assertTrue(AiTextMatcher::containsAnyNeedle('atlas', ['tl']));
        $this->assertFalse(AiTextMatcher::containsAnyNeedle('atlas', ['forge']));
    }

    public function test_contains_any_non_empty_needle_skips_empty_needles(): void
    {
        $this->assertTrue(AiTextMatcher::containsAnyNonEmptyNeedle('corrija app/Foo.php', ['', 'app/']));
        $this->assertFalse(AiTextMatcher::containsAnyNonEmptyNeedle('corrija app/Foo.php', ['', 'tests/']));
    }

    public function test_contains_any_ascii_lower_needle_normalizes_needles_only(): void
    {
        $this->assertTrue(AiTextMatcher::containsAnyAsciiLowerNeedle('seguranca do modulo', ['Segurança']));
        $this->assertFalse(AiTextMatcher::containsAnyAsciiLowerNeedle('Segurança do módulo', ['segurança']));
    }
}
