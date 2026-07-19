<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Adapters;

use App\Models\AiJob;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\EngineeringKernel\ProviderPort;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\AgentExecutionProviderPortService;
use App\Support\AtlasSecurity;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Second Engineering Kernel adapter: pure delegation to the existing, already-proven
 * AgentExecutionProviderPortService — zero behavior change, zero new normalization rules. Gives
 * Atlas Dev, Atlas Forge and Autonomos one shared ProviderPort mechanism surface for provider
 * invocation facts while each runtime keeps its own flow.
 */
final class AgentExecutionProviderPortAdapter implements ProviderPort
{
    public function __construct(
        private readonly AgentExecutionProviderPortService $service,
        private readonly ?AiProviderManager $providers = null,
        private readonly ?Closure $providerInvoker = null,
    ) {}

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    public function invoke(array $request): array
    {
        if (($request['execute_provider'] ?? false) !== true) {
            return array_replace($this->service->normalize($request), [
                'provider_invoked' => false,
                'executes_provider' => false,
            ]);
        }

        $providerKey = trim((string) ($request['provider'] ?? ''));
        $model = trim((string) ($request['model'] ?? ''));
        $prompt = trim((string) ($request['prompt'] ?? ''));
        if ($providerKey === '' || $model === '' || $prompt === '') {
            return ['status' => 'invalid_request', 'provider_invoked' => false, 'executes_provider' => true];
        }

        if ($this->providerInvoker instanceof Closure) {
            $raw = ($this->providerInvoker)($providerKey, $model, $prompt);
        } else {
            $provider = ($this->providers ?? app(AiProviderManager::class))->get($providerKey);
            $payload = ['provider' => $providerKey, 'model' => $model, 'route' => ['provider' => $providerKey, 'model' => $model]];
            // Rivals: geração de patch-plan é turno ÚNICO stateless — o modo
            // one-shot (-z) com --usage-file captura os tokens que o chat não
            // emite (mesma resposta do modelo; caminho do endpoint e do agente
            // harbor). Só assim o metadata['hermes_usage'] nasce preenchido.
            // Gated pela env do rivals: fora dela, comportamento byte-idêntico.
            $usageFile = null;
            if (filter_var(getenv('ATLAS_RIVALS_RUNTIME_EXECUTION') ?: false, FILTER_VALIDATE_BOOLEAN)) {
                $usageFile = tempnam(sys_get_temp_dir(), 'rivals-hermes-usage-');
                @unlink($usageFile);
                $payload['hermes'] = ['cli_oneshot' => true, 'usage_file' => $usageFile];
            }
            $job = new AiJob([
                'type' => 'atlas_self_construction_native_patch_plan',
                'status' => 'running',
                'provider' => $providerKey,
                'model' => $model,
                'payload' => $payload,
            ]);
            $result = $provider->run($job, $prompt);
            $raw = [
                'ok' => $result->ok,
                'output' => $result->output,
                'provider' => (string) ($result->metadata['provider'] ?? $result->metadata['provider_key'] ?? $provider->key()),
                'model' => (string) ($result->metadata['model'] ?? $result->metadata['actual_model'] ?? $job->model ?? ''),
                'error' => $result->errorMessage,
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
                'exit_code' => $result->exitCode,
                'duration_ms' => $result->durationMs,
            ];
            // Rivals: o provider_call do fast-path não carrega tokens no path
            // hermes (só o adaptador Sonnet os preenche), e sem tokens TODO
            // relatório do braço com-Atlas trava no gate por usage vazio, mesmo
            // com o braço perfeito. O hermes JÁ captura o usage no metadata
            // (hermes_usage/acp_usage) — aqui só o espelho num sink que o bridge
            // lê. Gated 100% pela env do rivals: zero impacto fora da medição.
            $this->recordRivalsUsageToSink($result->metadata);
        }

        if (($raw['ok'] ?? false) !== true) {
            $errorCode = trim((string) ($raw['error_code'] ?? ''));
            $errorCode = preg_replace('/[^A-Za-z0-9._-]+/', '_', $errorCode) ?: 'unknown';
            $errorCode = substr($errorCode, 0, 120);
            $errorMessage = trim(preg_replace(
                '/\s+/',
                ' ',
                AtlasSecurity::redactString((string) ($raw['error_message'] ?? $raw['error'] ?? '')),
            ) ?: '');

            return [
                'status' => 'unavailable',
                'failure_reason' => 'provider_failure:'.$errorCode,
                'error_code' => $errorCode,
                'error_message' => mb_substr($errorMessage, 0, 500),
                'exit_code' => is_numeric($raw['exit_code'] ?? null) ? (int) $raw['exit_code'] : null,
                'duration_ms' => is_numeric($raw['duration_ms'] ?? null) ? (int) $raw['duration_ms'] : null,
                'provider_invoked' => true,
                'executes_provider' => true,
                'exhausted' => true,
            ];
        }
        if (($raw['provider'] ?? null) !== $providerKey || ($raw['model'] ?? null) !== $model) {
            return ['status' => 'provider_route_mismatch', 'provider_invoked' => true, 'executes_provider' => true, 'exhausted' => true];
        }
        $decoded = $this->decodeContract((string) ($raw['output'] ?? ''));
        if ($decoded === null) {
            Log::warning('provider_contract_decode_failed', [
                'provider' => $providerKey,
                'model' => $model,
                'output_bytes' => strlen((string) ($raw['output'] ?? '')),
                'output_tail' => substr((string) ($raw['output'] ?? ''), -600),
            ]);

            return ['status' => 'invalid_provider_contract', 'provider_invoked' => true, 'executes_provider' => true, 'exhausted' => true];
        }
        $claimAllowed = array_values(array_map('strval', (array) ($request['claim']['allowed_files'] ?? [])));
        $patchAllowed = array_values(array_map('strval', (array) ($decoded['patch_plan']['allowed_files'] ?? [])));
        // Rivals runtime isolado (worktree descartável de benchmark): tarefa de
        // CRIAÇÃO nasce com claim vazio (CodeDiscoveryEngine só enxerga arquivo
        // existente) e o contrato exato vira insatisfazível por definição —
        // matava 100% do braço com-Atlas. Sob a flag explícita, claim vazio
        // adota a proposta do provider, com sanidade de caminho; a checagem
        // exata permanece intacta para TODA operação normal (claim não-vazio).
        $rivalsIsolated = filter_var(getenv('ATLAS_RIVALS_RUNTIME_EXECUTION') ?: false, FILTER_VALIDATE_BOOLEAN);
        if ($claimAllowed === [] && $rivalsIsolated) {
            $sane = $patchAllowed !== [] && count($patchAllowed) <= 8
                && array_all($patchAllowed, static fn (string $p): bool => $p !== ''
                    && ! str_starts_with($p, '/')
                    && ! str_contains($p, '..'));
            if (! $sane) {
                return ['status' => 'invalid_provider_scope', 'provider_invoked' => true, 'executes_provider' => true, 'exhausted' => true];
            }
            $claimAllowed = $patchAllowed;
        }
        if ($claimAllowed === [] || $patchAllowed !== $claimAllowed) {
            return ['status' => 'invalid_provider_scope', 'provider_invoked' => true, 'executes_provider' => true, 'exhausted' => true];
        }
        foreach ((array) ($decoded['patch_plan']['patches'] ?? []) as $patch) {
            if (! is_array($patch) || ! in_array((string) ($patch['path'] ?? ''), $claimAllowed, true)
                || ! in_array((string) ($patch['mode'] ?? ''), ['create', 'modify'], true)
                || ! array_key_exists('next', $patch)) {
                return ['status' => 'invalid_provider_patch', 'provider_invoked' => true, 'executes_provider' => true, 'exhausted' => true];
            }
        }

        Log::info('provider_patch_plan_decoded', [
            'provider' => $providerKey,
            'model' => $model,
            'allowed_files' => $decoded['patch_plan']['allowed_files'] ?? null,
            'patches' => array_map(
                static fn (mixed $p): array => is_array($p)
                    ? ['path' => $p['path'] ?? null, 'mode' => $p['mode'] ?? null, 'next_bytes' => strlen((string) ($p['next'] ?? ''))]
                    : ['invalid' => true],
                (array) ($decoded['patch_plan']['patches'] ?? []),
            ),
        ]);

        return [
            'status' => 'ok',
            'provider_invoked' => true,
            'executes_provider' => true,
            'provider' => $providerKey,
            'patch_plan' => (array) ($decoded['patch_plan'] ?? []),
            'model' => $model,
            'output_hash' => hash('sha256', (string) ($raw['output'] ?? '')),
        ];
    }

