<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\Spatial;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Spatial\AtlasCortexLocalityIntersectionEmitter;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Spatial\LocalityIntersectionInputSchemaMissingException;
use Tests\TestCase;

class AtlasCortexLocalityIntersectionEmitterTest extends TestCase
{
    private string $fsPath = '';

    private string $cgPath = '';

    private string $outPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $this->fsPath = sys_get_temp_dir().'/cortex-fs-'.$tag.'.jsonl';
        $this->cgPath = sys_get_temp_dir().'/cortex-cg-'.$tag.'.jsonl';
        $this->outPath = sys_get_temp_dir().'/cortex-out-'.$tag.'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->fsPath);
        @unlink($this->cgPath);
        @unlink($this->outPath);
        parent::tearDown();
    }

    private function writeRows(string $path, array $rows): void
    {
        $bytes = '';
        foreach ($rows as $row) {
            $bytes .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        }
        file_put_contents($path, $bytes);
    }

    public function test_intersection_at_kfs_1_and_kcg_1_yields_common_neighbor(): void
    {
        $this->writeRows($this->fsPath, [
            ['schema' => 'atlas.cortex.fs_locality.v1', 'fqcn' => 'A', 'depth' => 1, 'neighbors' => ['B', 'C']],
        ]);
        $this->writeRows($this->cgPath, [
            ['schema' => 'atlas.cortex.callgraph_locality.v1', 'fqcn' => 'A', 'depth' => 1, 'neighbors' => ['B', 'D']],
        ]);

        $emitter = new AtlasCortexLocalityIntersectionEmitter($this->fsPath, $this->cgPath, $this->outPath, static fn (): bool => true);
        $emitter->emit();

        $bytes = (string) file_get_contents($this->outPath);
        $rows = array_map(static fn (string $l): array => json_decode($l, true), array_values(array_filter(explode("\n", trim($bytes)))));
        $row = $rows[0];
        self::assertSame('A', $row['fqcn']);
        self::assertSame(1, $row['k_fs']);
        self::assertSame(1, $row['k_cg']);
        self::assertSame(['B'], $row['intersection']);
    }

    public function test_byte_identical_emit_across_two_runs(): void
    {
        $this->writeRows($this->fsPath, [['schema' => 'atlas.cortex.fs_locality.v1', 'fqcn' => 'X', 'depth' => 1, 'neighbors' => ['Y']]]);
        $this->writeRows($this->cgPath, [['schema' => 'atlas.cortex.callgraph_locality.v1', 'fqcn' => 'X', 'depth' => 1, 'neighbors' => ['Y']]]);

        $emitter = new AtlasCortexLocalityIntersectionEmitter($this->fsPath, $this->cgPath, $this->outPath, static fn (): bool => true);
        $emitter->emit();
        $a = (string) file_get_contents($this->outPath);
        @unlink($this->outPath);
        $emitter->emit();
        $b = (string) file_get_contents($this->outPath);

        self::assertSame($a, $b);
    }

    public function test_missing_schema_version_field_throws_typed_exception_and_writes_no_bytes(): void
    {
        // Note: write a malformed FS row that lacks 'schema'/'schema_version'.
        file_put_contents($this->fsPath, json_encode(['fqcn' => 'A', 'depth' => 1, 'neighbors' => ['B']])."\n");
        $this->writeRows($this->cgPath, [['schema' => 'atlas.cortex.callgraph_locality.v1', 'fqcn' => 'A', 'depth' => 1, 'neighbors' => ['B']]]);

        $emitter = new AtlasCortexLocalityIntersectionEmitter($this->fsPath, $this->cgPath, $this->outPath, static fn (): bool => true);

        $threw = false;
        try {
            $emitter->emit();
        } catch (LocalityIntersectionInputSchemaMissingException) {
            $threw = true;
        }
        self::assertTrue($threw);
        self::assertFileDoesNotExist($this->outPath);
    }

    public function test_master_switch_off_is_byte_identical_noop_and_does_not_open_inputs(): void
    {
        $opened = ['fs' => false, 'cg' => false];
        // We mock by passing non-existent paths and assert: with master OFF, no exception is thrown
        // (which would happen if the emitter tried to read missing input files).
        $emitter = new AtlasCortexLocalityIntersectionEmitter(
            '/never-existing/fs.jsonl',
            '/never-existing/cg.jsonl',
            $this->outPath,
            static fn (): bool => false,
        );

        $verdict = $emitter->emit();
        self::assertTrue($verdict['disabled']);
        self::assertFileDoesNotExist($this->outPath);
    }
}
