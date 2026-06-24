<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council;

use InvalidArgumentException;

/**
 * CORTEX LENS REGISTRY — the spine of the v+3 Cortex Council. Each lens implements {@see LensContract} and
 * registers itself under a unique id; the registry is the single map id ⇒ lens that the (future) council
 * triangulator iterates over.
 *
 * IDEMPOTENT REGISTRATION: re-registering the SAME id with the SAME concrete class is a no-op (so service
 * provider boot calls can be retried without consequence). Re-registering the SAME id with a DIFFERENT class
 * throws {@see InvalidArgumentException} — that would silently swap a perspective and is a Goodhart hazard.
 *
 * NO scoring, NO triangulation, NO weighting here. This packet is JUST the spine. The 5 lens packets and the
 * triangulator slot in on top of this contract.
 */
final class AtlasCortexLensRegistry
{
    /** @var array<string,LensContract> id ⇒ lens */
    private array $lenses = [];

    public function register(LensContract $lens): void
    {
        $id = $lens->id();
        if ($id === '') {
            throw new InvalidArgumentException('LensContract id() must not be empty');
        }

        if (isset($this->lenses[$id])) {
            $existing = $this->lenses[$id];
            if ($existing::class !== $lens::class) {
                throw new InvalidArgumentException(sprintf(
                    'Cortex lens id=%s already registered with %s — refusing re-registration with %s',
                    $id,
                    $existing::class,
                    $lens::class,
                ));
            }

            return; // idempotent: same id + same class ⇒ no-op
        }

        $this->lenses[$id] = $lens;
    }

    /**
     * @return array<string,LensContract>  id ⇒ lens, registration-order preserved
     */
    public function all(): array
    {
        return $this->lenses;
    }

    public function byId(string $id): LensContract
    {
        if (! isset($this->lenses[$id])) {
            throw new InvalidArgumentException('Cortex lens id is not registered: '.$id);
        }

        return $this->lenses[$id];
    }
}
