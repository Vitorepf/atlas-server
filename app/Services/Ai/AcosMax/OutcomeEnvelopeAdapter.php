<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

/**
 * ESP-06 — thin read/write adapter between a native organ payload and the
 * shared MULTX-03 outcome envelope. Adapters label divergent fields by origin;
 * they never fuse or rename the native organs.
 */
interface OutcomeEnvelopeAdapter
{
    public function origin(): string;

    /**
     * @param  array<string,mixed>  $native
     * @param  array<string,mixed>  $context
     */
    public function toEnvelope(array $native, array $context = []): OutcomeEnvelope;

    /**
     * @return array<string,mixed> native-shaped projection for the target organ
     */
    public function fromEnvelope(OutcomeEnvelope $envelope): array;
}
