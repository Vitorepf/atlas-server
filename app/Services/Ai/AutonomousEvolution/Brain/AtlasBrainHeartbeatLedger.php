<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlLedgerTrait;
use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;

final class AtlasBrainHeartbeatLedger
{
    use JsonlLedgerTrait;

    public const SCHEMA = 'atlas.brain.heartbeat.v1';

    public function __construct(?string $root = null)
    {
        $this->root = rtrim((string) ($root ?? config('atlas.brain.heartbeat_root', storage_path('app/atlas/brain/heartbeat'))), '/');
    }

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

        try {
            (new JsonlReceiptStore($this->pathFor($scope)))->append($persisted);
        } catch (\Throwable) {
            return null; // was: unwritable target dir ⇒ null, never throw
        }

        return $persisted;
    }

    /** @return list<array<string,mixed>> */
    public function tail(string $scope, int $k = 30): array
    {
        if ($k <= 0) {
            return [];
        }

        return $this->doTail($scope, $k);
    }
}
