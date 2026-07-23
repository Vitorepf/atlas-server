<?php

declare(strict_types=1);

namespace App\Services\Engineering;


/**
 * Shared seam for documentation-reality services that finalize read-only
 * envelopes with tamper-evidence hashes.
 */
final class SharedAtlasDocumentationRealitySelfImprovementModelingServiceSeam
{
    /**
     * Hash the read-only envelope for tamper-evidence, excluding the volatile
     * generated_at field and the hash field itself.
     *
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    public static function finalizeTamperEvidentEnvelope(array $envelope, string $hashField): array
    {
        $hashPayload = $envelope;
        unset($hashPayload['generated_at'], $hashPayload[$hashField]);
        $envelope[$hashField] = hash(
            'sha256',
            json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return $envelope;
    }
}
