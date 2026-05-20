<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

class AiProviderModelResolver
{
    public function __construct(
        private readonly AtlasAiRuntimeSettings $settings,
        private readonly GeminiModelCatalog $gemini,
    ) {}

    /**
     * @return array{model:?string,source:string,model_label:?string,model_tier:string,allow_auto:bool,allow_manual:bool}
     */
    public function resolveWithSource(?string $provider, mixed $explicit = null, array $context = []): array
    {
        $providerConfig = is_string($provider) ? $this->settings->providerConfig($provider) : [];
        $tier = $this->clean($providerConfig['model_tier'] ?? null) ?: $this->settings->defaultTier();
        $allowAuto = (bool) ($providerConfig['allow_auto'] ?? true);
        $allowManual = (bool) ($providerConfig['allow_manual'] ?? true);

        if ($provider === 'gemini_cli') {
            return $this->gemini->resolve($explicit, $context);
        }

        $explicitModel = $this->clean($explicit);
        $explicitAlias = $this->modelAlias($explicit);
        $defaultAlias = $this->modelAlias($providerConfig['default_model_alias'] ?? null);

        if ($explicitAlias === 'auto' || ($explicitModel === null && $defaultAlias === 'auto')) {
            return $this->resolveGenericAlias($providerConfig, $context, $tier, $allowAuto, $allowManual, 'atlas_decide');
        }

        if (in_array($explicitAlias, ['default', 'premium', 'fallback'], true)) {
            return $this->resolveGenericAlias($providerConfig, ['requested_model_alias' => $explicitAlias], $tier, $allowAuto, $allowManual, 'manual_override');
        }

        if ($explicitModel !== null) {
            $configuredModel = $this->clean($providerConfig['model'] ?? null);
            $identity = $this->clean($providerConfig['model_identity'] ?? null);
            $label = $explicitModel;
            if ($explicitModel === $configuredModel || $explicitModel === $identity) {
                $label = $this->clean($providerConfig['model_label'] ?? null) ?: $explicitModel;
            }
            $premiumModel = $this->clean($providerConfig['premium_model'] ?? null);
            if ($explicitModel === $premiumModel) {
                return $this->resolution(
                    $explicitModel,
                    'explicit',
                    $this->clean($providerConfig['premium_model_label'] ?? null) ?: $explicitModel,
                    'premium',
                    $allowAuto,
                    $allowManual,
                    'premium',
                );
            }
            $fallbackModel = $this->clean($providerConfig['fallback_model'] ?? null);
            if ($explicitModel === $fallbackModel) {
                return $this->resolution(
                    $explicitModel,
                    'explicit',
                    $this->clean($providerConfig['fallback_model_label'] ?? null) ?: $explicitModel,
                    $tier,
                    $allowAuto,
                    $allowManual,
                    'fallback',
                );
            }

            return $this->resolution($explicitModel, 'explicit', $label, $tier, $allowAuto, $allowManual);
        }

        $configuredModel = $this->clean($providerConfig['model'] ?? null);
        if ($configuredModel !== null) {
            $label = $this->clean($providerConfig['model_label'] ?? null) ?: $configuredModel;

            return $this->resolution($configuredModel, 'configured_model', $label, $tier, $allowAuto, $allowManual, 'default');
        }

        $identity = $this->clean($providerConfig['model_identity'] ?? null);
        if ($identity !== null) {
            $label = $this->clean($providerConfig['model_label'] ?? null) ?: $identity;

            return $this->resolution($identity, 'configured_model_identity', $label, $tier, $allowAuto, $allowManual, 'default');
        }

        return match ($provider) {
            'claude_cli' => $this->resolution('claude_cli_default', 'provider_default_identity', 'Claude CLI default', $tier, $allowAuto, $allowManual),
            'codex_cli' => $this->resolution('codex_cli_default', 'provider_default_identity', 'Codex CLI default', $tier, $allowAuto, $allowManual),
            'claude_codex' => $this->resolution('council_default', 'provider_default_identity', 'Claude + Codex council', 'council', $allowAuto, $allowManual),
            default => $this->resolution(null, 'unresolved', null, $tier, $allowAuto, $allowManual),
        };
    }

