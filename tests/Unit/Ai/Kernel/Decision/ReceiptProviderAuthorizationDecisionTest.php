<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Kernel\Decision;

use App\Services\Ai\Kernel\Decision\ReceiptProviderAuthorizationDecision;
use PHPUnit\Framework\TestCase;

final class ReceiptProviderAuthorizationDecisionTest extends TestCase
{
    private ReceiptProviderAuthorizationDecision $decision;

    protected function setUp(): void
    {
        $this->decision = new ReceiptProviderAuthorizationDecision();
    }

    public function testExactMatchAuthorizesViaPrimary(): void
    {
        $result = $this->decision->authorize(
            ['primary' => 'claude_cli', 'fallbacks' => ['codex_cli']],
            'claude_cli',
            null,
        );

        $this->assertSame('atlas.decide.receipt_provider_authorization.v1', $result['schema_version']);
        $this->assertTrue($result['authorized']);
        $this->assertSame('exact_match', $result['code']);
        $this->assertSame('claude_cli', $result['expected_provider']);
        $this->assertSame('primary', $result['matched_via']);
        $this->assertSame(['codex_cli'], $result['fallbacks_considered']);
    }

    public function testWildcardAutoAuthorizesAnyRuntime(): void
    {
        $auto = $this->decision->authorize(['primary' => 'auto'], 'gpt_5', null);
        $selected = $this->decision->authorize(['primary' => 'selected-by-decide'], 'minimax_m3', null);

        $this->assertTrue($auto['authorized']);
        $this->assertSame('wildcard_auto', $auto['code']);
        $this->assertSame('wildcard', $auto['matched_via']);
        $this->assertSame('auto', $auto['expected_provider']);

        $this->assertTrue($selected['authorized']);
        $this->assertSame('wildcard_auto', $selected['code']);
        $this->assertSame('selected-by-decide', $selected['expected_provider']);
    }

    public function testFamilyMatchAuthorizesClaudeCodexToCodexCli(): void
    {
        $result = $this->decision->authorize(
            ['primary' => 'claude_codex'],
            'codex_cli',
            null,
        );

        $this->assertTrue($result['authorized']);
        $this->assertSame('family_match', $result['code']);
        $this->assertSame('family', $result['matched_via']);
        $this->assertSame('claude_codex', $result['expected_provider']);

        // Same family also authorizes the sibling CLI member.
        $sibling = $this->decision->authorize(['primary' => 'claude_codex'], 'claude_cli', null);
        $this->assertTrue($sibling['authorized']);
        $this->assertSame('family_match', $sibling['code']);
    }

    public function testStageExceptionOnlyAppliesUnderContextScout(): void
    {
        $inScout = $this->decision->authorize(
            ['primary' => 'claude_cli'],
            'gemini_cli',
            'context_scout',
        );

        $this->assertTrue($inScout['authorized']);
        $this->assertSame('stage_exception', $inScout['code']);
        $this->assertSame('stage', $inScout['matched_via']);
        $this->assertSame('claude_cli', $inScout['expected_provider']);

        // gemini_cli outside the context_scout stage is NOT authorized.
        $outsideScout = $this->decision->authorize(
            ['primary' => 'claude_cli'],
            'gemini_cli',
            'plan',
        );

        $this->assertFalse($outsideScout['authorized']);
        $this->assertSame('provider_mismatch', $outsideScout['code']);
        $this->assertNull($outsideScout['matched_via']);
        $this->assertSame('claude_cli', $outsideScout['expected_provider']);

        // R1 fail-closed short-circuits BEFORE the R5 stage exception: an
        // empty/blank authorized primary must veto gemini_cli even inside the
        // context_scout stage, with expected_provider never bound. Were R5 to
        // run ahead of R1 (or R1 to stop short-circuiting), this would fail
        // open and authorize an unverified provider.
        $blankPrimaryInScout = $this->decision->authorize(
            ['primary' => '   '],
            'gemini_cli',
            'context_scout',
        );

        $this->assertFalse($blankPrimaryInScout['authorized']);
        $this->assertSame('provider_mismatch', $blankPrimaryInScout['code']);
        $this->assertNull($blankPrimaryInScout['matched_via']);
        $this->assertNull($blankPrimaryInScout['expected_provider']);
    }

    public function testFallbackMatchAuthorizesRuntimePresentInFallbacks(): void
    {
        $result = $this->decision->authorize(
            ['primary' => 'claude_cli', 'fallbacks' => ['codex_cli', 'gpt_5']],
            'gpt_5',
            null,
        );

        $this->assertTrue($result['authorized']);
        $this->assertSame('fallback_match', $result['code']);
        $this->assertSame('fallback', $result['matched_via']);
        $this->assertContains('gpt_5', $result['fallbacks_considered']);
        $this->assertSame(['codex_cli', 'gpt_5'], $result['fallbacks_considered']);
        $this->assertSame('claude_cli', $result['expected_provider']);
    }

