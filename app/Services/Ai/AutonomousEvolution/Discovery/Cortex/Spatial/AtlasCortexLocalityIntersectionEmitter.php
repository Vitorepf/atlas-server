<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Spatial;

use RuntimeException;

/**
 * FACT-only emitter that combines FS-locality JSONL and callgraph-locality JSONL into
 * intersection sets per (FQCN, K_fs, K_cg). No ranking, no cohesion score.
 *
 * Fail-loud: missing schema_version on either input row ⇒ typed exception, zero output.
 * Master-switch OFF ⇒ byte-identical no-op; the input files are NOT opened.
 */
final class AtlasCortexLocalityIntersectionEmitter
{
    public const SCHEMA = 'atlas.cortex.locality_intersection.v1';

    public const REQUIRED_INPUT_SCHEMAS = [
        AtlasCortexCallGraphLocalityReporter::SCHEMA,
        'atlas.cortex.fs_locality.v1',
    ];

    /** @var callable(): bool */
    private $masterSwitch;

    public function __construct(
        private readonly string $fsLocalityPath,
        private readonly string $callgraphLocalityPath,
        private readonly string $outputJsonlPath,
        ?callable $masterSwitch = null,
    ) {
        $this->masterSwitch = $masterSwitch ?? static function (): bool {
            if (function_exists('config')) {
                $v = config('atlas.loop.master_enabled');
                if ($v !== null) {
                    return (bool) $v;
                }
            }
            $env = getenv('ATLAS_LOOP_MASTER_ENABLED');

            return $env === false ? true : in_array(strtolower((string) $env), ['1', 'true', 'on', 'yes'], true);
        };
        $dir = dirname($this->outputJsonlPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function emit(): array
    {
        if (! ($this->masterSwitch)()) {
            return ['schema' => self::SCHEMA, 'disabled' => true, 'reason' => 'master_switch_off'];
        }

        $fs = $this->readJsonl($this->fsLocalityPath, 'fs_locality');
        $cg = $this->readJsonl($this->callgraphLocalityPath, 'callgraph_locality');

        $fsBy = $this->groupByFqcnDepth($fs);
        $cgBy = $this->groupByFqcnDepth($cg);

        $rows = [];
        $allFqcns = array_unique(array_merge(array_keys($fsBy), array_keys($cgBy)));
        sort($allFqcns, SORT_STRING);
        foreach ($allFqcns as $fqcn) {
            $fsDepths = array_keys($fsBy[$fqcn] ?? []);
            $cgDepths = array_keys($cgBy[$fqcn] ?? []);
            sort($fsDepths);
            sort($cgDepths);
            foreach ($fsDepths as $kFs) {
                foreach ($cgDepths as $kCg) {
                    $fsNeighbors = $this->unionUpTo($fsBy[$fqcn], $kFs);
                    $cgNeighbors = $this->unionUpTo($cgBy[$fqcn], $kCg);
                    $intersection = array_values(array_intersect($fsNeighbors, $cgNeighbors));
                    sort($intersection, SORT_STRING);
                    $rows[] = [
                        'fqcn' => $fqcn,
                        'k_fs' => $kFs,
                        'k_cg' => $kCg,
                        'intersection_count' => count($intersection),
                        'intersection' => $intersection,
                    ];
                }
            }
        }

        $bytes = '';
        foreach ($rows as $row) {
            $bytes .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        }
        @file_put_contents($this->outputJsonlPath, $bytes);

        return ['schema' => self::SCHEMA, 'row_count' => count($rows), 'output_path' => $this->outputJsonlPath];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $path, string $kind): array
    {
        if (! is_file($path)) {
            throw new LocalityIntersectionInputMissingException($kind.'_input_missing:'.$path);
        }
        $rows = [];
        foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode((string) $line, true);
            if (! is_array($decoded)) {
                throw new LocalityIntersectionInputMalformedException($kind.'_input_malformed_line');
            }
            if (! array_key_exists('schema', $decoded) && ! array_key_exists('schema_version', $decoded)) {
                throw new LocalityIntersectionInputSchemaMissingException($kind.'_input_missing_schema_version');
            }
            $rows[] = $decoded;
        }

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string, array<int, list<string>>>
     */
    private function groupByFqcnDepth(array $rows): array
    {
        $by = [];
        foreach ($rows as $row) {
            $fqcn = (string) ($row['fqcn'] ?? $row['file_path'] ?? '');
            $depth = (int) ($row['depth'] ?? 0);
            $neighbors = array_values(array_map('strval', (array) ($row['neighbors'] ?? $row['neighbor_fqcns'] ?? [])));
            $by[$fqcn][$depth] = $neighbors;
        }

        return $by;
    }

    /**
     * @param  array<int, list<string>>  $depthMap
     * @return list<string>
     */
    private function unionUpTo(array $depthMap, int $maxDepth): array
    {
        $out = [];
        foreach ($depthMap as $depth => $neighbors) {
            if ((int) $depth <= $maxDepth) {
                foreach ($neighbors as $n) {
                    $out[$n] = true;
                }
            }
        }

        return array_keys($out);
    }
}

final class LocalityIntersectionInputMissingException extends RuntimeException {}

final class LocalityIntersectionInputMalformedException extends RuntimeException {}

final class LocalityIntersectionInputSchemaMissingException extends RuntimeException {}
