<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

final class AtlasBrainHeartbeatLedger
{
    public const SCHEMA = 'atlas.brain.heartbeat.v1';

    public function __construct(private readonly ?string $root = null) {}

    /** @param array{actor?:string,command?:string,status?:string,dry_run?:bool} $row */
    public function record(string $scope, array $row, ?int $at = null): ?array
    {
        $scope = $this->slugify($scope);
        $actor = trim((string) ($row['actor'] ?? ''));
        if ($scope === '' || $actor === '') {
            return null;
        }

        $persisted = [
            'schema' => self::SCHEMA,
            'scope' => $scope,
            'actor' => $actor,
            'command' => (string) ($row['command'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'dry_run' => (bool) ($row['dry_run'] ?? false),
            'recorded_at' => $at ?? time(),
        ];

        $path = $this->pathFor($scope);
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o775, true) && ! is_dir($dir)) {
            return null;
        }
        @file_put_contents($path, json_encode($persisted, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);

        return $persisted;
    }

    /** @return list<array<string,mixed>> */
    public function tail(string $scope, int $k = 30): array
    {
        $path = $this->pathFor($this->slugify($scope));
        if (! is_file($path)) {
            return [];
        }
        $rows = [];
        foreach (preg_split('/\R/', (string) @file_get_contents($path)) ?: [] as $line) {
            $row = json_decode(trim($line), true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return array_values(array_slice($rows, -max(1, $k)));
    }

    private function pathFor(string $scope): string
    {
        return rtrim((string) ($this->root ?? config('atlas.brain.heartbeat_root', storage_path('app/atlas/brain/heartbeat'))), '/').'/'.$scope.'.ndjson';
    }

    private function slugify(string $scope): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9._-]+/i', '-', trim($scope)));
    }
}
