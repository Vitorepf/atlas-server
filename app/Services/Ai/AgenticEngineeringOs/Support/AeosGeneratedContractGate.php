<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Support;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Quarantine gate for Aaeos/Generated contract deciders — never builds, off hot path.
 */
final class AeosGeneratedContractGate
{
    public const SCHEMA_VERSION = 'atlas.aaeos.generated_contract_gate.v1';

    public const HOT_PATH_ENABLED_CONFIG_KEY = 'atlas_elite_compaction.generated.hot_path_enabled';

    public const DEFAULT_HOT_PATH_ENABLED = false;

    public const QUARANTINE_NAMESPACE_CONFIG_KEY = 'atlas_elite_compaction.generated.quarantine_namespace';
    public const FIELD_HOT_PATH_ENABLED = 'hot_path_enabled';
    public const FIELD_GENERATED_FILE_COUNT = 'generated_file_count';
    public const FIELD_QUARANTINE_NAMESPACE = 'quarantine_namespace';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_APP_SERVICES_AI_AAEOS_GENERATED = 'app/Services/Ai/Aaeos/Generated';
    public const FIELD_AAEOS_GENERATED_ = 'Aaeos/Generated/';
    public const FIELD_USE_LIVE_ACOS_SERVICES_OR_ENABLE_ATLAS_ELITE_COMPACTION_GENERATED_HOT_PATH_ENABLED_EXPLICITLY_ = 'Use live ACOS services or enable atlas_elite_compaction.generated.hot_path_enabled explicitly.';

    public function assertHotPathAllowed(string $class): void
    {
        if ((AiValueNormalizer::boolOrNull(config(self::HOT_PATH_ENABLED_CONFIG_KEY, self::DEFAULT_HOT_PATH_ENABLED)) ?? self::DEFAULT_HOT_PATH_ENABLED)) {
            return;
        }
        $class = AiValueNormalizer::trimmedStringOrNull($class) ?? '';
        if (! str_contains($class, 'Aaeos\\Generated\\') && ! str_contains($class, self::FIELD_AAEOS_GENERATED_)) {
            return;
        }
        throw new \RuntimeException(
            'Aaeos/Generated is quarantined off the runtime hot path. '
            .self::FIELD_USE_LIVE_ACOS_SERVICES_OR_ENABLE_ATLAS_ELITE_COMPACTION_GENERATED_HOT_PATH_ENABLED_EXPLICITLY_
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $root = base_path(self::FIELD_APP_SERVICES_AI_AAEOS_GENERATED);
        $count = count(glob($root.'/*.php') ?: []);

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_HOT_PATH_ENABLED => (AiValueNormalizer::boolOrNull(config(self::HOT_PATH_ENABLED_CONFIG_KEY, self::DEFAULT_HOT_PATH_ENABLED)) ?? self::DEFAULT_HOT_PATH_ENABLED),
            self::FIELD_GENERATED_FILE_COUNT => $count,
            self::FIELD_QUARANTINE_NAMESPACE => AiValueNormalizer::trimmedStringOrNull(
                config(self::QUARANTINE_NAMESPACE_CONFIG_KEY) ?? null
            ) ?? '',
        ];
    }
}
