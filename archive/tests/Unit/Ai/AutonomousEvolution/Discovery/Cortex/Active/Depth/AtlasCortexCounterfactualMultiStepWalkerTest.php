<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery\Cortex\Active\Depth;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\Depth\AtlasCortexCounterfactualMultiStepWalker;
use Tests\TestCase;

final class AtlasCortexCounterfactualMultiStepWalkerTest extends TestCase
{
    private function snapshot(): array
    {
        return [
            'reachability' => [
                'app/Services/Foo.php' => ['app/Services/Bar.php', 'app/Services/Baz.php'],
                'app/Services/Bar.php' => ['app/Services/Qux.php'],
                'app/Services/Baz.php' => [],
                'app/Services/Qux.php' => [],
            ],
        ];
    }

    public function test_flag_off_returns_empty_walk_result(): void
    {
        $walker = new AtlasCortexCounterfactualMultiStepWalker(enabled: false);
        $result = $walker->walk($this->snapshot(), [
            ['site' => 'app/Services/Foo.php', 'mutation_kind' => 'tighten_signature'],
        ]);

        $this->assertSame([], $result['steps']);
        $this->assertSame('flag_off', $result['terminated_reason']);
    }

    public function test_walk_enumerates_steps_with_observable_fact_delta_and_no_score(): void
    {
        $walker = new AtlasCortexCounterfactualMultiStepWalker(enabled: true, depth: 3);
        $result = $walker->walk($this->snapshot(), [
            ['site' => 'app/Services/Foo.php', 'mutation_kind' => 'tighten_signature'],
            ['site' => 'app/Services/Bar.php', 'mutation_kind' => 'add_guard'],
            ['site' => 'app/Services/Qux.php', 'mutation_kind' => 'rename_method'],
        ]);

        $this->assertCount(3, $result['steps']);
        $json = (string) json_encode($result, JSON_UNESCAPED_SLASHES);
        foreach (['"score"', '"rank"', '"rating"', '"severity"', '"grade"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, "forbidden key {$forbidden} found");
        }
        $this->assertSame(0, $result['steps'][0]['step_index']);
        $this->assertArrayHasKey('observable_fact_delta', $result['steps'][0]);
        $this->assertArrayHasKey('affected_files', $result['steps'][0]['observable_fact_delta']);
    }

    public function test_forbidden_scope_terminates_walk_without_advancing_into_forbidden(): void
    {
        $walker = new AtlasCortexCounterfactualMultiStepWalker(enabled: true, depth: 3);
        $result = $walker->walk($this->snapshot(), [
            ['site' => 'app/Services/Foo.php', 'mutation_kind' => 'tighten_signature'],
            ['site' => 'app/Services/Ai/MarketingDomain/Bridge.php', 'mutation_kind' => 'add_guard'],
        ]);

        $this->assertSame('forbidden_scope', $result['terminated_reason']);
        $this->assertCount(1, $result['steps'], 'walk must stop at the first non-forbidden step');
    }

    public function test_unreachable_next_step_terminates_walk(): void
    {
        $walker = new AtlasCortexCounterfactualMultiStepWalker(enabled: true, depth: 3);
        $result = $walker->walk($this->snapshot(), [
            ['site' => 'app/Services/Foo.php', 'mutation_kind' => 'tighten_signature'],
            ['site' => 'app/Services/UnrelatedIsland.php', 'mutation_kind' => 'add_guard'],
        ]);

        $this->assertSame('next_step_not_reachable_from_prior', $result['terminated_reason']);
        $this->assertCount(1, $result['steps']);
    }

    public function test_depth_cap_is_honored(): void
    {
        $walker = new AtlasCortexCounterfactualMultiStepWalker(enabled: true, depth: 2);
        $result = $walker->walk($this->snapshot(), [
            ['site' => 'app/Services/Foo.php', 'mutation_kind' => 'tighten_signature'],
            ['site' => 'app/Services/Bar.php', 'mutation_kind' => 'add_guard'],
            ['site' => 'app/Services/Qux.php', 'mutation_kind' => 'rename_method'],
        ]);

        $this->assertCount(2, $result['steps']);
        $this->assertSame('depth_reached', $result['terminated_reason']);
    }

    public function test_self_link_does_not_inflate_projected_reachable_count(): void
    {
        $snapshot = [
            'reachability' => [
                'app/Services/Foo.php' => ['app/Services/Foo.php', 'app/Services/Bar.php'],
                'app/Services/Bar.php' => [],
            ],
        ];
        $walker = new AtlasCortexCounterfactualMultiStepWalker(enabled: true, depth: 1);
        $result = $walker->walk($snapshot, [
            ['site' => 'app/Services/Foo.php', 'mutation_kind' => 'tighten_signature'],
        ]);

        $delta = $result['steps'][0]['observable_fact_delta'];
        $this->assertSame(
            count($delta['affected_files']),
            $delta['projected_reachable_count'],
            'projected_reachable_count must equal the distinct affected_files count',
        );
        $this->assertCount(2, $delta['affected_files']);
    }

    public function test_hard_cap_is_eight(): void
    {
        $walker = new AtlasCortexCounterfactualMultiStepWalker(enabled: true, depth: 99);
        $reachable = [];
        $hypotheses = [];
        $hypotheses[] = ['site' => 'app/A.php', 'mutation_kind' => 'x'];
        for ($i = 1; $i < 12; $i++) {
            $reachable[sprintf('app/A.php')][] = sprintf('app/B%d.php', $i);
            $hypotheses[] = ['site' => sprintf('app/B%d.php', $i), 'mutation_kind' => 'x'];
        }
        $result = $walker->walk(['reachability' => $reachable], $hypotheses);

        $this->assertLessThanOrEqual(AtlasCortexCounterfactualMultiStepWalker::HARD_CAP_DEPTH, count($result['steps']));
    }
}
