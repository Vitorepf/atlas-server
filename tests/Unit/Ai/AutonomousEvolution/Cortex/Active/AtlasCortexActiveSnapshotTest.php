<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\Active;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\AtlasCortexActiveSnapshot;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\CortexPassiveImmutabilityViolation;
use Tests\TestCase;

final class AtlasCortexActiveSnapshotTest extends TestCase
{
    private function passive(): array
    {
        return [
            'schema' => 'atlas.cortex.scope_comprehension.v1',
            'inventory' => [
                ['symbol' => 'AtlasLoopFoo', 'file' => 'app/Foo.php', 'kind' => 'class'],
                ['symbol' => 'AtlasLoopBar', 'file' => 'app/Bar.php', 'kind' => 'class'],
            ],
            'doc_stated_gaps' => ['SomeGapCapability'],
        ];
    }

    private function activeProbes(): array
    {
        return [
            'hypothetical_changes' => [
                ['propagation_reason' => 'call_site_dangling', 'caller_file' => 'app/Bar.php'],
                ['propagation_reason' => 'UNKNOWN_REGION', 'fact' => 'UNKNOWN_REGION', 'caller_file' => 'UNKNOWN_REGION'],
            ],
            'counterfactuals' => [
                ['fact' => 'UNRESOLVED_TARGET', 'target' => 'AtlasMissing'],
            ],
            'call_graph_projections' => [
                ['entry' => 'AtlasLoopFoo', 'depth' => 2, 'nodes' => [['symbol_id' => 'AtlasLoopFoo']]],
            ],
            'critical_paths' => [
                ['fact' => 'CRITICAL_FILE', 'file_path' => 'shared/Common.php', 'intersect_count' => 3],
                ['fact' => 'UNKNOWN_REGION', 'at_symbol' => 'Beyond', 'flow_id' => 'flowB'],
            ],
        ];
    }

    public function test_serialization_is_byte_identical_across_two_runs(): void
    {
        $a = (new AtlasCortexActiveSnapshot($this->passive(), $this->activeProbes()))->toJson();
        $b = (new AtlasCortexActiveSnapshot($this->passive(), $this->activeProbes()))->toJson();

        $this->assertSame(sha1($a), sha1($b));
        $this->assertSame($a, $b);
    }

    public function test_passive_mutation_attempt_raises_typed_exception(): void
    {
        $snapshot = new AtlasCortexActiveSnapshot($this->passive(), $this->activeProbes());

        $this->expectException(CortexPassiveImmutabilityViolation::class);
        $snapshot->mutatePassive(['evil' => 'overwrite']);
    }

    public function test_no_scalar_score_or_rank_field_anywhere_in_active_subtree(): void
    {
        $snapshot = new AtlasCortexActiveSnapshot($this->passive(), $this->activeProbes());
        $payload = $snapshot->toArray();

        $forbidden = ['score', 'comprehension_score', 'rank'];
        $this->walk($payload['active'], $forbidden, 'active');
        $this->assertTrue(true);
    }

    public function test_unknown_regions_are_aggregated_from_every_sub_probe(): void
    {
        $snapshot = new AtlasCortexActiveSnapshot($this->passive(), $this->activeProbes());
        $unknowns = $snapshot->active()['unknown_regions'];

        $froms = array_map(static fn (array $u): string => $u['from'], $unknowns);
        $sentinels = array_map(static fn (array $u): string => $u['sentinel'], $unknowns);

        $this->assertContains('hypothetical_changes', $froms);
        $this->assertContains('counterfactuals', $froms);
        $this->assertContains('critical_paths', $froms);
        $this->assertContains('UNKNOWN_REGION', $sentinels);
        $this->assertContains('UNRESOLVED_TARGET', $sentinels);
    }

    public function test_passive_payload_is_preserved_byte_identical_in_snapshot(): void
    {
        $passive = $this->passive();
        $snapshot = new AtlasCortexActiveSnapshot($passive, $this->activeProbes());

        // The passive payload survives serialization intact (no collapse, no overwrite by active).
        $this->assertSame($passive, $snapshot->passive());
    }

    /**
     * @param  list<string>  $forbidden
     */
    private function walk(mixed $value, array $forbidden, string $path): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            if (is_string($key)) {
                $this->assertNotContains($key, $forbidden, "forbidden field '{$key}' found at {$path}");
            }
            $this->walk($child, $forbidden, $path.'.'.$key);
        }
    }
}