    /**
     * Espelha o usage capturado pelo provider (hermes) num arquivo-sink que o
     * bridge do rivals lê, contornando o provider_call do fast-path que não
     * carrega tokens no path hermes. Acumula (o kernel pode chamar o port 2×:
     * inicial + repair). No-op fora do rivals (env ausente).
     *
     * @param  array<string,mixed>  $metadata
     */
    private function recordRivalsUsageToSink(array $metadata): void
    {
        $sink = getenv('ATLAS_RIVALS_USAGE_SINK');
        if (! is_string($sink) || $sink === '') {
            return;
        }
        if (! filter_var(getenv('ATLAS_RIVALS_RUNTIME_EXECUTION') ?: false, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }
        $usage = $metadata['hermes_usage'] ?? $metadata['acp_usage'] ?? null;
        if (! is_array($usage) || $usage === []) {
            return;
        }
        $pick = static function (array $src, array $keys): ?int {
            foreach ($keys as $k) {
                if (is_numeric($src[$k] ?? null)) {
                    return (int) $src[$k];
                }
            }

            return null;
        };
        $in = $pick($usage, ['input_tokens', 'prompt_tokens', 'tokens_in']);
        $out = $pick($usage, ['output_tokens', 'completion_tokens', 'tokens_out']);
        if ($in === null && $out === null) {
            return;
        }
        $prev = is_file($sink)
            ? (json_decode((string) file_get_contents($sink), true) ?: [])
            : [];
        @file_put_contents($sink, json_encode([
            'input_tokens' => (int) ($prev['input_tokens'] ?? 0) + (int) ($in ?? 0),
            'output_tokens' => (int) ($prev['output_tokens'] ?? 0) + (int) ($out ?? 0),
            'calls' => (int) ($prev['calls'] ?? 0) + 1,
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /** @return array<string,mixed>|null */
    private function decodeContract(string $output): ?array
    {
        $trimmed = trim($output);
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $trimmed) ?? '';
        }
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded) && is_array($decoded['patch_plan'] ?? null)) {
            return $decoded;
        }

        // Providers CLI (hermes) misturam o stream de raciocínio antes da
        // resposta; o contrato válido é o ÚLTIMO objeto JSON balanceado com
        // patch_plan. Scanner string-aware, do fim para o começo.
        foreach (array_reverse(self::balancedJsonObjects($trimmed)) as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded) && is_array($decoded['patch_plan'] ?? null)) {
                return $decoded;
            }
        }

