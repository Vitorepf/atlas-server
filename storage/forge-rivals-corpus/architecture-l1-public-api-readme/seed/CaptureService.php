<?php

declare(strict_types=1);

namespace App\Domain\Captures;

/**
 * Public surface of the Captures domain.
 *
 * The arm must produce a README.md adjacent to this file documenting
 * exactly the 4 public methods below — no private helpers, no future
 * speculative API.
 */
final class CaptureService
{
    /**
     * Persist a new capture. Throws CaptureValidationException if the
     * payload fails canonical normalization (empty body, untrusted html,
     * tag overflow).
     *
     * @param  array<string,mixed>  $payload
     */
    public function store(array $payload): Capture
    {
        throw new \LogicException('stub');
    }

    /**
     * Read a capture by id. Returns null when missing — never throws on
     * a not-found.
     */
    public function find(string $captureId): ?Capture
    {
        throw new \LogicException('stub');
    }

    /**
     * Delete a capture. Idempotent: deleting an already-removed capture
     * returns false without raising.
     */
    public function delete(string $captureId): bool
    {
        throw new \LogicException('stub');
    }

    /**
     * Search captures by free-text query. Returns at most $limit results;
     * throws CaptureSearchException only when the search backend is
     * unavailable (callers must surface a degraded-mode banner).
     *
     * @return list<Capture>
     */
    public function search(string $query, int $limit = 25): array
    {
        throw new \LogicException('stub');
    }

    // Private helpers below MUST NOT appear in the README.
    private function normalize(array $payload): array
    {
        return $payload;
    }
}
