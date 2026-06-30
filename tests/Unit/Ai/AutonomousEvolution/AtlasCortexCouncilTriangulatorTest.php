<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\AtlasCortexCouncilTriangulator;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\CouncilReport;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\LensObservation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves the council triangulator: agreements (≥2 lens ids assert the same canonical fact signature),
 * disagreements (each lens-emitted disagreement_signal is a first-class fact + cross-lens kind+target
 * conflicts), zero scoring/verdict/winner fields on CouncilReport (reflection), and verbatim raw-facts
 * preservation per lens id.
 */
final class AtlasCortexCouncilTriangulatorTest extends TestCase
{
    private function triangulator(): AtlasCortexCouncilTriangulator
    {
        return new AtlasCortexCouncilTriangulator;
    }

    private function obs(string $lensId, array $facts, array $disagreements = []): LensObservation
    {
        return new LensObservation($lensId, 'subj-1', ['facts' => $facts], $disagreements);
    }

    public function test_agreements_appear_when_same_signature_asserted_by_two_lenses(): void
    {
        $shared = ['kind' => 'method_unused', 'method' => 'Sample::doStuff'];

        $report = $this->triangulator()->triangulate([
            $this->obs('callgraph', [$shared, ['kind' => 'call_edge', 'from' => 'a', 'to' => 'b']]),
            $this->obs('testcoverage', [$shared, ['kind' => 'covered_method', 'method' => 'Other::go']]),
        ]);

        $this->assertCount(1, $report->agreements, 'one shared signature ⇒ one agreement');
        $this->assertSame(['callgraph', 'testcoverage'], $report->agreements[0]['supporting_lens_ids']);
        $this->assertSame($shared, $report->agreements[0]['fact']);
    }

    public function test_disagreement_signal_from_a_single_lens_is_first_class_fact_in_report(): void
    {
        $report = $this->triangulator()->triangulate([
            $this->obs('dataflow', [['kind' => 'method_data_flow', 'method' => 'x', 'write_set' => ['a']]], ['dynamic_property_access_in:x']),
        ]);

        $this->assertCount(1, $report->disagreements);
        $this->assertSame('lens_disagreement_signal', $report->disagreements[0]['kind']);
        $this->assertSame('dynamic_property_access_in:x', $report->disagreements[0]['signal']);
        $this->assertSame('dataflow', $report->disagreements[0]['lens_id']);
    }

    public function test_cross_lens_conflict_appears_when_two_lenses_assert_conflicting_discriminators(): void
    {
        $report = $this->triangulator()->triangulate([
            $this->obs('testcoverage', [['kind' => 'covered_method', 'method' => 'X::go', 'count' => 7]]),
            $this->obs('testcoverage_v2', [['kind' => 'covered_method', 'method' => 'X::go', 'count' => 0]]),
        ]);

        $crossConflicts = array_values(array_filter($report->disagreements, static fn (array $d): bool => ($d['kind'] ?? '') === 'cross_lens_fact_conflict'));
        $this->assertNotEmpty($crossConflicts, 'differing discriminator on same kind+target ⇒ cross-lens conflict');
        $this->assertStringContainsString('covered_method@X::go', (string) $crossConflicts[0]['signal']);
    }

    public function test_single_lens_two_discriminators_does_not_fabricate_cross_lens_conflict(): void
    {
        $report = $this->triangulator()->triangulate([
            $this->obs('testcoverage', [
                ['kind' => 'covered_method', 'method' => 'X::go', 'count' => 7],
                ['kind' => 'covered_method', 'method' => 'X::go', 'count' => 0],
            ]),
        ]);

        $crossConflicts = array_values(array_filter($report->disagreements, static fn (array $d): bool => ($d['kind'] ?? '') === 'cross_lens_fact_conflict'));
        $this->assertEmpty($crossConflicts, 'a single lens with differing discriminators must not produce a cross_lens_fact_conflict');
    }

    public function test_raw_facts_are_preserved_verbatim_keyed_by_lens_id(): void
    {
        $a = [['kind' => 'fact_a', 'x' => 1]];
        $b = [['kind' => 'fact_b', 'y' => 2]];
        $report = $this->triangulator()->triangulate([
            $this->obs('lens_a', $a),
            $this->obs('lens_b', $b),
        ]);

        $this->assertSame($a, $report->rawFactsByLens['lens_a']);
        $this->assertSame($b, $report->rawFactsByLens['lens_b']);
        $this->assertSame(['lens_a', 'lens_b'], $report->participatingLensIds);
    }

    public function test_council_report_carries_no_scoring_or_verdict_field(): void
    {
        $reflection = new ReflectionClass(CouncilReport::class);
        foreach ($reflection->getProperties() as $p) {
            $this->assertDoesNotMatchRegularExpression(
                '/score|verdict|rank|winner/i',
                $p->getName(),
                'CouncilReport must not carry scoring property: '.$p->getName(),
            );
        }
        // Also confirm the to-array shape carries no such key.
        $report = $this->triangulator()->triangulate([$this->obs('lens_a', [['kind' => 'k']])]);
        foreach (array_keys($report->toArray()) as $key) {
            $this->assertDoesNotMatchRegularExpression('/score|verdict|rank|winner/i', (string) $key);
        }
    }

    public function test_empty_observation_list_yields_empty_report(): void
    {
        $report = $this->triangulator()->triangulate([]);

        $this->assertSame('', $report->subjectId);
        $this->assertSame([], $report->agreements);
        $this->assertSame([], $report->disagreements);
        $this->assertSame([], $report->participatingLensIds);
    }
}