        // Último recurso: âncora no próprio contrato. Prosa de raciocínio pode
        // ter aspas/chaves desbalanceadas que enganam o scanner acima.
        $anchor = strrpos($trimmed, '"patch_plan"');
        if ($anchor !== false) {
            $start = strrpos(substr($trimmed, 0, $anchor), '{');
            if ($start !== false) {
                $sub = substr($trimmed, $start);
                $end = strlen($sub);
                for ($attempts = 0; $attempts < 500; $attempts++) {
                    $end = strrpos(substr($sub, 0, $end), '}');
                    if ($end === false) {
                        break;
                    }
                    $decoded = json_decode(substr($sub, 0, $end + 1), true);
                    if (is_array($decoded) && is_array($decoded['patch_plan'] ?? null)) {
                        return $decoded;
                    }
                }
            }
        }

        return null;
    }

    /** @return list<string> Objetos JSON top-level balanceados encontrados no texto. */
    private static function balancedJsonObjects(string $text): array
    {
        $objects = [];
        $depth = 0;
        $start = null;
        $inString = false;
        $escaped = false;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($ch === '\\') {
                    $escaped = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($ch === '"') {
                $inString = true;
            } elseif ($ch === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
            } elseif ($ch === '}' && $depth > 0) {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    $objects[] = substr($text, $start, $i - $start + 1);
                    $start = null;
                }
            }
        }

        return $objects;
    }
}