    public function testEmptyPrimaryFailsClosedWithNullExpectedProvider(): void
    {
        $result = $this->decision->authorize(
            ['primary' => '   ', 'fallbacks' => ['codex_cli']],
            'codex_cli',
            null,
        );

        $this->assertFalse($result['authorized']);
        $this->assertSame('provider_mismatch', $result['code']);
        $this->assertNull($result['expected_provider']);
        $this->assertNull($result['matched_via']);
        // R1 short-circuits even though runtime matches a fallback.
        $this->assertSame(['codex_cli'], $result['fallbacks_considered']);
    }

    public function testMissingPrimaryKeyFailsClosed(): void
    {
        $result = $this->decision->authorize([], 'claude_cli', null);

        $this->assertFalse($result['authorized']);
        $this->assertSame('provider_mismatch', $result['code']);
        $this->assertNull($result['expected_provider']);
        $this->assertSame([], $result['fallbacks_considered']);
    }

    public function testEmptyRuntimeProviderFailsClosed(): void
    {
        $result = $this->decision->authorize(
            ['primary' => 'claude_cli', 'fallbacks' => ['codex_cli']],
            '   ',
            'context_scout',
        );

        $this->assertFalse($result['authorized']);
        $this->assertSame('provider_mismatch', $result['code']);
        $this->assertNull($result['expected_provider']);
        $this->assertNull($result['matched_via']);
    }

    public function testNoRuleSatisfiedReturnsMismatchWithExpectedBound(): void
    {
        $result = $this->decision->authorize(
            ['primary' => 'claude_cli', 'fallbacks' => ['codex_cli']],
            'gemini_cli',
            null,
        );

        $this->assertFalse($result['authorized']);
        $this->assertSame('provider_mismatch', $result['code']);
        $this->assertNull($result['matched_via']);
        // R7 still binds expected_provider to the trimmed primary.
        $this->assertSame('claude_cli', $result['expected_provider']);
    }

    public function testFallbacksAreNormalizedTrimmedAndReindexedAsStringList(): void
    {
        $result = $this->decision->authorize(
            ['primary' => 'claude_cli', 'fallbacks' => ['  codex_cli  ', '', '  ', 0, 'gpt_5']],
            'gpt_5',
            null,
        );

        $this->assertTrue($result['authorized']);
        $this->assertSame('fallback_match', $result['code']);
        // Trimmed, empty/whitespace dropped, numeric coerced to string, keys re-indexed (list<string>).
        $this->assertSame(['codex_cli', '0', 'gpt_5'], $result['fallbacks_considered']);
        $this->assertSame([0, 1, 2], array_keys($result['fallbacks_considered']));
        foreach ($result['fallbacks_considered'] as $value) {
            $this->assertIsString($value);
        }
    }

    public function testNonScalarFallbackElementIsDroppedWithoutEmittingWarning(): void
    {
        // A malformed nested array among the fallbacks must NOT coerce to the
        // literal string "Array" and must NOT emit an "Array to string conversion"
        // warning — the kernel is documented pure with no side effects.
        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured[] = $errstr;

            return true; // swallow so a regression surfaces as an assertion failure, not a fatal.
        });

        try {
            $result = $this->decision->authorize(
                ['primary' => 'claude_cli', 'fallbacks' => [['a'], '  codex_cli  ', 0, 'gpt_5']],
                'gpt_5',
                null,
            );
        } finally {
            restore_error_handler();
        }

        // No runtime warning (e.g. "Array to string conversion") escaped the pure kernel.
        $this->assertSame([], $captured);

        // The non-scalar entry is dropped outright — never admitted as the bogus "Array" token —
        // while the surrounding scalars normalize exactly as before (trim, int 0 -> "0", reindex).
        $this->assertSame(['codex_cli', '0', 'gpt_5'], $result['fallbacks_considered']);
        $this->assertNotContains('Array', $result['fallbacks_considered']);

        // Scalars alongside the dropped element still authorize via the fallback rule.
        $this->assertTrue($result['authorized']);
        $this->assertSame('fallback_match', $result['code']);
        $this->assertSame('fallback', $result['matched_via']);
    }

    public function testRulePrecedenceFamilyWinsOverFallbackForClaudeCodex(): void
    {
        // runtime is both a claude_codex family member AND listed in fallbacks;
        // ordered rules must resolve to family_match (R4) before fallback (R6).
        $result = $this->decision->authorize(
            ['primary' => 'claude_codex', 'fallbacks' => ['codex_cli']],
            'codex_cli',
            null,
        );

        $this->assertTrue($result['authorized']);
        $this->assertSame('family_match', $result['code']);
        $this->assertSame('family', $result['matched_via']);
    }

    public function testPrimaryIsTrimmedBeforeExactMatch(): void
    {
        $result = $this->decision->authorize(
            ['primary' => '  claude_cli  '],
            'claude_cli',
            null,
        );

        $this->assertTrue($result['authorized']);
        $this->assertSame('exact_match', $result['code']);
        $this->assertSame('claude_cli', $result['expected_provider']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $selection = ['primary' => 'claude_codex', 'fallbacks' => ['codex_cli', 'gpt_5']];

        $first = $this->decision->authorize($selection, 'gpt_5', null);
        $second = $this->decision->authorize($selection, 'gpt_5', null);

        $this->assertSame($first, $second);
    }
}
