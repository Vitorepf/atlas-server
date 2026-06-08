<?php

namespace App\Services\Ai\Hermes\Acp;

/**
 * One warm, already-initialized `hermes acp` process held across jobs by
 * {@see HermesAcpSessionPool}.
 *
 * Carries:
 *  - the live {@see HermesAcpChannel} (the persistent process),
 *  - a MONOTONIC JSON-RPC message id sequence (so a reused process never collides
 *    request ids across jobs — initialize=1, job1 session/prompt=2/3, job2=4/5, …),
 *  - the `initialized` flag (the expensive handshake is done once),
 *  - a `served` counter so the pool can recycle the process after N prompts.
 *
 * Not shared across workers — each worker process owns its own pool, and a single
 * worker drains its queue sequentially, so there is never concurrent access to one
 * warm session.
 */
class HermesAcpWarmSession
{
    private int $lastId = 0;

    public function __construct(
        public readonly HermesAcpChannel $channel,
        public readonly string $key,
        public bool $initialized = false,
        public int $served = 0,
    ) {}

    /** Next monotonic JSON-RPC message id for this process's lifetime. */
    public function nextId(): int
    {
        return ++$this->lastId;
    }
}
