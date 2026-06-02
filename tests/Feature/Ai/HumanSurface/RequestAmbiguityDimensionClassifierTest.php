<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\HumanSurface;

use App\Services\Ai\HumanSurface\RequestAmbiguityDimensionClassifier;
use PHPUnit\Framework\TestCase;

final class RequestAmbiguityDimensionClassifierTest extends TestCase
{
    private RequestAmbiguityDimensionClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new RequestAmbiguityDimensionClassifier();
    }

    public function testObjectCueWithoutVerbIsMissingAction(): void
    {
        $result = $this->classifier->classify('o arquivo de rotas');

        $this->assertSame('missing_action', $result['dimension']);
        $this->assertSame(['o', 'arquivo'], $result['evidence']);
        $this->assertSame(0.7, $result['confidence']);
    }

    public function testLoneVerbWithoutObjectIsMissingTarget(): void
    {
        $result = $this->classifier->classify('implementa');

        $this->assertSame('missing_target', $result['dimension']);
        $this->assertSame(['implementa'], $result['evidence']);
        $this->assertSame(0.6, $result['confidence']);
    }

    public function testVerbsFromTwoFamiliesAreCompetingIntents(): void
    {
        $result = $this->classifier->classify('implementa e pesquisa o mercado');

        $this->assertSame('competing_intents', $result['dimension']);
        $this->assertSame(['implementa', 'pesquisa'], $result['evidence']);
        $this->assertSame(0.7, $result['confidence']);
    }

    public function testVerbWithUnboundedMarkerIsUnboundedScope(): void
    {
        $result = $this->classifier->classify('refatora tudo');

        $this->assertSame('unbounded_scope', $result['dimension']);
        $this->assertSame(['refatora', 'tudo'], $result['evidence']);
        $this->assertSame(0.7, $result['confidence']);
    }

    public function testVerbWithObjectAndPathTokenIsNone(): void
    {
        $result = $this->classifier->classify('corrige o teste em tests/X.php');

        $this->assertSame('none', $result['dimension']);
        $this->assertSame([], $result['evidence']);
        $this->assertSame(0.6, $result['confidence']);
    }

    public function testCoFiringResolvesByPriorityToMissingTargetOverCompeting(): void
    {
        // Two verb families fire competing_intents, while "no object cue" fires
        // missing_target. Priority (missing_target > competing_intents) decides.
        $result = $this->classifier->classify('implementa pesquisa');

        $this->assertSame('missing_target', $result['dimension']);
        $this->assertSame(['implementa', 'pesquisa'], $result['evidence']);
    }

    public function testMissingActionIsHighestPriorityOverUnboundedMarker(): void
    {
        // Object cue with no verb fires missing_action (top priority) even when
        // an unbounded marker co-occurs.
        $result = $this->classifier->classify('o arquivo tudo');

        $this->assertSame('missing_action', $result['dimension']);
        $this->assertSame(['o', 'arquivo'], $result['evidence']);
    }

    public function testVerbPlusObjectPlusTudoIsUnboundedScopeNotNone(): void
    {
        $withMarker = $this->classifier->classify('refatora o arquivo tudo');
        $withoutMarker = $this->classifier->classify('refatora o arquivo');

        $this->assertSame('unbounded_scope', $withMarker['dimension']);
        $this->assertSame(['refatora', 'tudo'], $withMarker['evidence']);

        // Removing the unbounded marker collapses the same request to none.
        $this->assertSame('none', $withoutMarker['dimension']);
    }

    public function testPluralWithoutPathTriggersUnboundedScope(): void
    {
        $result = $this->classifier->classify('implementa rotas');

        $this->assertSame('unbounded_scope', $result['dimension']);
        $this->assertSame(['implementa', 'rotas'], $result['evidence']);
        $this->assertSame(0.7, $result['confidence']);
    }

    public function testEvidenceIsNonEmptyForEveryNonNoneDimension(): void
    {
        $missingAction = $this->classifier->classify('o arquivo de rotas');
        $missingTarget = $this->classifier->classify('implementa');
        $competing = $this->classifier->classify('implementa e pesquisa o mercado');
        $unbounded = $this->classifier->classify('refatora tudo');

        $this->assertNotEmpty($missingAction['evidence']);
        $this->assertNotEmpty($missingTarget['evidence']);
        $this->assertNotEmpty($competing['evidence']);
        $this->assertNotEmpty($unbounded['evidence']);
    }

    public function testConfidenceIsStrictlyHigherWithTwoCuesThanSingleCue(): void
    {
        $singleCue = $this->classifier->classify('implementa');
        $twoCues = $this->classifier->classify('implementa e pesquisa o mercado');

        $this->assertSame(1, count($singleCue['evidence']));
        $this->assertSame(2, count($twoCues['evidence']));
        $this->assertGreaterThan($singleCue['confidence'], $twoCues['confidence']);
        $this->assertSame(0.6, $singleCue['confidence']);
        $this->assertSame(0.7, $twoCues['confidence']);
    }

    public function testConfidenceIsCappedAndComputedFromEvidenceStrength(): void
    {
        // Five object cues, no verb -> missing_action with five corroborating
        // cues: base 0.6 + 0.1 * 4 = 1.0, capped at 0.95.
        $result = $this->classifier->classify('o a em arquivo teste rota');

        $this->assertSame('missing_action', $result['dimension']);
        $this->assertSame(6, count($result['evidence']));
        $this->assertSame(0.95, $result['confidence']);
    }

    public function testClassificationIsDeterministic(): void
    {
        $first = $this->classifier->classify('implementa e pesquisa o mercado');
        $second = $this->classifier->classify('implementa e pesquisa o mercado');

        $this->assertSame($first, $second);
    }
}
