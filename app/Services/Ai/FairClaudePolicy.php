<?php

namespace App\Services\Ai;

class FairClaudePolicy
{
    public const MODE_NAME = 'claude_code_comparison';

    public const PROVIDER_LOCK = 'claude_cli';

    public const MODEL_LOCK = 'opus';

    public const ERROR_CODE = 'fair_mode_violation';

    /**
     * @param  array<string,mixed>  $flags
     */
    public function isEnabled(array $flags): bool
    {
        return (bool) ($flags['claude_only'] ?? false)
            || (bool) ($flags['single_provider'] ?? false)
            || (bool) ($flags['no_decide'] ?? false)
            || (bool) ($flags['fallback_disabled'] ?? false);
    }

    /**
     * @param  array<string,mixed>  $flags
     * @return array<string,mixed>
     */
    public function normalizeFlags(array $flags): array
    {
        if (! $this->isEnabled($flags)) {
            return [
                'fair_mode' => false,
                'claude_only' => false,
                'single_provider' => false,
                'no_decide' => false,
                'fallback_disabled' => false,
            ];
        }

        return [
            'fair_mode' => true,
            'claude_only' => (bool) ($flags['claude_only'] ?? false),
            'single_provider' => true,
            'no_decide' => true,
            'fallback_disabled' => true,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $modelSelection
     * @return array<string,mixed>
     */
    public function validate(?string $provider, ?array $modelSelection): array
    {
        if ($provider !== self::PROVIDER_LOCK) {
            return $this->violation(
                message: 'Fair Claude mode requires provider claude_cli.',
                details: ['provider' => $provider],
            );
        }

        if (! is_array($modelSelection) || $modelSelection === []) {
            return $this->violation(
                message: 'Fair Claude mode requires Claude Opus. Use --model=opus or --claude-only.',
                details: ['model' => null],
            );
        }

        $modelProvider = is_string($modelSelection['provider'] ?? null) ? $modelSelection['provider'] : null;
        if ($modelProvider !== self::PROVIDER_LOCK) {
            return $this->violation(
                message: 'Fair Claude mode only allows Claude Opus models.',
                details: [
                    'provider' => $provider,
                    'model_provider' => $modelProvider,
                    'model' => $modelSelection['model'] ?? null,
                ],
            );
        }

        $alias = $this->normalize((string) ($modelSelection['alias'] ?? ''));
        $tier = $this->normalize((string) ($modelSelection['tier'] ?? ''));

        if ($alias !== self::MODEL_LOCK || $tier !== 'premium') {
            return $this->violation(
                message: 'Fair Claude mode requires the configured Claude Opus premium model.',
                details: [
                    'model' => $modelSelection['model'] ?? null,
                    'alias' => $modelSelection['alias'] ?? null,
                    'tier' => $modelSelection['tier'] ?? null,
                ],
            );
        }

        return ['ok' => true];
    }

    /**
     * @return array<string,mixed>
     */
    public function metadata(): array
    {
        return [
            'fair_mode' => true,
            'fair_mode_name' => self::MODE_NAME,
            'single_provider' => true,
            'provider_lock' => self::PROVIDER_LOCK,
            'model_lock' => self::MODEL_LOCK,
            'fallback_disabled' => true,
            'atlas_decide_disabled' => true,
            'council_disabled' => true,
            'allowed_providers' => [self::PROVIDER_LOCK],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function isFairPayload(array $payload): bool
    {
        return (bool) data_get($payload, 'fair_mode.fair_mode')
            || (bool) data_get($payload, 'dev_execution_plan.fair_mode.fair_mode')
            || (bool) data_get($payload, 'dev_execution_plan.operator_options.fair_mode');
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function metadataFromPayload(array $payload): array
    {
        $metadata = data_get($payload, 'fair_mode');
        if (! is_array($metadata)) {
            $metadata = data_get($payload, 'dev_execution_plan.fair_mode');
        }

        return is_array($metadata) && (bool) ($metadata['fair_mode'] ?? false)
            ? array_replace($this->metadata(), $metadata)
            : $this->metadata();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function validateInvocation(?string $provider, ?string $model, array $payload): array
    {
        if ($provider !== self::PROVIDER_LOCK) {
            return $this->violation(
                message: 'Fair Claude mode requires provider claude_cli.',
                details: ['provider' => $provider],
            );
        }

        $expectedModel = $this->expectedResolvedModel($payload);
        if ($expectedModel === null) {
            return $this->violation(
                message: 'Fair Claude mode requires locked Claude Opus model metadata.',
                details: [
                    'model' => $model,
                    'expected_model' => null,
                ],
            );
        }

        if ($expectedModel !== null && $model !== null && $model !== $expectedModel) {
            return $this->violation(
                message: 'Fair Claude mode requires the locked Claude Opus model.',
                details: [
                    'model' => $model,
                    'expected_model' => $expectedModel,
                ],
            );
        }

        $alias = data_get($payload, 'requested_model_alias')
            ?: data_get($payload, 'dev_execution_plan.selected_model.alias');
        $tier = data_get($payload, 'requested_model_tier')
            ?: data_get($payload, 'dev_execution_plan.selected_model.tier');

        if ($alias !== null && $this->normalize((string) $alias) !== self::MODEL_LOCK) {
            return $this->violation(
                message: 'Fair Claude mode requires the configured Claude Opus premium model.',
                details: [
                    'model_alias' => $alias,
                    'model_tier' => $tier,
                ],
            );
        }

        if ($tier !== null && $this->normalize((string) $tier) !== 'premium') {
            return $this->violation(
                message: 'Fair Claude mode requires the configured Claude Opus premium model.',
                details: [
                    'model_alias' => $alias,
                    'model_tier' => $tier,
                ],
            );
        }

        return ['ok' => true];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function expectedResolvedModel(array $payload): ?string
    {
        $model = data_get($payload, 'dev_execution_plan.selected_model.model')
            ?: data_get($payload, 'requested_model');

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }

    /**
     * @param  array<string,mixed>  $details
     * @return array<string,mixed>
     */
    public function violation(string $message, array $details = []): array
    {
        return [
            'ok' => false,
            'error' => self::ERROR_CODE,
            'message' => $message,
            'fair_mode' => $this->metadata(),
            'details' => $details,
        ];
    }

    private function normalize(string $value): string
    {
        return str_replace(['_', '.', ' '], '-', strtolower(trim($value)));
    }
}
