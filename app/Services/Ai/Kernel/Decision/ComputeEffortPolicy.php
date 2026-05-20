<?php

namespace App\Services\Ai\Kernel\Decision;

final class ComputeEffortPolicy
{
    public const DEFAULT_LEVEL = 'balanced';

    /** @var list<string> */
    public const LEVELS = ['fast', 'balanced', 'deep', 'max'];

    /** @var array<string,string> */
    private const ALIASES = [
        'low' => 'fast',
        'rapido' => 'fast',
        'rápido' => 'fast',
        'quick' => 'fast',
        'normal' => 'balanced',
        'medio' => 'balanced',
        'médio' => 'balanced',
        'medium' => 'balanced',
        'default' => 'balanced',
        'auto' => 'balanced',
        'alto' => 'deep',
        'high' => 'deep',
        'profundo' => 'deep',
        'xhigh' => 'max',
        'maximo' => 'max',
        'máximo' => 'max',
        'maximum' => 'max',
    ];

    /**
     * @return array<string,mixed>
     */
    public function contract(mixed $requested, ?string $provider = null, array $context = []): array
    {
        $level = $this->normalize($requested) ?? $this->infer($context);
        $source = $this->normalize($requested) !== null ? 'operator' : 'atlas_default';
        $providerMapping = $provider !== null ? $this->providerMapping($provider, $level) : null;

        return [
            'schema_version' => 'atlas.compute_effort_contract.v1',
            'authority' => 'atlas_decide',
            'atlas_level' => $level,
            'source' => $source,
            'available_levels' => self::LEVELS,
            'operator_requested_effort' => $this->scalar($requested),
            'provider' => $provider,
            'provider_mapping' => $providerMapping,
            'measurement' => [
                'status' => 'enabled',
                'signals' => [
                    'duration_ms',
                    'tokens_in',
                    'tokens_out',
                    'cost_estimate_usd',
                    'provider_reported_reasoning_tokens',
                    'retry_count',
                    'completion_status',
                    'gate_status',
                    'quality_score',
                ],
                'rule' => 'effort is measured as requested level plus observed cost, latency, retries, gates and outcome; provider-specific raw knobs are not surfaced as product authority.',
            ],
        ];
    }

    public function normalize(mixed $value): ?string
    {
        $value = $this->scalar($value);
        if ($value === null) {
            return null;
        }

        $normalized = strtr(mb_strtolower($value), ['_' => '-', ' ' => '-']);
        $normalized = trim($normalized, '-');

        if (in_array($normalized, self::LEVELS, true)) {
            return $normalized;
        }

        return self::ALIASES[$normalized] ?? null;
    }

    public function defaultLevel(): string
    {
        $configured = $this->normalize(config('atlas.ai.compute_effort.default'));

        return $configured ?? self::DEFAULT_LEVEL;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function providerMapping(?string $provider, ?string $level): ?array
    {
        $level = $this->normalize($level) ?? self::DEFAULT_LEVEL;

        return match ($provider) {
            'claude_cli' => [
                'provider' => 'claude_cli',
                'mechanism' => 'cli_arg',
                'name' => '--effort',
                'control' => '--effort',
                'value' => [
                    'fast' => 'low',
                    'balanced' => 'medium',
                    'deep' => 'high',
                    'max' => 'max',
                ][$level],
                'control_status' => 'enforced',
            ],
            'codex_cli' => [
                'provider' => 'codex_cli',
                'mechanism' => 'cli_config_override',
                'name' => 'model_reasoning_effort',
                'control' => 'model_reasoning_effort',
                'value' => [
                    'fast' => 'low',
                    'balanced' => 'medium',
                    'deep' => 'high',
                    'max' => 'xhigh',
                ][$level],
                'control_status' => 'enforced',
            ],
            'gemini_cli' => [
                'provider' => 'gemini_cli',
                'mechanism' => 'not_exposed_by_current_cli',
                'name' => null,
                'control' => null,
                'value' => null,
                'api_equivalent' => [
                    'gemini_3' => [
                        'name' => 'thinkingLevel',
                        'control' => 'thinkingLevel',
                        'value' => [
                            'fast' => 'low',
                            'balanced' => 'medium',
                            'deep' => 'high',
                            'max' => 'high',
                        ][$level],
                    ],
                    'gemini_2_5' => [
                        'name' => 'thinkingBudget',
                        'control' => 'thinkingBudget',
                        'value' => [
                            'fast' => 0,
                            'balanced' => -1,
                            'deep' => 8192,
                            'max' => 24576,
                        ][$level],
                    ],
                ],
                'control_status' => 'observed_only_until_gemini_sdk_driver',
            ],
            default => [
                'provider' => $provider,
                'mechanism' => 'unknown_provider',
                'name' => null,
                'control' => null,
                'value' => null,
                'control_status' => 'not_enforced',
            ],
        };
    }

    private function infer(array $context): string
    {
        $flow = $this->scalar($context['flow'] ?? null);
        $profile = $this->scalar($context['specialist_profile'] ?? null);
        $task = mb_strtolower((string) ($context['task'] ?? ''));

        if ($flow === 'programming.forge' || $profile === 'programming.architecture') {
            return 'max';
        }

        foreach (['arquitetura', 'architecture', 'refactor', 'debug dificil', 'programacao pesada', 'programming pesada', 'forge'] as $needle) {
            if (str_contains($task, $needle)) {
                return 'deep';
            }
        }

        return self::DEFAULT_LEVEL;
    }

    private function scalar(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
