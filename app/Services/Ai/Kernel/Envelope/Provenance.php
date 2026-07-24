<?php

namespace App\Services\Ai\Kernel\Envelope;

use Carbon\CarbonImmutable;

final readonly class Provenance
{
    public function __construct(
        public string $surfaceId,
        public string $surfaceVersion,
        public string $sessionId,
        public CarbonImmutable $receivedAt,
        public ?string $upstreamEnvelopeId = null,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            surfaceId: self::string($input['surface_id'] ?? 'atlas_cli') ?: 'atlas_cli',
            surfaceVersion: self::string($input['surface_version'] ?? 'dev') ?: 'dev',
            sessionId: self::string($input['session_id'] ?? 'default') ?: 'default',
            receivedAt: isset($input['received_at']) ? (function () use ($input) {
                try {
                    return CarbonImmutable::parse($input['received_at']);
                } catch (\Throwable) {
                    return CarbonImmutable::now();
                }
            })() : CarbonImmutable::now(),
            upstreamEnvelopeId: self::optionalString($input['upstream_envelope_id'] ?? null),
        );
    }

    private static function optionalString(mixed $value): ?string
    {
        $string = self::string($value);

        return $string !== '' ? $string : null;
    }
}
