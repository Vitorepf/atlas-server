<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\Spatial;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Spatial\AtlasCortexCallGraphLocalityReporter;
use Tests\TestCase;

class AtlasCortexCallGraphLocalityReporterTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-callgraph-locality-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_chain_emits_exact_depth_lists_byte_identically(): void
    {
        $adj = [
            'A' => ['B'],
            'B' => ['C'],
            'C' => ['D'],
            'D' => [],
        ];
        $reporter = new AtlasCortexCallGraphLocalityReporter(
            static fn (): array => $adj,
            $this->path,
            [1, 2, 3],
            static fn (): bool => true,
        );
        $reporter->emit();
        $bytesA = (string) file_get_contents($this->path);
        @unlink($this->path);
        $reporter->emit();
        $bytesB = (string) file_get_contents($this->path);

        self::assertSame($bytesA, $bytesB);
        $lines = array_values(array_filter(explode("\n", trim($bytesA))));
        $rows = array_map(static fn (string $l): array => json_decode($l, true), $lines);
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r['fqcn'].':'.$r['depth']] = $r['neighbors'];
        }
        self::assertSame(['B'], $byKey['A:1']);
        self::assertSame(['C'], $byKey['A:2']);
        self::assertSame(['D'], $byKey['A:3']);
    }

    public function test_inheritance_only_input_yields_zero_callgraph_neighbors(): void
    {
        // Caller is responsible for filtering inheritance edges — we hand the reporter an empty
        // call-edge adjacency for A even though A extends B, and assert zero neighbors.
        $reporter = new AtlasCortexCallGraphLocalityReporter(
            static fn (): array => ['A' => [], 'B' => []],
            $this->path,
            [1, 2],
            static fn (): bool => true,
        );
        $reporter->emit();
        $rows = array_map(static fn (string $l): array => json_decode($l, true), array_values(array_filter(explode("\n", trim((string) file_get_contents($this->path))))));
        foreach ($rows as $row) {
            if ($row['fqcn'] === 'A') {
                self::assertSame(0, $row['neighbor_count']);
            }
        }
    }

    public function test_master_switch_off_is_byte_identical_noop(): void
    {
        $reporter = new AtlasCortexCallGraphLocalityReporter(
            static fn (): array => ['A' => ['B']],
            $this->path,
            [1],
            static fn (): bool => false,
        );
        self::assertFileDoesNotExist($this->path);
        $verdict = $reporter->emit();
        self::assertTrue($verdict['disabled']);
        self::assertFileDoesNotExist($this->path);
    }

    public function test_sources_are_sorted_alphabetically_in_emitted_jsonl(): void
    {
        $adj = ['zeta' => [], 'alpha' => [], 'mu' => []];
        $reporter = new AtlasCortexCallGraphLocalityReporter(
            static fn (): array => $adj,
            $this->path,
            [1],
            static fn (): bool => true,
        );
        $reporter->emit();
        $lines = array_values(array_filter(explode("\n", trim((string) file_get_contents($this->path)))));
        $names = array_map(static fn (string $l): string => json_decode($l, true)['fqcn'], $lines);
        self::assertSame(['alpha', 'mu', 'zeta'], $names);
    }
}
