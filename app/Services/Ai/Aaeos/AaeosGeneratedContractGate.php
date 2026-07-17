<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Quarantine gate for Aaeos/Generated contract deciders — never builds, off hot path.
 */
final class AaeosGeneratedContractGate
{
    public const SCHEMA_VERSION = 'atlas.aaeos.generated_contract_gate.v1';

    public const HOT_PATH_ENABLED_CONFIG_KEY = 'atlas_elite_compaction.generated.hot_path_enabled';

    public const DEFAULT_HOT_PATH_ENABLED = false;

    public const QUARANTINE_NAMESPACE_CONFIG_KEY = 'atlas_elite_compaction.generated.quarantine_namespace';

    public function assertHotPathAllowed(string $class): void
    {
        if ((AiValueNormalizer::boolOrNull(config(self::HOT_PATH_ENABLED_CONFIG_KEY, self::DEFAULT_HOT_PATH_ENABLED)) ?? self::DEFAULT_HOT_PATH_ENABLED)) {
            return;
        }
        $class = AiValueNormalizer::trimmedStringOrNull($class) ?? '';
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
            'hot_path_enabled' => (AiValueNormalizer::boolOrNull(config(self::HOT_PATH_ENABLED_CONFIG_KEY, self::DEFAULT_HOT_PATH_ENABLED)) ?? self::DEFAULT_HOT_PATH_ENABLED),
            'generated_file_count' => $count,
            'quarantine_namespace' => AiValueNormalizer::trimmedStringOrNull(
                config(self::QUARANTINE_NAMESPACE_CONFIG_KEY) ?? null
            ) ?? '',
        ];
    }
}
