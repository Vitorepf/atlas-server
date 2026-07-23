<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Services\Ai\Policy\AtlasAiRuntimeSettings;
use Illuminate\Support\Str;

class GeminiModelCatalog
{
    public const PROVIDER = 'gemini_cli';

    public const ALIAS_AUTO = 'auto';

    public const ALIAS_FLASH = 'gemini_flash';

    public const ALIAS_PRO = 'gemini_pro';

    public function __construct(
        private readonly AtlasAiRuntimeSettings $settings,
    ) {}

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function resolve(mixed $requested = null, array $context = []): array
    {
        $provider = $this->settings->providerConfig(self::PROVIDER);
        $models = $this->models($provider);
        $requestedAlias = $this->normalizeAlias($requested);
        $explicitModel = $this->clean($requested);
        $source = 'config_default';

        if ($explicitModel !== null && $this->aliasForModel($explicitModel, $models) !== null) {
            $alias = (string) $this->aliasForModel($explicitModel, $models);
            $source = 'manual_override';
        } elseif ($requestedAlias !== null && $requestedAlias !== self::ALIAS_AUTO) {
            if (! isset($models[$requestedAlias])) {
                return $this->invalidResolution($requestedAlias, $explicitModel, $models);
            }

            $alias = $requestedAlias;
            $source = 'manual_override';
        } elseif ($this->shouldUsePro($context)) {
            $alias = self::ALIAS_PRO;
            $source = 'atlas_decide';
        } else {
            $alias = $this->defaultAlias($provider, $models);
            $source = ($requestedAlias === self::ALIAS_AUTO || $requested === null) ? 'atlas_decide' : 'config_default';
        }

        if (! isset($models[$alias])) {
            $alias = $this->defaultAlias($provider, $models);
            $source = 'config_default';
        }

        $entry = $models[$alias] ?? reset($models) ?: [];
        $model = $this->clean($entry['model'] ?? null)
            ?: $this->clean($provider['model'] ?? null)
            ?: 'gemini-3.5-flash';

        return [
            'model' => $model,
            'source' => $source,
            'model_label' => $this->clean($entry['label'] ?? null) ?: $model,
            'model_tier' => $this->clean($entry['tier'] ?? null) ?: 'daily',
            'model_alias' => $alias,
            'selected_model_alias' => $alias,
            'selected_model' => $model,
            'model_family' => 'gemini',
            'selection_source' => $source,
            'operator_requested_model_alias' => $requestedAlias,
            'operator_requested_model' => $explicitModel,
            'allow_auto' => (bool) ($provider['allow_auto'] ?? true),
            'allow_manual' => (bool) ($provider['allow_manual'] ?? true),
            'allowed_models' => $this->allowedModels($models),
            'available_model_aliases' => array_keys($models),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveForJob(AiJob $job): array
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $metadata = is_array($job->metadata) ? $job->metadata : [];
        $requested = data_get($payload, 'model_selection_contract.requested_model_alias')
            ?? data_get($payload, 'model_selection_contract.requested_model')
            ?? data_get($payload, 'selected_model_alias')
            ?? data_get($metadata, 'selected_model_alias')
            ?? $job->model;

        return $this->resolve($requested, array_merge($metadata, $payload, [
            'model' => $job->model,
        ]));
    }

    /**
     * @param  array<string,mixed>|null  $provider
     * @return array<string,array<string,mixed>>
     */
    public function models(?array $provider = null): array
    {
        $provider ??= $this->settings->providerConfig(self::PROVIDER);
        $configured = is_array($provider['models'] ?? null) ? $provider['models'] : [];
        $models = [];

        foreach ($configured as $alias => $entry) {
            $alias = $this->normalizeAlias($alias);
            if ($alias === null || $alias === self::ALIAS_AUTO || ! is_array($entry)) {
                continue;
            }

            $model = $this->clean($entry['model'] ?? null);
            if ($model === null) {
                continue;
            }

            $models[$alias] = [
                'model' => $model,
                'label' => $this->clean($entry['label'] ?? null) ?: $model,
                'tier' => $this->clean($entry['tier'] ?? null) ?: ($alias === self::ALIAS_PRO ? 'premium' : 'daily'),
            ];
        }

        if ($models === []) {
            $flashModel = $this->clean($provider['model'] ?? null) ?: 'gemini-3.5-flash';
            $models[self::ALIAS_FLASH] = [
                'model' => $flashModel,
                'label' => $this->clean($provider['model_label'] ?? null) ?: 'Gemini Flash',
                'tier' => $this->clean($provider['model_tier'] ?? null) ?: 'daily',
            ];
            $models[self::ALIAS_PRO] = [
                'model' => $this->clean($provider['premium_model'] ?? null) ?: 'gemini-3.1-pro-preview',
                'label' => $this->clean($provider['premium_model_label'] ?? null) ?: 'Gemini Pro',
                'tier' => 'premium',
            ];
        }

        return $models;
    }

    /**
     * @param  array<string,mixed>  $provider
     * @param  array<string,array<string,mixed>>  $models
     */
    private function defaultAlias(array $provider, array $models): string
    {
        $alias = $this->normalizeAlias($provider['default_model_alias'] ?? null);

        return $alias !== null && isset($models[$alias]) ? $alias : self::ALIAS_FLASH;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function shouldUsePro(array $context): bool
    {
        $level = $this->clean(data_get($context, 'compute_effort.atlas_level') ?? data_get($context, 'compute_effort'));
        if (in_array($level, ['deep', 'max'], true)) {
            return true;
        }

        $haystack = strtolower(implode(' ', array_filter([
            $this->clean($context['domain'] ?? null),
            $this->clean($context['flow'] ?? null),
            $this->clean($context['task'] ?? null),
            $this->clean($context['task_type'] ?? null),
            $this->clean(data_get($context, 'task_request.task_type')),
            $this->clean(data_get($context, 'programming_message_plan.task_type')),
            $this->clean(data_get($context, 'programming_message_plan.flow')),
        ])));

        foreach ([
            'programming.heavy',
            'programming.architecture',
            'architecture',
            'arquitetura',
            'critical_review',
            'review critical',
            'revisao critica',
            'risk_high',
            'alto risco',
            'long_context',
            'contexto longo',
            'debug',
            'repair',
            'programming.repair',
        ] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeAlias(mixed $value): ?string
    {
        $value = $this->clean($value);
        if ($value === null) {
            return null;
        }

        $normalized = Str::of($value)->lower()->replace(['-', ' '], '_')->toString();

        return match ($normalized) {
            'auto' => self::ALIAS_AUTO,
            'flash', 'gemini_flash', 'gemini_3_5_flash' => self::ALIAS_FLASH,
            'pro', 'gemini_pro', 'gemini_3_1_pro', 'gemini_3_pro' => self::ALIAS_PRO,
            default => $normalized,
        };
    }

    /**
     * @param  array<string,array<string,mixed>>  $models
     */
    private function invalidResolution(string $requestedAlias, ?string $requestedModel, array $models): array
    {
        return [
            'model' => null,
            'source' => 'policy_violation',
            'model_label' => null,
            'model_tier' => 'blocked',
            'model_alias' => null,
            'selected_model_alias' => null,
            'selected_model' => null,
            'model_family' => 'gemini',
            'selection_source' => 'policy_violation',
            'operator_requested_model_alias' => $requestedAlias,
            'operator_requested_model' => $requestedModel,
            'allow_auto' => (bool) ($this->settings->providerConfig(self::PROVIDER)['allow_auto'] ?? true),
            'allow_manual' => (bool) ($this->settings->providerConfig(self::PROVIDER)['allow_manual'] ?? true),
            'allowed_models' => $this->allowedModels($models),
            'available_model_aliases' => array_keys($models),
            'error_code' => 'model_not_allowed',
            'error_message' => 'Gemini model alias or id is outside the Atlas configured catalog.',
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $models
     */
    private function aliasForModel(string $model, array $models): ?string
    {
        foreach ($models as $alias => $entry) {
            if (($entry['model'] ?? null) === $model) {
                return $alias;
            }
        }

        return null;
    }

    /**
     * @param  array<string,array<string,mixed>>  $models
     * @return list<string>
     */
    private function allowedModels(array $models): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (array $entry): ?string => $this->clean($entry['model'] ?? null),
            $models,
        ))));
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

        return Str::limit($value, 160, '');
    }
}