    public function resolve(?string $provider, mixed $explicit = null, array $context = []): ?string
    {
        return $this->resolveWithSource($provider, $explicit, $context)['model'];
    }

    /**
     * @param  array<string,mixed>  $providerConfig
     * @param  array<string,mixed>  $context
     * @return array{model:?string,source:string,model_label:?string,model_tier:string,allow_auto:bool,allow_manual:bool}
     */
    private function resolveGenericAlias(array $providerConfig, array $context, string $tier, bool $allowAuto, bool $allowManual, string $source): array
    {
        $alias = $this->modelAlias($context['requested_model_alias'] ?? null) ?: $this->adaptiveGenericAlias($context);

        if ($alias === 'premium' && $this->clean($providerConfig['premium_model'] ?? null) !== null) {
            return $this->resolution(
                $this->clean($providerConfig['premium_model'] ?? null),
                $source,
                $this->clean($providerConfig['premium_model_label'] ?? null) ?: $this->clean($providerConfig['premium_model'] ?? null),
                'premium',
                $allowAuto,
                $allowManual,
                'premium',
            );
        }

        if ($alias === 'fallback' && $this->clean($providerConfig['fallback_model'] ?? null) !== null) {
            return $this->resolution(
                $this->clean($providerConfig['fallback_model'] ?? null),
                $source,
                $this->clean($providerConfig['fallback_model_label'] ?? null) ?: $this->clean($providerConfig['fallback_model'] ?? null),
                $tier,
                $allowAuto,
                $allowManual,
                'fallback',
            );
        }

        $model = $this->clean($providerConfig['model'] ?? null) ?: $this->clean($providerConfig['model_identity'] ?? null);

        return $this->resolution(
            $model,
            $source,
            $this->clean($providerConfig['model_label'] ?? null) ?: $model,
            $tier,
            $allowAuto,
            $allowManual,
            'default',
        );
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function adaptiveGenericAlias(array $context): string
    {
        $level = $this->clean(data_get($context, 'compute_effort.atlas_level') ?? data_get($context, 'compute_effort'));
        if (in_array($level, ['deep', 'max'], true)) {
            return 'premium';
        }

        if ($level === 'fast') {
            return 'fallback';
        }

        $haystack = strtolower(implode(' ', array_filter([
            $this->clean($context['domain'] ?? null),
            $this->clean($context['flow'] ?? null),
            $this->clean($context['task'] ?? null),
            $this->clean($context['task_type'] ?? null),
        ])));

        foreach (['architecture', 'arquitetura', 'critical_review', 'revisao critica', 'risk_high', 'alto risco'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return 'premium';
            }
        }

        foreach (['triage', 'rascunho', 'draft', 'mobile', 'voice', 'voz'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return 'fallback';
            }
        }

        return 'default';
    }

    private function modelAlias(mixed $value): ?string
    {
        $value = $this->clean($value);
        if ($value === null) {
            return null;
        }

        return match (Str::of($value)->lower()->replace(['-', ' '], '_')->toString()) {
            'auto' => 'auto',
            'default', 'configured', 'sonnet', 'spark' => 'default',
            'premium', 'opus', 'best', 'max' => 'premium',
            'fallback', 'haiku', 'mini', 'fast' => 'fallback',
            default => null,
        };
    }

    private function clean(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return Str::limit($value, 120, '');
    }

    /**
     * @return array{model:?string,source:string,model_label:?string,model_tier:string,allow_auto:bool,allow_manual:bool}
     */
    private function resolution(?string $model, string $source, ?string $label, string $tier, bool $allowAuto, bool $allowManual, ?string $alias = null): array
    {
        return [
            'model' => $model,
            'source' => $source,
            'model_label' => $label,
            'model_tier' => $tier,
            'model_alias' => $alias,
            'selected_model_alias' => $alias,
            'selected_model' => $model,
            'allow_auto' => $allowAuto,
            'allow_manual' => $allowManual,
        ];
    }
}
