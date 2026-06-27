<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * HEALTH-SCORE LEDGER — append-only NDJSON time-series of the L90 composite score per scope. Writes
 * one row per snapshot tick. Lets the operator plot the score over days/weeks (drift, recovery,
 * regression) instead of polling brain:state and eyeballing trend.
 *
 * Append is the ONLY mutation. Reads are bounded (tail K). Pure file IO, per-scope NDJSON.
 *
 * Pétreo: réu never edits the ledger (else it'd rewrite history to look better — Goodhart, lying
 * mirror).
 */
final class AtlasBrainHealthScoreLedger
{
    public const SCHEMA = 'atlas.brain.health_score_ledger.v1';

    public const DEFAULT_TAIL = 30;

    private readonly string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim((string) ($root ?? config('atlas.brain.health_score_root', storage_path('app/atlas/brain/health-score'))), '/');
    }

    public function append(string $scope, int $score, ?int $at = null): ?array
    {
        $scope = $this->slugify(trim($scope));
        if ($scope === '') {
            return null;
        }

        $row = [
            'schema' => self::SCHEMA,
            'scope' => $scope,
            'score' => max(0, min(100, $score)),
            'recorded_at' => $at ?? time(),
        ];

        try {
            $path = $this->pathFor($scope);
            $dir = dirname($path);
            if (! is_dir($dir) && ! @mkdir($dir, 0o775, true) && ! is_dir($dir)) {
                return null;
            }
            @file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            return null;
        }

        return $row;
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
