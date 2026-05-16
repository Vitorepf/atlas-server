<?php

declare(strict_types=1);

namespace Tests\Unit\Planning;

use PHPUnit\Framework\TestCase;

final class MigrationPlanTest extends TestCase
{
    private const PLAN_PATH = __DIR__.'/../../../docs/planning/migration/feature.migration.md';

    private const RISKS_PATH = __DIR__.'/../../../docs/planning/migration/risks.md';

    public function test_plan_exists(): void
    {
        $this->assertFileExists(self::PLAN_PATH);
    }

    public function test_phase_count_in_range(): void
    {
        $phases = $this->parse((string) file_get_contents(self::PLAN_PATH));
        $this->assertGreaterThanOrEqual(3, count($phases));
        $this->assertLessThanOrEqual(5, count($phases));
    }

    public function test_each_phase_has_required_blocks(): void
    {
        foreach ($this->parse((string) file_get_contents(self::PLAN_PATH)) as $phase) {
            $this->assertNotEmpty($phase['preconditions'], "phase {$phase['id']} missing preconditions");
            $this->assertNotEmpty($phase['rollback'], "phase {$phase['id']} missing rollback");
            $this->assertNotSame('revert', strtolower(implode(' ', $phase['rollback'])));
            $this->assertNotSame('', $phase['decision_gate']['metric'], "phase {$phase['id']} decision_gate.metric missing");
            $this->assertNotSame('', $phase['decision_gate']['threshold'], "phase {$phase['id']} decision_gate.threshold missing");
            $this->assertNotSame('', $phase['decision_gate']['owner'], "phase {$phase['id']} decision_gate.owner missing");
            $this->assertNotEmpty($phase['risk_links'], "phase {$phase['id']} risk_links empty");
        }
    }

    public function test_phase_dependency_graph_acyclic(): void
    {
        $graph = [];
        foreach ($this->parse((string) file_get_contents(self::PLAN_PATH)) as $phase) {
            $graph[$phase['id']] = $phase['depends_on'];
        }
        $this->assertFalse($this->hasCycle($graph));
    }

    public function test_every_risk_is_addressed_by_at_least_one_phase(): void
    {
        $riskIds = $this->extractRiskIds((string) file_get_contents(self::RISKS_PATH));
        $covered = [];
        foreach ($this->parse((string) file_get_contents(self::PLAN_PATH)) as $phase) {
            foreach ($phase['risk_links'] as $rid) {
                $covered[$rid] = true;
            }
        }
        $missing = array_values(array_diff($riskIds, array_keys($covered)));
        $this->assertSame([], $missing, 'risks not addressed: '.implode(',', $missing));
    }

    /** @return list<array{id:string,depends_on:list<string>,preconditions:list<string>,rollback:list<string>,decision_gate:array{metric:string,threshold:string,owner:string},risk_links:list<string>}> */
    private function parse(string $md): array
    {
        $phases = [];
        $current = null;
        $section = '';
        foreach (preg_split('/\R/', $md) ?: [] as $line) {
            if (preg_match('/^##\s+(phase-\S+)/', $line, $m)) {
                if ($current !== null) {
                    $phases[] = $current;
                }
                $current = [
                    'id' => $m[1],
                    'depends_on' => [],
                    'preconditions' => [],
                    'rollback' => [],
                    'decision_gate' => ['metric' => '', 'threshold' => '', 'owner' => ''],
                    'risk_links' => [],
                ];
                $section = '';

                continue;
            }
            if ($current === null) {
                continue;
            }
            if (preg_match('/^###\s+(preconditions|rollback|decision_gate|risk_links|depends_on)/i', $line, $m)) {
                $section = strtolower($m[1]);

                continue;
            }
            if (preg_match('/^- (.+)$/', $line, $m)) {
                $entry = trim($m[1]);
                if ($section === 'depends_on') {
                    $current['depends_on'][] = $entry;
                } elseif ($section === 'preconditions') {
                    $current['preconditions'][] = $entry;
                } elseif ($section === 'rollback') {
                    $current['rollback'][] = $entry;
                } elseif ($section === 'risk_links') {
                    $current['risk_links'][] = $entry;
                } elseif ($section === 'decision_gate' && preg_match('/^(metric|threshold|owner):\s*(.+)$/i', $entry, $mm)) {
                    $current['decision_gate'][strtolower($mm[1])] = trim($mm[2]);
                }
            }
        }
        if ($current !== null) {
            $phases[] = $current;
        }

        return $phases;
    }

    /** @return list<string> */
    private function extractRiskIds(string $md): array
    {
        $ids = [];
        foreach (preg_split('/\R/', $md) ?: [] as $line) {
            if (preg_match('/^-\s*(risk-\S+):/', $line, $m)) {
                $ids[] = $m[1];
            }
        }

        return $ids;
    }

    /** @param array<string,list<string>> $graph */
    private function hasCycle(array $graph): bool
    {
        $state = [];
        $dfs = function (string $node) use (&$dfs, $graph, &$state): bool {
            $state[$node] = 1;
            foreach ($graph[$node] ?? [] as $next) {
                if (! isset($state[$next])) {
                    if ($dfs($next)) {
                        return true;
                    }
                } elseif ($state[$next] === 1) {
                    return true;
                }
            }
            $state[$node] = 2;

            return false;
        };
        foreach (array_keys($graph) as $node) {
            if (! isset($state[$node]) && $dfs($node)) {
                return true;
            }
        }

        return false;
    }
}
