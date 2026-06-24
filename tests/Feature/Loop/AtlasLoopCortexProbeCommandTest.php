<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopCortexProbeCommand;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\AtlasCortexActiveSnapshot;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasLoopCortexProbeCommandTest extends TestCase
{
    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:loop:cortex:probe', $args);

        return [$exit, $kernel->output()];
    }

    public function test_flag_off_prints_exactly_disabled_line_and_exits_zero(): void
    {
        config()->set('atlas.cortex.active.enabled', false);

        [$exit, $out] = $this->runCmd([]);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopCortexProbeCommand::DISABLED_LINE."\n", $out);
    }

    public function test_flag_on_with_json_emits_valid_snapshot_payload(): void
    {
        config()->set('atlas.cortex.active.enabled', true);
        $this->app->instance(AtlasLoopCortexProbeCommand::INDEX_SOURCE_KEY, fn (): array => [
            'callers' => ['FixtureClass::doThing' => []],
            'callees' => ['FixtureClass::doThing' => []],
            'symbols' => ['FixtureClass::doThing' => ['file_line' => 'app/FixtureClass.php:10', 'role' => 'method']],
        ]);

        [$exit, $out] = $this->runCmd([
            '--target' => 'FixtureClass::doThing',
            '--edit' => 'remove',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode(trim($out), true);
        $this->assertIsArray($decoded);
        $this->assertSame(AtlasCortexActiveSnapshot::SCHEMA, $decoded['schema']);
        $this->assertArrayHasKey('passive', $decoded);
        $this->assertArrayHasKey('active', $decoded);
        foreach (['hypothetical_changes', 'counterfactuals', 'call_graph_projections', 'critical_paths', 'unknown_regions'] as $section) {
            $this->assertArrayHasKey($section, $decoded['active'], "missing section {$section}");
        }
    }

    public function test_flag_on_with_target_produces_non_empty_fact_payload(): void
    {
        config()->set('atlas.cortex.active.enabled', true);

        [$exit, $out] = $this->runCmd([
            '--target' => 'FixtureClass::doThing',
            '--edit' => 'remove',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode(trim($out), true);
        $this->assertNotEmpty($decoded['active']['hypothetical_changes']);
        $this->assertNotEmpty($decoded['active']['counterfactuals']);
    }

    public function test_output_carries_no_score_or_ranking_field_anywhere(): void
    {
        config()->set('atlas.cortex.active.enabled', true);

        [$exit, $out] = $this->runCmd([
            '--target' => 'X::y',
            '--edit' => 'rename',
            '--flows' => ['X::y'],
            '--json' => true,
        ]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode(trim($out), true);
        $forbidden = ['score', 'comprehension_score', 'rank', 'ranking', 'priority', 'recommendation'];
        $this->walk($decoded['active'], $forbidden);
        $this->assertTrue(true);
    }

    /**
     * @param  list<string>  $forbidden
     */
    private function walk(mixed $value, array $forbidden): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $k => $v) {
            if (is_string($k)) {
                $this->assertNotContains($k, $forbidden);
            }
            $this->walk($v, $forbidden);
        }
    }
}
