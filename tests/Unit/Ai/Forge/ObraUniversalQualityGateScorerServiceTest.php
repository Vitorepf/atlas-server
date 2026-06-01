<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Forge;

use App\Services\Ai\Forge\ObraUniversalQualityGateScorerService;
use PHPUnit\Framework\TestCase;

final class ObraUniversalQualityGateScorerServiceTest extends TestCase
{
    private ObraUniversalQualityGateScorerService $scorer;

    protected function setUp(): void
    {
        $this->scorer = new ObraUniversalQualityGateScorerService();
    }

    public function testReturnShapeMatchesSchemaWithSchemaVersion(): void
    {
        $result = $this->scorer->score(['objective' => 'ship it']);

        $this->assertSame('atlas.obra.universal_gates.v1', $result['schema_version']);
        $this->assertSame(12, $result['gates_total']);
        $this->assertCount(12, $result['gates']);
    }

    public function testRuleOneOnlyObjectivePopulatedPassesFirstGateOnly(): void
    {
        $result = $this->scorer->score(['objective' => 'x']);

        $this->assertSame(1, $result['gates_passed']);
        $this->assertTrue($result['gates'][0]['passed']);
        $this->assertSame(2, $result['first_unmet_gate']);
        $this->assertSame('definition_of_done', $result['first_unmet_key']);
        $this->assertTrue($result['has_objective']);
        $this->assertFalse($result['all_passed']);
    }

    public function testRuleTwoEveryFieldPopulatedPassesAllGates(): void
    {
        $result = $this->scorer->score($this->fullyPopulatedObra());

        $this->assertSame(12, $result['gates_passed']);
        $this->assertTrue($result['all_passed']);
        $this->assertNull($result['first_unmet_gate']);
        $this->assertNull($result['first_unmet_key']);
        $this->assertTrue($result['has_objective']);
    }

    public function testRuleThreeEmptyArrayOrEmptyStringCountsAsNotPassedWhileNonEmptyArrayPasses(): void
    {
        $emptyStructure = $this->fullyPopulatedObra();
        $emptyStructure['structure'] = [];

        $emptyResult = $this->scorer->score($emptyStructure);

        // gate 3 maps to structure; empty array => NOT passed.
        $this->assertSame(3, $emptyResult['gates'][2]['gate']);
        $this->assertSame('structure', $emptyResult['gates'][2]['key']);
        $this->assertFalse($emptyResult['gates'][2]['passed']);
        $this->assertSame(11, $emptyResult['gates_passed']);
        $this->assertSame(3, $emptyResult['first_unmet_gate']);

        // empty string also counts as NOT passed.
        $emptyStringStructure = $this->fullyPopulatedObra();
        $emptyStringStructure['structure'] = '';
        $emptyStringResult = $this->scorer->score($emptyStringStructure);
        $this->assertFalse($emptyStringResult['gates'][2]['passed']);

        // a non-empty array passes.
        $nonEmptyStructure = $this->fullyPopulatedObra();
        $nonEmptyStructure['structure'] = ['module_a', 'module_b'];
        $nonEmptyResult = $this->scorer->score($nonEmptyStructure);
        $this->assertTrue($nonEmptyResult['gates'][2]['passed']);
        $this->assertSame(12, $nonEmptyResult['gates_passed']);
    }

    public function testRuleFourWhitespaceOnlyObjectiveFailsGateOneAndHasObjective(): void
    {
        $result = $this->scorer->score(['objective' => ' ']);

        $this->assertFalse($result['gates'][0]['passed']);
        $this->assertFalse($result['has_objective']);
        $this->assertSame(0, $result['gates_passed']);
        $this->assertSame(1, $result['first_unmet_gate']);
        $this->assertSame('objective', $result['first_unmet_key']);
    }

