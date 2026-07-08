<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

/**
 * Quarantine gate for Aaeos/Generated contract deciders — never builds, off hot path.
 */
final class AaeosGeneratedContractGate
{
    public const SCHEMA_VERSION = 'atlas.aaeos.generated_contract_gate.v1';

    public function assertHotPathAllowed(string $class): void
    {
        if ((bool) config('atlas_elite_compaction.generated.hot_path_enabled', false)) {
            return;
        }
        if (! str_contains($class, 'Aaeos\\Generated\\') && ! str_contains($class, 'Aaeos/Generated/')) {
            return;
        }
        throw new \RuntimeException(
            'Aaeos/Generated is quarantined off the runtime hot path. '
            .'Use live ACOS services or enable atlas_elite_compaction.generated.hot_path_enabled explicitly.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $root = base_path('app/Services/Ai/Aaeos/Generated');
        $count = count(glob($root.'/*.php') ?: []);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'hot_path_enabled' => (bool) config('atlas_elite_compaction.generated.hot_path_enabled', false),
            'generated_file_count' => $count,
            'quarantine_namespace' => config('atlas_elite_compaction.generated.quarantine_namespace'),
        ];
    }
}
