<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

class AiProviderModelResolver
{
    private const GEMINI_MODEL = 'gemini-3.1-pro-preview';

    public function __construct(
        private readonly AtlasAiRuntimeSettings $settings,
    ) {}

    /**
     * @return array{model:?string,source:string,model_label:?string,model_tier:string,allow_auto:bool,allow_manual:bool}
     */
    public function resolveWithSource(?string $provider, mixed $explicit = null): array
    {
        $providerConfig = is_string($provider) ? $this->settings->providerConfig($provider) : [];
        $tier = $this->clean($providerConfig['model_tier'] ?? null) ?: $this->settings->defaultTier();
        $allowAuto = (bool) ($providerConfig['allow_auto'] ?? true);
        $allowManual = (bool) ($providerConfig['allow_manual'] ?? true);

        if ($provider === 'gemini_cli') {
            return $this->resolution(
                self::GEMINI_MODEL,
                'provider_fixed_model',
                'Gemini 3.1 Pro Preview',
                'premium',
                $allowAuto,
                $allowManual,
            );
        }

        $explicitModel = $this->clean($explicit);
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
                );
            }

            return $this->resolution($explicitModel, 'explicit', $label, $tier, $allowAuto, $allowManual);
        }

        $configuredModel = $this->clean($providerConfig['model'] ?? null);
        if ($configuredModel !== null) {
            $label = $this->clean($providerConfig['model_label'] ?? null) ?: $configuredModel;

            return $this->resolution($configuredModel, 'configured_model', $label, $tier, $allowAuto, $allowManual);
        }

        $identity = $this->clean($providerConfig['model_identity'] ?? null);
        if ($identity !== null) {
            $label = $this->clean($providerConfig['model_label'] ?? null) ?: $identity;

            return $this->resolution($identity, 'configured_model_identity', $label, $tier, $allowAuto, $allowManual);
        }

        return match ($provider) {
            'claude_cli' => $this->resolution('claude_cli_default', 'provider_default_identity', 'Claude CLI default', $tier, $allowAuto, $allowManual),
            'codex_cli' => $this->resolution('codex_cli_default', 'provider_default_identity', 'Codex CLI default', $tier, $allowAuto, $allowManual),
            'gemini_cli' => $this->resolution(self::GEMINI_MODEL, 'provider_fixed_model', 'Gemini 3.1 Pro Preview', 'premium', $allowAuto, $allowManual),
            'claude_codex' => $this->resolution('council_default', 'provider_default_identity', 'Claude + Codex council', 'council', $allowAuto, $allowManual),
            default => $this->resolution(null, 'unresolved', null, $tier, $allowAuto, $allowManual),
        };
    }

    public function resolve(?string $provider, mixed $explicit = null): ?string
    {
        return $this->resolveWithSource($provider, $explicit)['model'];
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
    private function resolution(?string $model, string $source, ?string $label, string $tier, bool $allowAuto, bool $allowManual): array
    {
        return [
            'model' => $model,
            'source' => $source,
            'model_label' => $label,
            'model_tier' => $tier,
            'allow_auto' => $allowAuto,
            'allow_manual' => $allowManual,
        ];
    }
}
