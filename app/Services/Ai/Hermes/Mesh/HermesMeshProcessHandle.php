<?php

namespace App\Services\Ai\Hermes\Mesh;

use Symfony\Component\Process\Process;

/**
 * MeshWorkerHandle backed by a real, already-started `hermes` Symfony Process
 * (a child of the Executive Mesh fleet, isolated in its own git worktree).
 *
 * The handle is non-blocking: the mesh service polls isFinished() and harvests
 * result() only once the process exits. result() returns an
 * `atlas.hermes.result_packet.v1`-shaped array carrying ONLY hashes + byte
 * counts of the child output — never the raw text — so the reconciliation
 * receipt stays redacted.
 */
class HermesMeshProcessHandle implements MeshWorkerHandle
{
    public function __construct(
        private readonly Process $process,
        private readonly int $childIndex,
    ) {}

    public function isFinished(): bool
    {
        return ! $this->process->isRunning();
    }

    /**
     * @return array<string,mixed>
     */
    public function result(): array
    {
        $text = (string) $this->process->getOutput();
        $bytes = strlen($text);

        return [
            'schema_version' => 'atlas.hermes.result_packet.v1',
            'child_index' => $this->childIndex,
            'result_hash' => $bytes > 0 ? hash('sha256', $text) : null,
            'output' => [
                'response_hash' => $bytes > 0 ? hash('sha256', $text) : null,
                'response_bytes' => $bytes,
            ],
            'exit_code' => $this->process->getExitCode(),
            'timed_out' => ! $this->process->isSuccessful() && $this->process->getExitCode() === null,
        ];
    }
}
