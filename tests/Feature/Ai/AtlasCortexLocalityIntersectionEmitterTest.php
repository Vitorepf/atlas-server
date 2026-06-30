<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Spatial\AtlasCortexLocalityIntersectionEmitter;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Spatial\LocalityIntersectionInputMissingException;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Spatial\LocalityIntersectionInputSchemaMissingException;
use RuntimeException;
use Tests\TestCase;

final class AtlasCortexLocalityIntersectionEmitterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/locality_test_'.uniqid();
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        array_map('unlink', glob($this->tmpDir.'/*') ?: []);
        @rmdir($this->tmpDir);
    }

    private function writeJsonl(string $filename, array $rows): string
    {
        $path = $this->tmpDir.'/'.$filename;
        $lines = implode("\n", array_map('json_encode', $rows))."\n";
        file_put_contents($path, $lines);

        return $path;
    }

    private function emitter(string $fsPath, string $cgPath, bool $masterOn = true): AtlasCortexLocalityIntersectionEmitter
    {
        return new AtlasCortexLocalityIntersectionEmitter(
            fsLocalityPath:         $fsPath,
            callgraphLocalityPath:  $cgPath,
            outputJsonlPath:        $this->tmpDir.'/out.jsonl',
            masterSwitch:           static fn (): bool => $masterOn,
        );
    }

    // ── AC1: runnable gate (implicit) ─────────────────────────────────────────

    public function test_ac1_output_has_schema_key(): void
    {
        $fs = $this->writeJsonl('fs.jsonl', [
            ['schema' => 'atlas.cortex.fs_locality.v1', 'fqcn' => 'App\\Foo', 'depth' => 1, 'neighbors' => ['App\\Bar']],
        ]);
        $cg = $this->writeJsonl('cg.jsonl', [
            ['schema' => 'atlas.cortex.callgraph_locality.v1', 'fqcn' => 'App\\Foo', 'depth' => 1, 'neighbor_fqcns' => ['App\\Bar']],
        ]);

        $r = $this->emitter($fs, $cg)->emit();

        $this->assertArrayHasKey('schema', $r);
        $this->assertSame(AtlasCortexLocalityIntersectionEmitter::SCHEMA, $r['schema']);
    }

    // ── AC2: master switch OFF → disabled=true, files NOT opened ─────────────

    public function test_ac2_master_switch_off_returns_disabled(): void
    {
        // Paths do NOT exist — if files were opened we'd get a missing-file exception.
        $r = $this->emitter('/nonexistent/fs.jsonl', '/nonexistent/cg.jsonl', masterOn: false)->emit();

        $this->assertArrayHasKey('disabled', $r);
        $this->assertTrue($r['disabled']);
    }

    public function test_ac2_master_switch_off_does_not_throw_missing_file(): void
    {
        // No exception means file was not opened.
        $this->expectNotToPerformAssertions();
        $this->emitter('/nonexistent/fs.jsonl', '/nonexistent/cg.jsonl', masterOn: false)->emit();
    }

    public function test_ac2_master_switch_on_opens_input_files_and_may_throw(): void
    {
        // Paths still don't exist → must throw when master is ON.
        $this->expectException(LocalityIntersectionInputMissingException::class);
        $this->emitter('/nonexistent/fs.jsonl', '/nonexistent/cg.jsonl', masterOn: true)->emit();
    }

    // ── AC3: missing/wrong schema_version fails loud with RuntimeException ────

    public function test_ac3_fs_row_without_schema_throws_runtime_exception(): void
    {
        $fs = $this->writeJsonl('fs.jsonl', [
            ['fqcn' => 'App\\Foo', 'depth' => 1, 'neighbors' => ['App\\Bar']],  // no schema key
        ]);
        $cg = $this->writeJsonl('cg.jsonl', [
            ['schema' => 'atlas.cortex.callgraph_locality.v1', 'fqcn' => 'App\\Foo', 'depth' => 1, 'neighbor_fqcns' => []],
        ]);

        $this->expectException(RuntimeException::class);
        $this->emitter($fs, $cg)->emit();
    }

    public function test_ac3_cg_row_without_schema_throws_runtime_exception(): void
    {
        $fs = $this->writeJsonl('fs.jsonl', [
            ['schema' => 'atlas.cortex.fs_locality.v1', 'fqcn' => 'App\\Foo', 'depth' => 1, 'neighbors' => []],
        ]);
        $cg = $this->writeJsonl('cg.jsonl', [
            ['fqcn' => 'App\\Foo', 'depth' => 1, 'neighbor_fqcns' => []],  // no schema key
        ]);

        $this->expectException(RuntimeException::class);
        $this->emitter($fs, $cg)->emit();
    }

    public function test_ac3_schema_missing_is_typed_exception(): void
    {
        $fs = $this->writeJsonl('fs.jsonl', [
            ['fqcn' => 'App\\Foo', 'depth' => 1, 'neighbors' => []],  // missing schema
        ]);
        $cg = $this->writeJsonl('cg.jsonl', [
            ['schema' => 'atlas.cortex.callgraph_locality.v1', 'fqcn' => 'App\\Foo', 'depth' => 1, 'neighbor_fqcns' => []],
        ]);

        $this->expectException(LocalityIntersectionInputSchemaMissingException::class);
        $this->emitter($fs, $cg)->emit();
    }

    public function test_ac3_exception_produces_no_partial_output(): void
    {
        $outPath = $this->tmpDir.'/out.jsonl';
        $fs = $this->writeJsonl('fs.jsonl', [
            ['fqcn' => 'App\\Foo', 'depth' => 1, 'neighbors' => []],  // missing schema
        ]);
        $cg = $this->writeJsonl('cg.jsonl', [
            ['schema' => 'atlas.cortex.callgraph_locality.v1', 'fqcn' => 'App\\Foo', 'depth' => 1, 'neighbor_fqcns' => []],
        ]);

        try {
            $this->emitter($fs, $cg)->emit();
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFileDoesNotExist($outPath, 'on schema error, no partial output file must be written');
    }

    public function test_ac3_schema_version_key_also_accepted(): void
    {
        // 'schema_version' is an alternative valid key per the service contract.
        $fs = $this->writeJsonl('fs.jsonl', [
            ['schema_version' => 'atlas.cortex.fs_locality.v1', 'fqcn' => 'App\\Foo', 'depth' => 1, 'neighbors' => ['App\\Bar']],
        ]);
        $cg = $this->writeJsonl('cg.jsonl', [
            ['schema_version' => 'atlas.cortex.callgraph_locality.v1', 'fqcn' => 'App\\Foo', 'depth' => 1, 'neighbor_fqcns' => ['App\\Bar']],
        ]);

        // Must NOT throw.
        $r = $this->emitter($fs, $cg)->emit();
        $this->assertArrayHasKey('row_count', $r);
    }

    // ── AC4: valid rows → deterministic intersections ─────────────────────────

    public function test_ac4_intersection_contains_shared_neighbors(): void
    {
        $fs = $this->writeJsonl('fs.jsonl', [
            ['schema' => 'atlas.cortex.fs_locality.v1', 'fqcn' => 'App\\Alpha', 'depth' => 1,
             'neighbors' => ['App\\Shared', 'App\\FsOnly']],
        ]);
        $cg = $this->writeJsonl('cg.jsonl', [
            ['schema' => 'atlas.cortex.callgraph_locality.v1', 'fqcn' => 'App\\Alpha', 'depth' => 1,
             'neighbor_fqcns' => ['App\\Shared', 'App\\CgOnly']],
        ]);

        $r = $this->emitter($fs, $cg)->emit();
        $this->assertSame(1, $r['row_count']);

        $out = array_map(
            static fn (string $l): array => json_decode($l, true),
            array_filter(
                explode("\n", trim(file_get_contents($this->tmpDir.'/out.jsonl'))),
            )
        );

        $row = $out[0];
        $this->assertSame('App\\Alpha', $row['fqcn']);
        $this->assertSame(1, $row['k_fs']);
        $this->assertSame(1, $row['k_cg']);
        $this->assertContains('App\\Shared', $row['intersection']);
        $this->assertNotContains('App\\FsOnly', $row['intersection']);
        $this->assertNotContains('App\\CgOnly', $row['intersection']);
    }

    public function test_ac4_intersection_members_are_sorted(): void
    {
        $fs = $this->writeJsonl('fs.jsonl', [
            ['schema' => 'atlas.cortex.fs_locality.v1', 'fqcn' => 'App\\Root', 'depth' => 1,
             'neighbors' => ['App\\Zebra', 'App\\Alpha', 'App\\Middle']],
        ]);
        $cg = $this->writeJsonl('cg.jsonl', [
            ['schema' => 'atlas.cortex.callgraph_locality.v1', 'fqcn' => 'App\\Root', 'depth' => 1,
             'neighbor_fqcns' => ['App\\Middle', 'App\\Alpha', 'App\\Zebra']],
        ]);

        $r = $this->emitter($fs, $cg)->emit();
        $out = array_map(
            static fn (string $l): array => json_decode($l, true),
            array_filter(explode("\n", trim(file_get_contents($this->tmpDir.'/out.jsonl'))))
        );

        $members = $out[0]['intersection'];
        $sorted  = $members;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $members, 'intersection members must be sorted');
    }

    public function test_ac4_no_overlap_produces_empty_intersection(): void
    {
        $fs = $this->writeJsonl('fs.jsonl', [
            ['schema' => 'atlas.cortex.fs_locality.v1', 'fqcn' => 'App\\X', 'depth' => 1, 'neighbors' => ['App\\A']],
        ]);
        $cg = $this->writeJsonl('cg.jsonl', [
            ['schema' => 'atlas.cortex.callgraph_locality.v1', 'fqcn' => 'App\\X', 'depth' => 1, 'neighbor_fqcns' => ['App\\B']],
        ]);

        $this->emitter($fs, $cg)->emit();
        $out = array_map(
            static fn (string $l): array => json_decode($l, true),
            array_filter(explode("\n", trim(file_get_contents($this->tmpDir.'/out.jsonl'))))
        );

        $this->assertSame(0, $out[0]['intersection_count']);
        $this->assertEmpty($out[0]['intersection']);
    }

    public function test_ac4_output_is_deterministic(): void
    {
        $fs = $this->writeJsonl('fs.jsonl', [
            ['schema' => 'atlas.cortex.fs_locality.v1', 'fqcn' => 'App\\Foo', 'depth' => 1,
             'neighbors' => ['App\\Shared', 'App\\Extra']],
        ]);
        $cg = $this->writeJsonl('cg.jsonl', [
            ['schema' => 'atlas.cortex.callgraph_locality.v1', 'fqcn' => 'App\\Foo', 'depth' => 1,
             'neighbor_fqcns' => ['App\\Shared']],
        ]);

        $r1 = $this->emitter($fs, $cg)->emit();
        $r2 = $this->emitter($fs, $cg)->emit();

        $this->assertSame(
            json_encode($r1, JSON_UNESCAPED_SLASHES),
            json_encode($r2, JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_ac4_fqcns_in_output_are_sorted(): void
    {
        $fs = $this->writeJsonl('fs.jsonl', [
            ['schema' => 'atlas.cortex.fs_locality.v1', 'fqcn' => 'App\\Zebra', 'depth' => 1, 'neighbors' => ['App\\S']],
            ['schema' => 'atlas.cortex.fs_locality.v1', 'fqcn' => 'App\\Alpha', 'depth' => 1, 'neighbors' => ['App\\S']],
        ]);
        $cg = $this->writeJsonl('cg.jsonl', [
            ['schema' => 'atlas.cortex.callgraph_locality.v1', 'fqcn' => 'App\\Zebra', 'depth' => 1, 'neighbor_fqcns' => ['App\\S']],
            ['schema' => 'atlas.cortex.callgraph_locality.v1', 'fqcn' => 'App\\Alpha', 'depth' => 1, 'neighbor_fqcns' => ['App\\S']],
        ]);

        $this->emitter($fs, $cg)->emit();
        $out = array_map(
            static fn (string $l): array => json_decode($l, true),
            array_filter(explode("\n", trim(file_get_contents($this->tmpDir.'/out.jsonl'))))
        );

        $fqcns = array_column($out, 'fqcn');
        $sorted = $fqcns;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $fqcns, 'output rows must be sorted by fqcn');
    }
}
