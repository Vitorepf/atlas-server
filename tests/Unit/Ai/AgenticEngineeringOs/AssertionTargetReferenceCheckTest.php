<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\AssertionTargetReferenceCheck;
use Tests\TestCase;

final class AssertionTargetReferenceCheckTest extends TestCase
{
    private AssertionTargetReferenceCheck $check;

    protected function setUp(): void
    {
        parent::setUp();

        $this->check = new AssertionTargetReferenceCheck();
    }

    public function testEmptyChangedSymbolsReturnsNoTargetSymbols(): void
    {
        $result = $this->check->referencesChangedSymbol('$svc->computeScore(3);', []);

        $this->assertFalse($result['exercises']);
        $this->assertSame([], $result['matched_symbols']);
        $this->assertSame('no_target_symbols', $result['reason']);
    }

    public function testSymbolOnlyInCommentAndImportIsNotReferenced(): void
    {
        $source = <<<'PHP'
            <?php

            use App\Foo\computeScore;

            // computeScore is TODO
            final class Probe
            {
                public function exercise(): void
                {
                    $this->assertTrue(true);
                }
            }
            PHP;

        $result = $this->check->referencesChangedSymbol($source, ['App\Foo\Bar::computeScore']);

        $this->assertFalse($result['exercises']);
        $this->assertSame([], $result['matched_symbols']);
        $this->assertSame('no_reference_to_changed_symbol', $result['reason']);
    }

    public function testRealCallToFullyQualifiedSymbolIsReferenced(): void
    {
        $source = <<<'PHP'
            <?php

            $svc = new Service();
            $value = $svc->computeScore(3);
            PHP;

        $result = $this->check->referencesChangedSymbol($source, ['App\Foo\Bar::computeScore']);

        $this->assertTrue($result['exercises']);
        $this->assertSame(['computeScore'], $result['matched_symbols']);
        $this->assertSame('references_changed_symbol', $result['reason']);
    }

    public function testWordBoundaryRejectsSubstringMatch(): void
    {
        $source = <<<'PHP'
            <?php

            $value = $svc->computeScoreInternal(3);
            PHP;

        $result = $this->check->referencesChangedSymbol($source, ['Score']);

        $this->assertFalse($result['exercises']);
        $this->assertSame([], $result['matched_symbols']);
        $this->assertSame('no_reference_to_changed_symbol', $result['reason']);
    }

    public function testTwoReferencedSymbolsKeptInFirstSeenOrder(): void
    {
        $source = <<<'PHP'
            <?php

            $alpha = $svc->computeScore(3);
            $beta = $svc->renderReport($alpha);
            PHP;

        $result = $this->check->referencesChangedSymbol(
            $source,
            ['App\Foo\Bar::computeScore', 'App\Foo\Baz::renderReport'],
        );

        $this->assertTrue($result['exercises']);
        $this->assertContains('computeScore', $result['matched_symbols']);
        $this->assertContains('renderReport', $result['matched_symbols']);
        $this->assertSame(['computeScore', 'renderReport'], $result['matched_symbols']);
        $this->assertSame('references_changed_symbol', $result['reason']);
    }

    public function testInlineCommentReferenceIsStrippedBeforeMatching(): void
    {
        $source = <<<'PHP'
            <?php

            $value = $svc->run(); // computeScore handled elsewhere
            PHP;

        $result = $this->check->referencesChangedSymbol($source, ['App\Foo\Bar::computeScore']);

        $this->assertFalse($result['exercises']);
        $this->assertSame([], $result['matched_symbols']);
        $this->assertSame('no_reference_to_changed_symbol', $result['reason']);
    }

    public function testDuplicateChangedSymbolsAreDeDupedFirstSeen(): void
    {
        $source = <<<'PHP'
            <?php

            $value = $svc->computeScore(3);
            PHP;

        $result = $this->check->referencesChangedSymbol(
            $source,
            ['App\Foo\Bar::computeScore', 'App\Other\Thing::computeScore'],
        );

        $this->assertTrue($result['exercises']);
        $this->assertSame(['computeScore'], $result['matched_symbols']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $source = '$svc->computeScore(3);';
        $symbols = ['App\Foo\Bar::computeScore'];

        $first = $this->check->referencesChangedSymbol($source, $symbols);
        $second = $this->check->referencesChangedSymbol($source, $symbols);

        $this->assertSame($first, $second);
    }
}
