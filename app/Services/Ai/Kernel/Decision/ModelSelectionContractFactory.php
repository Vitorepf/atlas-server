<?php

namespace App\Services\Ai\Kernel\Decision;

final class ModelSelectionContractFactory
{
    public const AUTHORITY = 'atlas_decide';

    /** @var list<string> */
    public const AVAILABLE_SELECTION_MODES = [
        'auto_best_allowed',
        'auto_best_available',
        'manual_override',
    ];

    /**
     * @param  array<string,mixed>|null  $modelSelection
     * @return array<string,mixed>
     */
    public function forCliDev(?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode): array
    {
        return $this->make(
            schemaVersion: 'atlas.cli_dev.model_selection_contract.v1',
            surface: 'atlas_cli_dev',
            provider: $provider,
            modelSelection: $modelSelection,
            modelOverride: $modelOverride,
            fairMode: $fairMode,
        );
    }

    /**
     * @param  array<string,mixed>|null  $modelSelection
     * @return array<string,mixed>
     */
    public function forAiChat(?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode): array
    {
        return $this->make(
            schemaVersion: 'atlas.ai_chat.model_selection_contract.v1',
            surface: 'atlas_ai_chat',
            provider: $provider,
            modelSelection: $modelSelection,
            modelOverride: $modelOverride,
            fairMode: $fairMode,
        );
    }

    /**
     * @param  array<string,mixed>|null  $modelSelection
     * @return array<string,mixed>
     */
    private function make(string $schemaVersion, string $surface, ?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode): array
    {
        $manual = $provider !== null || $modelSelection !== null || $modelOverride !== null || $fairMode;

        return [
            'schema_version' => $schemaVersion,
            'surface' => $surface,
            'authority' => self::AUTHORITY,
            'selection_mode' => $manual ? 'manual_override' : 'auto_best_allowed',
            'available_selection_modes' => self::AVAILABLE_SELECTION_MODES,
            'operator_requested_provider' => $provider ?: 'auto',
            'requested_model' => $modelOverride,
            'requested_model_alias' => is_string($modelSelection['alias'] ?? null) ? (string) $modelSelection['alias'] : null,
            'requested_model_source' => is_string($modelSelection['source'] ?? null) ? (string) $modelSelection['source'] : null,
            'fair_mode' => $fairMode,
        ];
    }
}