    public function testRuleFiveGateOrderingAndFirstUnmetIsLowestFailingGate(): void
    {
        $result = $this->scorer->score($this->fullyPopulatedObra());

        // gates[0] => gate 1 => objective; gates[11] => gate 12 => parent_objective.
        $this->assertSame(1, $result['gates'][0]['gate']);
        $this->assertSame('objective', $result['gates'][0]['key']);
        $this->assertSame(12, $result['gates'][11]['gate']);
        $this->assertSame('parent_objective', $result['gates'][11]['key']);

        // Fail gates 5 and 9 simultaneously: first_unmet_gate is the lowest (5).
        $multiUnmet = $this->fullyPopulatedObra();
        unset($multiUnmet['decisions']);       // gate 5
        $multiUnmet['current_version'] = '';    // gate 9
        $multiResult = $this->scorer->score($multiUnmet);

        $this->assertSame(5, $multiResult['first_unmet_gate']);
        $this->assertSame('decisions', $multiResult['first_unmet_key']);
        $this->assertFalse($multiResult['gates'][4]['passed']);
        $this->assertFalse($multiResult['gates'][8]['passed']);
        $this->assertSame(10, $multiResult['gates_passed']);
    }

    public function testGeneralisesToArbitraryMissingMiddleGate(): void
    {
        // Input NOT in any enumerated rule: only gate 7 (risks) is missing.
        $obra = $this->fullyPopulatedObra();
        unset($obra['risks']);

        $result = $this->scorer->score($obra);

        $this->assertSame(11, $result['gates_passed']);
        $this->assertFalse($result['all_passed']);
        $this->assertSame(7, $result['first_unmet_gate']);
        $this->assertSame('risks', $result['first_unmet_key']);
        $this->assertSame(7, $result['gates'][6]['gate']);
        $this->assertFalse($result['gates'][6]['passed']);
        // every other gate passed.
        $this->assertTrue($result['gates'][5]['passed']);
        $this->assertTrue($result['gates'][7]['passed']);
    }

    public function testEmptyObraFailsAllTwelveGates(): void
    {
        $result = $this->scorer->score([]);

        $this->assertSame(0, $result['gates_passed']);
        $this->assertFalse($result['all_passed']);
        $this->assertFalse($result['has_objective']);
        $this->assertSame(1, $result['first_unmet_gate']);
        $this->assertSame('objective', $result['first_unmet_key']);

        foreach ($result['gates'] as $gate) {
            $this->assertFalse($gate['passed']);
        }
    }

    public function testNullValueAndScalarZeroSemantics(): void
    {
        // null => NOT passed; a non-empty array elsewhere keeps gates distinct.
        $nullObjective = $this->fullyPopulatedObra();
        $nullObjective['objective'] = null;
        $nullResult = $this->scorer->score($nullObjective);
        $this->assertFalse($nullResult['gates'][0]['passed']);
        $this->assertFalse($nullResult['has_objective']);

        // integer 0 is present and non-empty (not one of the empty categories).
        $zeroVersion = $this->fullyPopulatedObra();
        $zeroVersion['current_version'] = 0;
        $zeroResult = $this->scorer->score($zeroVersion);
        $this->assertTrue($zeroResult['gates'][8]['passed']);
        $this->assertSame(12, $zeroResult['gates_passed']);
    }

    public function testDeterministicForIdenticalInput(): void
    {
        $obra = $this->fullyPopulatedObra();

        $first = $this->scorer->score($obra);
        $second = $this->scorer->score($obra);

        $this->assertSame($first, $second);
    }

    /**
     * Every one of the 12 universal gate fields populated with a non-empty value.
     *
     * @return array<string, mixed>
     */
    private function fullyPopulatedObra(): array
    {
        return [
            'objective' => 'deliver the universal quality gate scorer',
            'definition_of_done' => 'all gates evaluated and asserted',
            'structure' => ['service', 'test'],
            'next_step' => 'wire into runtime',
            'decisions' => ['use present/non-empty predicate'],
            'evidence_refs' => ['ledger:abc123'],
            'risks' => ['scope creep'],
            'tradeoffs' => ['strictness vs leniency'],
            'current_version' => 'v1',
            'output_intent' => 'runnable artifact',
            'learning' => 'gates must be non-empty, not key-existence',
            'parent_objective' => 'forge dev runtime maturity',
        ];
    }
}
