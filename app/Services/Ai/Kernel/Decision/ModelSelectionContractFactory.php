<?php

namespace App\Services\Ai\Kernel\Decision;

use App\Services\Ai\AiProviderModelResolver;

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
    public function forCliDev(?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode, array $context = []): array
    {
        return $this->make(
            schemaVersion: 'atlas.cli_dev.model_selection_contract.v1',
            surface: 'atlas_cli_dev',
            provider: $provider,
            modelSelection: $modelSelection,
            modelOverride: $modelOverride,
            fairMode: $fairMode,
            context: $context,
        );
    }

    /**
     * @param  array<string,mixed>|null  $modelSelection
     * @return array<string,mixed>
     */
    public function forAiChat(?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode, array $context = []): array
    {
        return $this->make(
            schemaVersion: 'atlas.ai_chat.model_selection_contract.v1',
            surface: 'atlas_ai_chat',
            provider: $provider,
            modelSelection: $modelSelection,
            modelOverride: $modelOverride,
            fairMode: $fairMode,
            context: $context,
        );
    }

    /**
     * @param  array<string,mixed>|null  $modelSelection
     * @return array<string,mixed>
     */
    private function make(string $schemaVersion, string $surface, ?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode, array $context): array
    {
        $manual = $provider !== null || $modelSelection !== null || $modelOverride !== null || $fairMode;
        $specialistProfile = $this->specialistProfile($context);
        $effort = app(ComputeEffortPolicy::class)->contract(
            requested: $context['compute_effort'] ?? $context['effort'] ?? null,
            provider: $provider,
            context: array_merge($context, ['specialist_profile' => $specialistProfile]),
        );
        $requestedAlias = is_string($modelSelection['alias'] ?? null) ? (string) $modelSelection['alias'] : null;
        $modelResolution = app(AiProviderModelResolver::class)->resolveWithSource(
            $provider,
            $modelOverride ?: $requestedAlias,
            array_merge($context, [
                'compute_effort' => $effort,
                'specialist_profile' => $specialistProfile,
            ]),
        );

        return [
            'schema_version' => $schemaVersion,
            'surface' => $surface,
            'authority' => self::AUTHORITY,
            'selection_mode' => $manual ? 'manual_override' : 'auto_best_allowed',
            'available_selection_modes' => self::AVAILABLE_SELECTION_MODES,
            'operator_requested_provider' => $provider ?: 'auto',
            'requested_model' => $modelOverride,
            'requested_model_alias' => $requestedAlias,
            'requested_model_source' => is_string($modelSelection['source'] ?? null) ? (string) $modelSelection['source'] : null,
            'selected_model' => $modelResolution['model'] ?? null,
            'selected_model_alias' => $modelResolution['selected_model_alias'] ?? $modelResolution['model_alias'] ?? null,
            'model_family' => $modelResolution['model_family'] ?? null,
            'model_tier' => $modelResolution['model_tier'] ?? null,
            'selection_source' => $modelResolution['selection_source'] ?? $modelResolution['source'] ?? null,
            'domain' => $this->scalar($context['domain'] ?? null),
            'flow' => $this->scalar($context['flow'] ?? null),
            'specialist_profile' => $specialistProfile,
            'specialist_profile_source' => $specialistProfile === null ? null : $this->specialistProfileSource($context),
            'compute_effort' => $effort,
            'fair_mode' => $fairMode,
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function specialistProfile(array $context): ?string
    {
        $explicit = $this->scalar($context['specialist_profile'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        $flow = $this->scalar($context['flow'] ?? null);
        if ($flow === 'programming.visual' || $flow === 'programming.frontend') {
            return 'programming.frontend';
        }

        $task = strtolower((string) ($context['task'] ?? ''));
        foreach (['frontend', 'ui', 'layout', 'screen', 'tela', 'component', 'react', 'expo', 'mobile visual', 'design system'] as $needle) {
            if (str_contains($task, $needle)) {
                return 'programming.frontend';
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function specialistProfileSource(array $context): string
    {
        if ($this->scalar($context['specialist_profile'] ?? null) !== null) {
            return 'explicit_context';
        }

        if ($this->scalar($context['flow'] ?? null) !== null) {
            return 'flow_or_task_inference';
        }

        return 'task_inference';
    }

    private function scalar(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
