<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Sentinels;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AtlasTaskCoordinationHealthService;
use Closure;
use ReflectionProperty;

/**
 * REGRESSION SENTINEL — pins down the serving-queue-disk drift in
 * {@see AtlasTaskCoordinationHealthService}.
 *
 * The operator serving stack ({@see \App\Services\Ai\SelfConstruction\AtlasTaskServingStack}) runs every
 * read/write on a DEDICATED queue disk chosen by `config('atlas.task_serving.queue_disk')` (env
 * `ATLAS_TASK_SERVING_QUEUE_DISK`, default 'local'). When the health panel is built with NO injected queue it
 * falls back to its private `queueRepo()`. If that fallback constructs the repository WITHOUT the configured
 * disk, health silently inspects a DIFFERENT queue than the serving CLI — a dry queue can read healthy, a real
 * backlog can read empty.
 *
 * This sentinel reads the configured disk, takes a LIVE health service, reaches into its private `queueRepo()`
 * via {@see Closure::bind} (no production hook), reflects the resolved disk off the returned repository, and
 * reports — FACTS, never a score — whether the two agree. A mismatch is surfaced in `drift` with both disk
 * names, so the exact divergence is visible instead of silent.
 */
final class AtlasLoopServingQueueDiskConformanceSentinel
{
    public const SCHEMA = 'atlas.loop.serving_queue_disk_conformance.v1';

    /**
     * @return array{schema:string, conformant:bool, env_disk:string, resolved_disk:string, drift?:array{expected_disk:string, resolved_disk:string}}
     */
    public function check(?AtlasTaskCoordinationHealthService $health = null): array
    {
        $health ??= new AtlasTaskCoordinationHealthService;

        $expectedDisk = $this->normalizeDisk(
            (string) config('atlas.task_serving.queue_disk', AgentControlPlaneTaskPacketQueueRepository::DEFAULT_DISK)
        );

        // Resolve the repository the health service's OWN private queueRepo() returns — the exact instance every
        // health read uses. Bound to the runtime class so an injected (wrong-disk) queue is honored as today.
        $repo = Closure::bind(
            fn (): AgentControlPlaneTaskPacketQueueRepository => $this->queueRepo(),
            $health,
            $health::class,
        )();

        $resolvedDisk = $this->resolveRepositoryDisk($repo);
        $conformant = $resolvedDisk === $expectedDisk;

        $facts = [
            'schema' => self::SCHEMA,
            'conformant' => $conformant,
            'env_disk' => $expectedDisk,
            'resolved_disk' => $resolvedDisk,
        ];
        if (! $conformant) {
            $facts['drift'] = ['expected_disk' => $expectedDisk, 'resolved_disk' => $resolvedDisk];
        }

        return $facts;
    }

    /** Reflect the repository's private `$disk`, resolving null/empty to the repository's own default. */
    private function resolveRepositoryDisk(AgentControlPlaneTaskPacketQueueRepository $repo): string
    {
        $property = new ReflectionProperty(AgentControlPlaneTaskPacketQueueRepository::class, 'disk');
        $value = $property->getValue($repo);

        return $this->normalizeDisk($value === null ? '' : (string) $value);
    }

    private function normalizeDisk(string $disk): string
    {
        $disk = trim($disk);

        return $disk === '' ? AgentControlPlaneTaskPacketQueueRepository::DEFAULT_DISK : $disk;
    }
}
