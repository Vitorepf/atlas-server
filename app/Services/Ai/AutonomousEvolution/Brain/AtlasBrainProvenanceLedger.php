<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PROVENANCE LEDGER — append-only NDJSON recording, per seeded task, the lineage signals: which finding
 * code triggered it, which portfolio path was active, which action_hint cycle birthed the seed. Lets a
 * future audit answer "where did this task packet come from?" without grepping reflection + done-set
 * separately.
 *
 * Append is the ONLY mutation. Reads are bounded (tail K). Pétreo (lying-mirror trap if editable).
 */
final class AtlasBrainProvenanceLedger
{
    public const SCHEMA = 'atlas.brain.provenance_ledger.v1';

    public const DEFAULT_TAIL = 30;

    private readonly string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim((string) ($root ?? config('atlas.brain.provenance_root', storage_path('app/atlas/brain/provenance'))), '/');
    }

    /**
     * @param  array{cycle_id:string, task_packet_id?:string, target_path?:string, actor?:string, action_hint?:string, recommended_path?:string, source_finding?:string, credit_status?:string, duplicate_key?:string, credit_bucket?:string}  $row
     */
    public function append(string $scope, array $row, ?int $at = null): ?array
    {
        $scope = $this->slugify(trim($scope));
        if ($scope === '') {
            return null;
        }
        $cycleId = trim((string) ($row['cycle_id'] ?? ''));
        if ($cycleId === '') {
            return null;
        }

        $persisted = [
            'schema' => self::SCHEMA,
            'scope' => $scope,
            'cycle_id' => $cycleId,
            'task_packet_id' => (string) ($row['task_packet_id'] ?? ''),
            'target_path' => (string) ($row['target_path'] ?? ''),
            'actor' => (string) ($row['actor'] ?? ''),
            'action_hint' => (string) ($row['action_hint'] ?? ''),
            'recommended_path' => (string) ($row['recommended_path'] ?? ''),
            'source_finding' => (string) ($row['source_finding'] ?? ''),
            'credit_status' => (string) ($row['credit_status'] ?? 'legacy_unscored'),
            'duplicate_key' => (string) ($row['duplicate_key'] ?? ''),
            'credit_bucket' => (string) ($row['credit_bucket'] ?? ''),
            'recorded_at' => $at ?? time(),
        ];

        try {
            $path = $this->pathFor($scope);
            $dir = dirname($path);
            if (! is_dir($dir) && ! @mkdir($dir, 0o775, true) && ! is_dir($dir)) {
                return null;
            }
            @file_put_contents($path, json_encode($persisted, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            return null;
        }

        return $persisted;
    }

    /** @return list<array<string,mixed>> */
    public function tail(string $scope, int $k = self::DEFAULT_TAIL): array
    {
        $path = $this->pathFor($this->slugify(trim($scope)));
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (preg_split('/\R/', (string) @file_get_contents($path)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return array_values(array_slice($out, -max(1, $k)));
    }

    private function pathFor(string $scope): string
    {
        return $this->root.'/'.$scope.'.ndjson';
    }

    private function slugify(string $s): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9._-]+/i', '-', $s));
    }
}
