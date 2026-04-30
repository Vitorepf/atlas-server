<?php

namespace App\Services\Ai\ValueObjects;

use Illuminate\Support\Str;

class AiTaskRequest
{
    public function __construct(
        private readonly array $data,
    ) {}

    /**
     * @param  array{agent:string,intent:string}  $route
     */
    public static function fromInput(string $input, array $options, array $route): self
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $mode = self::normalizeMode(data_get($payload, 'atlas_workflow_mode'));
        $agent = (string) ($route['agent'] ?? data_get($options, 'agent_slug', 'orquestrador'));

        return new self([
            'schema_version' => 1,
            'operator_input_excerpt' => Str::limit(trim($input), 1200, '...'),
            'surface' => self::surface($options, $payload),
            'workspace' => self::workspace($payload),
            'task_type' => self::inferTaskType($input, $mode, $agent, $payload),
            'domain' => self::domain($agent, $payload),
            'risk_level' => self::inferRiskLevel($input, $payload),
            'desired_mode' => $mode,
            'intent' => (string) ($route['intent'] ?? 'general'),
            'requested_agent' => data_get($payload, 'requested_agent') ?: ($options['agent_slug'] ?? null),
            'requested_provider' => data_get($payload, 'requested_provider') ?: ($options['provider'] ?? null),
            'constraints' => array_values(array_filter((array) data_get($payload, 'constraints', []))),
            'privacy_class' => self::privacyClass($payload),
            'source' => [
                'type' => $options['source_type'] ?? 'app',
                'id' => $options['source_id'] ?? null,
            ],
        ]);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function taskType(): string
    {
        return (string) $this->data['task_type'];
    }

    public function desiredMode(): string
    {
        return (string) $this->data['desired_mode'];
    }

    public function riskLevel(): string
    {
        return (string) $this->data['risk_level'];
    }

    private static function normalizeMode(mixed $mode): string
    {
        $mode = is_string($mode) ? trim($mode) : '';

        return match ($mode) {
            'plan', 'review', 'execute', 'dev', 'quality_repair', 'council', 'semantic_clarification' => $mode,
            default => 'direct',
        };
    }

    private static function surface(array $options, array $payload): string
    {
        $surface = (string) (data_get($payload, 'surface') ?: data_get($payload, 'app_surface') ?: '');

        if (str_contains($surface, 'cli')) {
            return 'mac_cli';
        }

        return match ($options['source_type'] ?? 'app') {
            'manual' => 'api',
            'scheduled' => 'automation',
            'system' => 'system',
            default => 'mobile_app',
        };
    }

    private static function workspace(array $payload): ?string
    {
        $workspace = data_get($payload, 'workspace') ?: config('atlas.ai.workdir');

        return is_string($workspace) && trim($workspace) !== '' ? trim($workspace) : null;
    }

    private static function inferTaskType(string $input, string $mode, string $agent, array $payload): string
    {
        $explicit = data_get($payload, 'task_type');
        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        if ($mode === 'semantic_clarification' || in_array($agent, ['aclarador', 'vault-curador', 'memory-writer'], true)) {
            return 'memory';
        }

        if ($mode === 'review' || $agent === 'code-reviewer') {
            return 'review';
        }

        if (in_array($mode, ['dev', 'execute'], true)) {
            return 'dev';
        }

        if ($mode === 'quality_repair') {
            return 'quality_repair';
        }

        if ($mode === 'plan') {
            return 'planning';
        }

        $text = Str::lower($input);

        return match (true) {
            str_contains($text, 'debug') || str_contains($text, 'erro') || str_contains($text, 'falha') => 'debug',
            str_contains($text, 'pesquisa') || str_contains($text, 'fonte') || str_contains($text, 'referencia') => 'research',
            str_contains($text, 'decisao') || str_contains($text, 'decisão') || str_contains($text, 'tradeoff') => 'decision',
            str_contains($text, 'codigo') || str_contains($text, 'código') || str_contains($text, 'implemente') || str_contains($text, 'deploy') || str_contains($text, 'migracao') || str_contains($text, 'migração') || str_contains($text, 'schema') => 'dev',
            default => 'direct',
        };
    }

    private static function domain(string $agent, array $payload): string
    {
        $requested = data_get($payload, 'requested_agent');
        if (is_string($requested) && $requested !== '' && $requested !== 'auto') {
            return $requested;
        }

        return $agent === 'orquestrador' ? 'unknown' : $agent;
    }

    private static function inferRiskLevel(string $input, array $payload): string
    {
        $explicit = data_get($payload, 'risk_level');
        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        $text = Str::lower($input);
        $irreversible = ['deletar', 'apagar', 'excluir', 'drop table', 'reset --hard', 'pagamento', 'transferir'];
        foreach ($irreversible as $needle) {
            if (str_contains($text, $needle)) {
                return 'irreversible';
            }
        }

        $high = ['deploy', 'producao', 'produção', 'migracao', 'migração', 'schema', 'seguranca', 'segurança', 'saude', 'saúde', 'financeiro'];
        foreach ($high as $needle) {
            if (str_contains($text, $needle)) {
                return 'high';
            }
        }

        return 'low';
    }

    private static function privacyClass(array $payload): string
    {
        $sensitivity = data_get($payload, 'privacy.sensitivity');

        return is_string($sensitivity) && $sensitivity !== '' ? $sensitivity : 'normal';
    }
}
