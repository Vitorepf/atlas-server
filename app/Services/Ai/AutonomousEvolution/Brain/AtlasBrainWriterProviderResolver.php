<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * FIX 2 (docs/atlas-brain-harness-build-spec.md): the brain writer resolves its provider as
 * `--provider ?? brain_default` and must NEVER read `atlas.loop.default_provider` (the documented wrong key).
 *
 * This is the pure resolution PRIMITIVE: the brain_default slug is injected at the construction site, so the
 * class performs no config()/file/network reads — just deterministic precedence (explicit override wins,
 * otherwise the brain default).
 */
final class AtlasBrainWriterProviderResolver
{
    public const SCHEMA_VERSION = 'atlas.brain.writer_provider.v1';

    public function __construct(private string $brainDefault) {}

    /**
     * Resolve the writer provider. A non-empty trimmed $override wins (source='override'); a null or
     * whitespace-only $override falls back to the injected brain default (source='brain_default').
     *
     * @return array{schema_version:string, provider:string, source:string}
     */
    public function resolve(?string $override): array
    {
        $trimmed = trim((string) $override);
        if ($trimmed !== '') {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'provider' => $trimmed,
                'source' => 'override',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'provider' => $this->brainDefault,
            'source' => 'brain_default',
        ];
    }
}
