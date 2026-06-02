<?php

namespace App\Services\Ai\Hermes\Mesh;

/**
 * Handle to a single in-flight mesh child worker.
 *
 * The Executive Mesh service owns the concurrency pool (Atlas-sovereign) and
 * polls handles; the handle abstracts HOW a child actually runs (a real
 * `hermes chat --worktree` Symfony Process in production, or a fake in tests).
 * This keeps the orchestration + governance fully unit-testable without ever
 * launching a model.
 */
interface MeshWorkerHandle
{
    /**
     * True once the child has finished (process no longer running).
     */
    public function isFinished(): bool;

    /**
     * The child's result as an `atlas.hermes.result_packet.v1`-shaped array.
     * Only valid to call after isFinished() returns true.
     *
     * @return array<string,mixed>
     */
    public function result(): array;
}
