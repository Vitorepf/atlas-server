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
        $this->root = rtrim((string) ($root ?? $this->defaultRoot()), '/');
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

    private function defaultRoot(): string
    {
        if (function_exists('config') && config()->has('atlas.brain.heartbeat_root')) {
            return (string) config('atlas.brain.heartbeat_root');
        }
        if ($this->runningUnderPhpunit()) {
            $token = (string) (getenv('TEST_TOKEN') ?: getmypid());

            return sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas-phpunit'.DIRECTORY_SEPARATOR.$token
                .DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'atlas'.DIRECTORY_SEPARATOR.'brain'.DIRECTORY_SEPARATOR.'heartbeat';
        }

        return function_exists('storage_path')
            ? storage_path('app/atlas/brain/heartbeat')
            : sys_get_temp_dir().'/atlas/brain/heartbeat';
    }

    private function runningUnderPhpunit(): bool
    {
        if (function_exists('app') && app()->environment('testing')) {
            return true;
        }

        return getenv('APP_ENV') === 'testing'
            || ($_ENV['APP_ENV'] ?? null) === 'testing'
            || ($_SERVER['APP_ENV'] ?? null) === 'testing';
    }
}
