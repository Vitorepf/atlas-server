<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\Active;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\AtlasCortexCallGraphProjector;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\AtlasCortexCriticalPathDetector;
use Tests\TestCase;

final class AtlasCortexCriticalPathDetectorTest extends TestCase
{
    /**
     * Three callee closures, designed to intersect on these files:
     *   - shared/Common.php : ALL 3 flows (intersect_count=3)
     *   - shared/AB.php     : flowA + flowB only (intersect_count=2)
     *   - shared/BC.php     : flowB + flowC only (intersect_count=2)
     *   - flow-private file appears in ONE closure only (intersect_count=1, filtered at K=2)
     */
    private function fixture(): array
    {
        return [
            'callees' => [
                // flow entries
                'flowA' => ['A_private', 'CommonSym', 'AB_Sym'],
                'flowB' => ['CommonSym', 'AB_Sym', 'BC_Sym'],
                'flowC' => ['C_private', 'CommonSym', 'BC_Sym'],
                'CommonSym' => [],
                'AB_Sym' => [],
                'BC_Sym' => [],
                'A_private' => [],
                'C_private' => [],
            ],
            'symbols' => [
                'flowA' => ['file_line' => 'app/FlowA.php:1', 'role' => 'method'],
                'flowB' => ['file_line' => 'app/FlowB.php:1', 'role' => 'method'],
                'flowC' => ['file_line' => 'app/FlowC.php:1', 'role' => 'method'],
                'CommonSym' => ['file_line' => 'shared/Common.php:10', 'role' => 'method'],
                'AB_Sym' => ['file_line' => 'shared/AB.php:20', 'role' => 'method'],
                'BC_Sym' => ['file_line' => 'shared/BC.php:30', 'role' => 'method'],
                'A_private' => ['file_line' => 'app/APrivate.php:5', 'role' => 'method'],
                'C_private' => ['file_line' => 'app/CPrivate.php:5', 'role' => 'method'],
            ],
        ];
    }

    private function detector(): AtlasCortexCriticalPathDetector
    {
        return new AtlasCortexCriticalPathDetector(new AtlasCortexCallGraphProjector(8));
    }

    public function test_k2_returns_exactly_3_facts_with_correct_intersect_and_flow_ids(): void
    {
        $flows = [
            'flowA' => ['entry' => 'flowA', 'depth' => 5, 'reverse' => false],
            'flowB' => ['entry' => 'flowB', 'depth' => 5, 'reverse' => false],
            'flowC' => ['entry' => 'flowC', 'depth' => 5, 'reverse' => false],
        ];

        $out = $this->detector()->detect($flows, $this->fixture(), 2);

        $critical = array_values(array_filter(
            $out['facts'],
            static fn (array $f): bool => $f['fact'] === AtlasCortexCriticalPathDetector::FACT_CRITICAL,
        ));
        $this->assertCount(3, $critical, 'exactly 3 critical files at K=2');

        $by = [];
        foreach ($critical as $f) {
            $by[$f['file_path']] = $f;
        }

        $this->assertSame(3, $by['shared/Common.php']['intersect_count']);
        $this->assertSame(['flowA', 'flowB', 'flowC'], $by['shared/Common.php']['flow_ids_intersecting']);

        $this->assertSame(2, $by['shared/AB.php']['intersect_count']);
        $this->assertSame(['flowA', 'flowB'], $by['shared/AB.php']['flow_ids_intersecting']);

        $this->assertSame(2, $by['shared/BC.php']['intersect_count']);
        $this->assertSame(['flowB', 'flowC'], $by['shared/BC.php']['flow_ids_intersecting']);

        // shortest_distance_per_flow is set, non-empty, and only carries the intersecting flows.
        foreach ($critical as $f) {
            $this->assertSame($f['flow_ids_intersecting'], array_keys($f['shortest_distance_per_flow']));
        }
    }

    public function test_output_has_no_scalar_criticality_score_or_ranking_field(): void
    {
        $flows = ['flowA' => ['entry' => 'flowA', 'depth' => 5, 'reverse' => false]];

        $out = $this->detector()->detect($flows, $this->fixture(), 1);

        $this->assertSame(AtlasCortexCriticalPathDetector::SCHEMA, $out['schema']);
        foreach ($out['facts'] as $fact) {
            // Strict field whitelist — no 'score', no 'rank', no 'priority' etc.
            $forbidden = ['score', 'criticality', 'criticality_score', 'rank', 'ranking', 'priority', 'weight'];
            foreach ($forbidden as $bad) {
                $this->assertArrayNotHasKey($bad, $fact, "fact must NOT carry scalar field '{$bad}'");
            }
            $this->assertContains($fact['fact'], [
                AtlasCortexCriticalPathDetector::FACT_CRITICAL,
                AtlasCortexCriticalPathDetector::FACT_UNKNOWN_REGION,
            ]);
        }
    }

    public function test_unknown_region_facts_are_inherited_from_projector_truncation(): void
    {
        // Force a deep chain past the cap on flowB so the projector emits a truncation frontier.
        $index = $this->fixture();
        $index['callees']['BC_Sym'] = ['Beyond1'];
        $index['callees']['Beyond1'] = ['Beyond2'];
        $index['symbols']['Beyond1'] = ['file_line' => 'shared/Beyond1.php:1', 'role' => 'method'];
        $index['symbols']['Beyond2'] = ['file_line' => 'shared/Beyond2.php:1', 'role' => 'method'];

        $flows = ['flowB' => ['entry' => 'flowB', 'depth' => 2, 'reverse' => false]];
        $detector = new AtlasCortexCriticalPathDetector(new AtlasCortexCallGraphProjector(2));

        $out = $detector->detect($flows, $index, 1);
        $unknown = array_values(array_filter(
            $out['facts'],
            static fn (array $f): bool => $f['fact'] === AtlasCortexCriticalPathDetector::FACT_UNKNOWN_REGION,
        ));
        $this->assertNotEmpty($unknown, 'truncation frontier must surface as UNKNOWN_REGION fact');
        $this->assertSame('Beyond2', $unknown[0]['at_symbol']);
        $this->assertSame('flowB', $unknown[0]['flow_id']);
    }
}
