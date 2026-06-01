<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Mission\DecompositionQuality;

use App\Services\Ai\Mission\DecompositionQuality\ObjectivePairMutualExclusivityScorer;
use PHPUnit\Framework\TestCase;

final class ObjectivePairMutualExclusivityScorerTest extends TestCase
{
    private ObjectivePairMutualExclusivityScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new ObjectivePairMutualExclusivityScorer();
    }

    public function testReturnShapeMatchesSchema(): void
    {
        $result = $this->scorer->score(
            ['title' => 'Criar dashboard de vendas', 'description' => 'painel mensal'],
            ['title' => 'Gerar relatorio anual', 'description' => 'documento anual'],
        );

        $this->assertSame('atlas.aaeos.objective_pair_exclusivity.v1', $result['schema_version']);
        $this->assertArrayHasKey('exclusivity', $result);
        $this->assertArrayHasKey('classification', $result);
        $this->assertArrayHasKey('jaccard', $result);
        $this->assertArrayHasKey('shared_terms', $result);
        $this->assertArrayHasKey('overlap_count', $result);
        $this->assertIsFloat($result['exclusivity']);
        $this->assertIsFloat($result['jaccard']);
        $this->assertIsInt($result['overlap_count']);
    }

    public function testDisjointObjectivesScoreFullExclusivity(): void
    {
        $result = $this->scorer->score(
            ['title' => 'Criar endpoint de autenticacao', 'description' => ''],
            ['title' => 'Gerar relatorio de vendas', 'description' => ''],
        );

        $this->assertSame(0.0, $result['jaccard']);
        $this->assertSame(1.0, $result['exclusivity']);
        $this->assertSame('disjoint', $result['classification']);
        $this->assertSame([], $result['shared_terms']);
        $this->assertSame(0, $result['overlap_count']);
    }

    public function testIdenticalObjectivesAreDuplicate(): void
    {
        $objective = [
            'title' => 'Criar dashboard de vendas mensais',
            'description' => 'painel consolidado de metricas',
        ];

        $result = $this->scorer->score($objective, $objective);

        $this->assertSame(1.0, $result['jaccard']);
        $this->assertSame(0.0, $result['exclusivity']);
        $this->assertSame('duplicate', $result['classification']);
    }

    public function testOverlappingObjectivesShareDeliverableNounsButNotVerb(): void
    {
        $result = $this->scorer->score(
            ['title' => 'Criar dashboard de vendas mensais', 'description' => ''],
            ['title' => 'Criar dashboard de vendas anuais', 'description' => ''],
        );

        $this->assertSame(0.5, $result['jaccard']);
        $this->assertSame(0.5, $result['exclusivity']);
        $this->assertSame('overlapping', $result['classification']);
        $this->assertContains('dashboard', $result['shared_terms']);
        $this->assertContains('vendas', $result['shared_terms']);
        $this->assertNotContains('criar', $result['shared_terms']);
    }

    public function testJaccardAndSharedTermsAreSymmetricAndDeterministic(): void
    {
        $objectiveA = [
            'title' => 'Criar dashboard de vendas mensais',
            'description' => 'incluir metricas de receita',
        ];
        $objectiveB = [
            'title' => 'Criar painel de vendas anuais',
            'description' => 'incluir metricas de receita consolidada',
        ];

        $forward = $this->scorer->score($objectiveA, $objectiveB);
        $reverse = $this->scorer->score($objectiveB, $objectiveA);

        $this->assertSame($forward['jaccard'], $reverse['jaccard']);
        $this->assertSame($forward['shared_terms'], $reverse['shared_terms']);

        $sorted = $forward['shared_terms'];
        sort($sorted);
        $this->assertSame($sorted, $forward['shared_terms']);
        $this->assertSame($sorted, $reverse['shared_terms']);
    }

    public function testBandBoundaryBetweenMostlyDistinctAndOverlappingIsComputed(): void
    {
        // jaccard exactly 0.34 -> 17 shared / 50 union (16 A-only, 17 B-only).
        $mostlyDistinct = $this->scorer->score(
            $this->objectiveFromTerms(
                $this->terms('shared', 17),
                $this->terms('alpha', 16),
            ),
            $this->objectiveFromTerms(
                $this->terms('shared', 17),
                $this->terms('beta', 17),
            ),
        );

        $this->assertSame(0.34, $mostlyDistinct['jaccard']);
        $this->assertSame('mostly_distinct', $mostlyDistinct['classification']);

        // jaccard just above the 0.5 overlapping threshold -> 26 shared / 50 union.
        $justAboveThreshold = $this->scorer->score(
            $this->objectiveFromTerms(
                $this->terms('shared', 26),
                $this->terms('alpha', 12),
            ),
            $this->objectiveFromTerms(
                $this->terms('shared', 26),
                $this->terms('beta', 12),
            ),
        );

        $this->assertGreaterThan(0.5, $justAboveThreshold['jaccard']);
        $this->assertSame('overlapping', $justAboveThreshold['classification']);
        $this->assertNotSame($mostlyDistinct['classification'], $justAboveThreshold['classification']);
    }

    /**
     * @param list<string> $titleTerms
     * @param list<string> $descriptionTerms
     *
     * @return array{title: string, description: string}
     */
    private function objectiveFromTerms(array $titleTerms, array $descriptionTerms): array
    {
        return [
            'title' => implode(' ', $titleTerms),
            'description' => implode(' ', $descriptionTerms),
        ];
    }

    /**
     * Generates $count distinct content words (length >= 4, never a stopword).
     *
     * @return list<string>
     */
    private function terms(string $prefix, int $count): array
    {
        $terms = [];

        for ($index = 0; $index < $count; $index++) {
            $terms[] = $prefix.'word'.$index;
        }

        return $terms;
    }
}
