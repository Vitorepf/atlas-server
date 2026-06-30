<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\AtlasCortexCriticalPathDetector;
use Tests\TestCase;

final class AtlasCortexCriticalPathDetectorTest extends TestCase
{
    private AtlasCortexCriticalPathDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new AtlasCortexCriticalPathDetector;
    }

    /**
     * Minimal index where two entry symbols share one common callee (Validator)
     * and each also has its own unique callee.
     *
     * callees:
     *   Auth::login  → [Services::validate, Auth::log]
     *   Api::handle  → [Services::validate, Api::emit]
     */
    private function sharedIndex(): array
    {
        return [
            'callees' => [
                'Auth::login'         => ['Services::validate', 'Auth::log'],
                'Api::handle'         => ['Services::validate', 'Api::emit'],
                'Services::validate'  => [],
                'Auth::log'           => [],
                'Api::emit'           => [],
            ],
            'symbols' => [
                'Auth::login'        => ['file_line' => 'app/Http/Auth.php:10',          'role' => 'method'],
                'Api::handle'        => ['file_line' => 'app/Http/Api.php:5',            'role' => 'method'],
                'Services::validate' => ['file_line' => 'app/Services/Validator.php:20', 'role' => 'method'],
                'Auth::log'          => ['file_line' => 'app/Services/AuthLogger.php:8', 'role' => 'method'],
                'Api::emit'          => ['file_line' => 'app/Services/ApiEmitter.php:3', 'role' => 'method'],
            ],
        ];
    }

    // ── AC2: file reached by ≥k flows → CRITICAL_FILE with sorted flow_ids + shortest_distance

    public function test_ac2_shared_file_produces_critical_file_fact(): void
    {
        $result = $this->detector->detect(
            ['auth' => ['entry' => 'Auth::login'], 'api' => ['entry' => 'Api::handle']],
            $this->sharedIndex(),
            k: 2,
        );

        $criticalFacts = array_filter($result['facts'], fn ($f) => $f['fact'] === AtlasCortexCriticalPathDetector::FACT_CRITICAL);
        $criticalFacts = array_values($criticalFacts);

        $this->assertNotEmpty($criticalFacts);

        $validatorFact = null;
        foreach ($criticalFacts as $fact) {
            if ($fact['file_path'] === 'app/Services/Validator.php') {
                $validatorFact = $fact;
                break;
            }
        }

        $this->assertNotNull($validatorFact, 'CRITICAL_FILE fact for Validator.php must exist');
        $this->assertSame(2, $validatorFact['intersect_count']);
        $this->assertArrayHasKey('shortest_distance_per_flow', $validatorFact);
        $this->assertArrayHasKey('flow_ids_intersecting',      $validatorFact);
    }

    public function test_ac2_flow_ids_intersecting_are_sorted(): void
    {
        $result = $this->detector->detect(
            ['z-flow' => ['entry' => 'Auth::login'], 'a-flow' => ['entry' => 'Api::handle']],
            $this->sharedIndex(),
            k: 2,
        );

        $critical = array_filter($result['facts'], fn ($f) =>
            $f['fact'] === AtlasCortexCriticalPathDetector::FACT_CRITICAL
            && $f['file_path'] === 'app/Services/Validator.php'
        );
        $fact = array_values($critical)[0];

        $flows = $fact['flow_ids_intersecting'];
        $sorted = $flows;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $flows);
    }

    public function test_ac2_shortest_distance_per_flow_present_for_each_intersecting_flow(): void
    {
        $result = $this->detector->detect(
            ['auth' => ['entry' => 'Auth::login'], 'api' => ['entry' => 'Api::handle']],
            $this->sharedIndex(),
            k: 2,
        );

        $critical = array_filter($result['facts'], fn ($f) =>
            $f['fact'] === AtlasCortexCriticalPathDetector::FACT_CRITICAL
            && $f['file_path'] === 'app/Services/Validator.php'
        );
        $fact = array_values($critical)[0];

        foreach ($fact['flow_ids_intersecting'] as $flowId) {
            $this->assertArrayHasKey($flowId, $fact['shortest_distance_per_flow']);
        }
    }

    // ── AC3: files below k intersections are omitted (no scalar criticality score)

    public function test_ac3_file_reached_by_only_one_flow_is_omitted(): void
    {
        $result = $this->detector->detect(
            ['auth' => ['entry' => 'Auth::login'], 'api' => ['entry' => 'Api::handle']],
            $this->sharedIndex(),
            k: 2,
        );

        $criticalFiles = array_map(fn ($f) => $f['file_path'] ?? null,
            array_filter($result['facts'], fn ($f) => $f['fact'] === AtlasCortexCriticalPathDetector::FACT_CRITICAL)
        );

        $this->assertNotContains('app/Services/AuthLogger.php', $criticalFiles);
        $this->assertNotContains('app/Services/ApiEmitter.php', $criticalFiles);
    }

    public function test_ac3_critical_file_facts_carry_no_scalar_criticality_score(): void
    {
        $result = $this->detector->detect(
            ['auth' => ['entry' => 'Auth::login'], 'api' => ['entry' => 'Api::handle']],
            $this->sharedIndex(),
            k: 2,
        );

        foreach ($result['facts'] as $fact) {
            if ($fact['fact'] !== AtlasCortexCriticalPathDetector::FACT_CRITICAL) {
                continue;
            }
            $this->assertArrayNotHasKey('criticality_score', $fact);
            $this->assertArrayNotHasKey('rank',              $fact);
        }
    }

    // ── AC4: depth-truncated frontiers emit UNKNOWN_REGION facts

    public function test_ac4_truncated_frontier_produces_unknown_region_fact(): void
    {
        // Chain: Entry → Middle → Beyond. depth=1 visits Entry+Middle but truncates Beyond.
        $index = [
            'callees' => [
                'Entry::run'  => ['Middle::exec'],
                'Middle::exec' => ['Beyond::process'],
                'Beyond::process' => [],
            ],
            'symbols' => [
                'Entry::run'      => ['file_line' => 'app/Entry.php:1',  'role' => 'method'],
                'Middle::exec'    => ['file_line' => 'app/Middle.php:1', 'role' => 'method'],
                'Beyond::process' => ['file_line' => 'app/Beyond.php:1', 'role' => 'method'],
            ],
        ];

        $result = $this->detector->detect(
            ['deep' => ['entry' => 'Entry::run', 'depth' => 1]],
            $index,
            k: 1,
        );

        $unknownFacts = array_filter($result['facts'], fn ($f) => $f['fact'] === AtlasCortexCriticalPathDetector::FACT_UNKNOWN_REGION);
        $this->assertNotEmpty($unknownFacts, 'Truncated frontier must produce UNKNOWN_REGION facts');

        $hit = false;
        foreach ($unknownFacts as $uf) {
            if ($uf['at_symbol'] === 'Beyond::process') {
                $hit = true;
                break;
            }
        }
        $this->assertTrue($hit, 'UNKNOWN_REGION must name the truncated symbol Beyond::process');
    }

    public function test_ac4_same_input_produces_identical_output(): void
    {
        $flows = ['auth' => ['entry' => 'Auth::login'], 'api' => ['entry' => 'Api::handle']];
        $index = $this->sharedIndex();

        $this->assertSame(
            $this->detector->detect($flows, $index, k: 2),
            $this->detector->detect($flows, $index, k: 2),
        );
    }
}
