<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognitive\Harness;

use Throwable;

/**
 * AP-819 Obra B (F2′) — Atlas Harness Surface v1.
 *
 * O análogo do "harness definition file" do paper Self-Harness (arXiv 2606.09498):
 * os pontos de configuração do harness DECLARADOS aqui — e SOMENTE eles — são
 * editáveis por uma proposta `harness_config`. G1 é ESTRUTURAL: o pipeline de
 * sinal (Cognitive/Failure/**, judges, a suite congelada F4′, este próprio
 * arquivo) não pertence à superfície ⇒ inalcançável por proposta, por construção.
 *
 * Cada seção tem bounds rígidos (tipo int + min/max). Um edit fora da allowlist
 * ou fora dos bounds é REJEITADO — nunca clampado silenciosamente.
 *
 * Overrides aplicados vivem num overlay durável (harness_overrides.json) que o
 * boot reaplica via config()->set — reversão = remover a entrada (o valor base
 * do config/.env volta a valer). Nada aqui edita .env nem código-fonte.
 */
final class AtlasHarnessSurface
{
    public const SCHEMA_VERSION = 'atlas.cognitive.harness_surface.v1';

    /**
     * A SUPERFÍCIE DECLARADA (allowlist por construção).
     *
     * @var array<string,array{config_path:string,min:int,max:int,description:string}>
     */
    private const SECTIONS = [
        'runtime_control.timeout_seconds' => [
            'config_path' => 'atlas.ai.timeout_seconds',
            'min' => 60,
            'max' => 1800,
            'description' => 'Timeout (s) de uma execução de provider no AiWorker.',
        ],
        'runtime_control.max_attempts' => [
            'config_path' => 'atlas.ai.max_attempts',
            'min' => 1,
            'max' => 5,
            'description' => 'Tentativas máximas por job antes de falha final.',
        ],
        'runtime_control.retry_delay_seconds' => [
            'config_path' => 'atlas.ai.retry_delay_seconds',
            'min' => 0,
            'max' => 900,
            'description' => 'Espera (s) entre tentativas de um job.',
        ],
        'runtime_control.decision_receipt_ttl_seconds' => [
            'config_path' => 'atlas.ai.decision_receipt_ttl_seconds',
            'min' => 600,
            'max' => 86400,
            'description' => 'TTL (s) do DecisionReceipt antes da execução expirar.',
        ],
        'provider_policy.hermes_max_turns' => [
            'config_path' => 'atlas.ai.providers.hermes_cli.max_turns',
            'min' => 10,
            'max' => 120,
            'description' => 'Turnos máximos de uma sessão Hermes CLI.',
        ],
        // Expansão 1 (diretiva 2026-06-11). NOTA G1: a janela de coleta de falhas
        // (failure_auto_feed.window_hours) fica DELIBERADAMENTE fora — o autopilot
        // nunca pode editar o próprio sinal que o julga.
        'runtime_control.sync_bridge_max_execution_seconds' => [
            'config_path' => 'atlas.ai.sync_bridge.max_execution_seconds',
            'min' => 60,
            'max' => 900,
            'description' => 'Teto (s) da execução inline da ponte síncrona do chat.',
        ],
        'loop_control.max_scenarios_per_task' => [
            'config_path' => 'atlas.loop.max_scenarios_per_task',
            'min' => 1,
            'max' => 24,
            'description' => 'Cenários máximos que o Evolution Loop explora por tarefa.',
        ],
        'loop_control.search_patience' => [
            'config_path' => 'atlas.loop.search_patience',
            'min' => 1,
            'max' => 10,
            'description' => 'Cenários consecutivos sem melhora antes do loop convergir.',
        ],
        'loop_control.max_seconds_per_scenario' => [
            'config_path' => 'atlas.loop.max_seconds_per_scenario',
            'min' => 60,
            'max' => 1200,
            'description' => 'Orçamento (s) por cenário do Evolution Loop.',
        ],
        'cache_control.response_cache_ttl_seconds' => [
            'config_path' => 'atlas.ai.cache.ttl_seconds',
            'min' => 60,
            'max' => 86400,
            'description' => 'TTL (s) do cache de respostas determinísticas.',
        ],
    ];

    private ?string $overridesPathOverride = null;

