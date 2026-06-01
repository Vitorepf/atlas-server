<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Mission;

use App\Services\Ai\Mission\ObjectiveDependencyEdgeInferer;
use PHPUnit\Framework\TestCase;

final class ObjectiveDependencyEdgeInfererTest extends TestCase
{
    private ObjectiveDependencyEdgeInferer $inferer;

    protected function setUp(): void
    {
        $this->inferer = new ObjectiveDependencyEdgeInferer();
    }

    public function testReturnShapeCarriesSchemaVersion(): void
    {
        $result = $this->inferer->inferEdges([]);

        $this->assertSame('atlas.aaeos.objective_dependency_edges.v1', $result['schema_version']);
        $this->assertSame([], $result['edges']);
    }

    public function testProducerBeforeConsumerEmitsSingleForwardEdgeWithNoReverse(): void
    {
        $result = $this->inferer->inferEdges([
            ['id' => 'A', 'title' => 'Criar API de pagamento'],
            ['id' => 'B', 'title' => 'Integrar API de pagamento no checkout'],
        ]);

        $this->assertSame(
            [
                [
                    'from' => 'A',
                    'to' => 'B',
                    'artifact' => 'api',
                    'rule' => 'criar->integrar',
                ],
            ],
            $result['edges'],
        );

        // Explicitly prove no reverse B -> A edge slipped in.
        foreach ($result['edges'] as $edge) {
            $this->assertFalse($edge['from'] === 'B' && $edge['to'] === 'A');
        }
    }

    public function testProducerIsResolvedRegardlessOfArrayOrder(): void
    {
        // Consumer (A) listed before producer (B); edge must still point B -> A.
        $result = $this->inferer->inferEdges([
            ['id' => 'A', 'title' => 'Testar o relatorio'],
            ['id' => 'B', 'title' => 'Gerar relatorio mensal'],
        ]);

        $this->assertCount(1, $result['edges']);

        $edge = $result['edges'][0];
        $this->assertSame('B', $edge['from']);
        $this->assertSame('A', $edge['to']);
        $this->assertSame('relatorio', $edge['artifact']);
        $this->assertSame('gerar->testar', $edge['rule']);
    }

    public function testTwoConsumersWithoutProducerYieldZeroEdges(): void
    {
        $result = $this->inferer->inferEdges([
            ['id' => 'A', 'title' => 'usar dashboard'],
            ['id' => 'B', 'title' => 'testar dashboard'],
        ]);

        $this->assertSame([], $result['edges']);
    }

    public function testObjectiveCarryingBothRolesProducesNoSelfEdge(): void
    {
        $result = $this->inferer->inferEdges([
            ['id' => 'SELF', 'title' => 'Criar e testar o relatorio mensal'],
        ]);

        $this->assertSame([], $result['edges']);

        foreach ($result['edges'] as $edge) {
            $this->assertNotSame($edge['from'], $edge['to']);
        }
    }

    public function testProducerNounConsumedTwiceEmitsTwoSortedEdgesAndSynonymsDedupe(): void
    {
        $result = $this->inferer->inferEdges([
            ['id' => 'obj-y', 'title' => 'Integrar e usar relatorio base'],
            ['id' => 'obj-p', 'title' => 'Criar relatorio base'],
            ['id' => 'obj-x', 'title' => 'Usar relatorio base'],
        ]);

        // Two distinct consumers => exactly two edges, both rooted at producer.
        $this->assertCount(2, $result['edges']);

        $this->assertSame('obj-p', $result['edges'][0]['from']);
        $this->assertSame('obj-p', $result['edges'][1]['from']);

        // Sorted by 'to': obj-x before obj-y.
        $this->assertSame('obj-x', $result['edges'][0]['to']);
        $this->assertSame('obj-y', $result['edges'][1]['to']);

        $this->assertSame('relatorio', $result['edges'][0]['artifact']);
        $this->assertSame('relatorio', $result['edges'][1]['artifact']);

        // obj-x consumes via 'usar' only.
        $this->assertSame('criar->usar', $result['edges'][0]['rule']);

        // obj-y carries two consumer verbs (integrar + usar) for the same noun;
        // the identical (from,to,artifact) collapses to a single deduped edge
        // keeping the lexicographically smallest rule.
        $this->assertSame('criar->integrar', $result['edges'][1]['rule']);
    }

    public function testRuleTableHoldsExactlySevenRules(): void
    {
        $result = $this->inferer->inferEdges([
            ['id' => 'p', 'title' => 'Criar gerar usar integrar testar consumir relatorio'],
        ]);

        // Single objective -> no self-edges regardless of how many verbs it has.
        $this->assertSame([], $result['edges']);

        // The seven curated rules each fire exactly once when wired across a
        // producer and a matching consumer, proving the table has seven entries.
        $rules = [];
        foreach ($this->ruleProbeObjectives() as $pair) {
            $edges = $this->inferer->inferEdges($pair)['edges'];
            $this->assertCount(1, $edges);
            $rules[] = $edges[0]['rule'];
        }

        $expected = [
            'criar->usar',
            'criar->integrar',
            'criar->testar',
            'criar->consumir',
            'gerar->usar',
            'gerar->integrar',
            'gerar->testar',
        ];

        $this->assertSame($expected, $rules);
        $this->assertCount(7, $rules);
    }

    public function testResultIsDeterministicAcrossRepeatedCalls(): void
    {
        $objectives = [
            ['id' => 'obj-y', 'title' => 'Integrar e usar relatorio base'],
            ['id' => 'obj-p', 'title' => 'Criar relatorio base'],
            ['id' => 'obj-x', 'title' => 'Usar relatorio base'],
        ];

        $first = $this->inferer->inferEdges($objectives);
        $second = $this->inferer->inferEdges($objectives);

        $this->assertSame($first, $second);
    }

    /**
     * One producer/consumer objective pair per curated rule, in rule order.
     *
     * @return list<list<array{id: string, title: string}>>
     */
    private function ruleProbeObjectives(): array
    {
        return [
            [
                ['id' => 'p', 'title' => 'Criar relatorio base'],
                ['id' => 'c', 'title' => 'Usar relatorio base'],
            ],
            [
                ['id' => 'p', 'title' => 'Criar relatorio base'],
                ['id' => 'c', 'title' => 'Integrar relatorio base'],
            ],
            [
                ['id' => 'p', 'title' => 'Criar relatorio base'],
                ['id' => 'c', 'title' => 'Testar relatorio base'],
            ],
            [
                ['id' => 'p', 'title' => 'Criar relatorio base'],
                ['id' => 'c', 'title' => 'Consumir relatorio base'],
            ],
            [
                ['id' => 'p', 'title' => 'Gerar relatorio base'],
                ['id' => 'c', 'title' => 'Usar relatorio base'],
            ],
            [
                ['id' => 'p', 'title' => 'Gerar relatorio base'],
                ['id' => 'c', 'title' => 'Integrar relatorio base'],
            ],
            [
                ['id' => 'p', 'title' => 'Gerar relatorio base'],
                ['id' => 'c', 'title' => 'Testar relatorio base'],
            ],
        ];
    }
}