    public function setOverridesPathForTesting(?string $path): void
    {
        $this->overridesPathOverride = $path;
    }

    public function overridesPath(): string
    {
        if ($this->overridesPathOverride !== null) {
            return $this->overridesPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/governance')
            : sys_get_temp_dir().'/atlas/governance';

        return $base.DIRECTORY_SEPARATOR.'harness_overrides.json';
    }

    /**
     * @return array<string,array{config_path:string,min:int,max:int,description:string}>
     */
    public function sections(): array
    {
        return self::SECTIONS;
    }

    /**
     * @return array{valid:bool,reason:?string}
     */
    public function validate(string $key, mixed $value): array
    {
        $section = self::SECTIONS[$key] ?? null;
        if ($section === null) {
            return ['valid' => false, 'reason' => 'key_not_in_harness_surface'];
        }
        if (! is_int($value) && ! (is_numeric($value) && (string) (int) $value === (string) $value)) {
            return ['valid' => false, 'reason' => 'value_must_be_integer'];
        }
        $value = (int) $value;
        if ($value < $section['min'] || $value > $section['max']) {
            return ['valid' => false, 'reason' => sprintf('value_out_of_bounds:%d..%d', $section['min'], $section['max'])];
        }

        return ['valid' => true, 'reason' => null];
    }

    public function currentValue(string $key): ?int
    {
        $section = self::SECTIONS[$key] ?? null;
        if ($section === null) {
            return null;
        }

        return (int) config($section['config_path']);
    }

    /**
     * Aplica um override validado: persiste no overlay + config()->set imediato.
     * Guarda o valor anterior para reversão exata.
     *
     * @return array{applied:bool,reason:?string,key:?string,value:?int,previous:?int}
     */
    public function applyOverride(string $key, mixed $value, string $proposalId): array
    {
        $verdict = $this->validate($key, $value);
        if (! $verdict['valid']) {
            return ['applied' => false, 'reason' => $verdict['reason'], 'key' => $key, 'value' => null, 'previous' => null];
        }

        $value = (int) $value;
        $previous = $this->currentValue($key);
        $overrides = $this->readOverrides();
        $overrides[$key] = [
            'value' => $value,
            'previous' => $previous,
            'proposal_id' => $proposalId,
            'applied_at' => now()->toJSON(),
        ];
        $this->writeOverrides($overrides);

        config()->set(self::SECTIONS[$key]['config_path'], $value);

        return ['applied' => true, 'reason' => null, 'key' => $key, 'value' => $value, 'previous' => $previous];
    }

    /**
     * @return array{reversed:bool,reason:?string,key:?string,restored:?int}
     */
    public function reverseOverride(string $key): array
    {
        $overrides = $this->readOverrides();
        $entry = $overrides[$key] ?? null;
        if ($entry === null) {
            return ['reversed' => false, 'reason' => 'override_not_found', 'key' => $key, 'restored' => null];
        }

        $previous = isset($entry['previous']) ? (int) $entry['previous'] : null;
        unset($overrides[$key]);
        $this->writeOverrides($overrides);

        if ($previous !== null && isset(self::SECTIONS[$key])) {
            config()->set(self::SECTIONS[$key]['config_path'], $previous);
        }

        return ['reversed' => true, 'reason' => null, 'key' => $key, 'restored' => $previous];
    }

    /**
     * Boot overlay: reaplica overrides persistidos (validados de novo — uma
     * entrada corrompida/fora-da-superfície é IGNORADA, nunca aplicada).
     * Fail-open: erro de leitura nunca derruba o boot.
     *
     * @return array{applied:int,skipped:int}
     */
    public function bootOverlay(): array
    {
        $applied = 0;
        $skipped = 0;
        try {
            foreach ($this->readOverrides() as $key => $entry) {
                $value = $entry['value'] ?? null;
                if ($this->validate((string) $key, $value)['valid']) {
                    config()->set(self::SECTIONS[$key]['config_path'], (int) $value);
                    $applied++;
                } else {
                    $skipped++;
                }
            }
        } catch (Throwable) {
            // overlay é opcional por construção; o config base segue valendo.
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function readOverrides(): array
    {
        $path = $this->overridesPath();
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,array<string,mixed>>  $overrides
     */
    private function writeOverrides(array $overrides): void
    {
        $path = $this->overridesPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        file_put_contents($path, json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
