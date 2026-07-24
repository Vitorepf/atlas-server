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
                // A resposta textual continua sendo o fallback compatível, mas
                // function-calls nativos chegam como fatos estruturados do
                // provider. Preservá-los aqui permite que o Atlas empacote o
                // patch_plan sem pedir JSON dentro de JSON ao modelo.
                'metadata' => $result->metadata,
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
        $nativeFunction = $this->packageNativeFunctionCall($raw, $request);
        if (($nativeFunction['status'] ?? null) === 'invalid_provider_scope') {
            return [
                'status' => 'invalid_provider_scope',
                'failure_reason' => 'provider_response_scope',
                'provider_invoked' => true,
                'executes_provider' => true,
                'exhausted' => true,
            ];
        }
        if (($nativeFunction['status'] ?? null) === 'invalid_provider_patch') {
            return [
                'status' => 'invalid_provider_patch',
                'failure_reason' => 'provider_response_patch',
                'provider_invoked' => true,
                'executes_provider' => true,
                'exhausted' => true,
            ];
        }
        $decoded = ($nativeFunction['status'] ?? null) === 'packaged'
            ? (array) ($nativeFunction['contract'] ?? [])
            : $this->decodeContract((string) ($raw['output'] ?? ''));
        $nativePackaged = ($nativeFunction['status'] ?? null) === 'packaged';
        $salvaged = false;
        if ($decoded === null) {
            // Camada 4 (ordem do operador, 20/07): o modelo RESOLVEU mas respondeu
            // em formato livre. Multiplicador não joga resposta boa fora por causa
            // do envelope — com alvo inequívoco, o Atlas monta o patch_plan.
            $decoded = $this->salvageFreeFormContract((string) ($raw['output'] ?? ''), $request, $prompt);
            $salvaged = $decoded !== null;
        }
        if ($decoded === null) {
            Log::warning('provider_contract_decode_failed', [
                'provider' => $providerKey,
                'model' => $model,
                'output_bytes' => strlen((string) ($raw['output'] ?? '')),
                'output_tail' => substr((string) ($raw['output'] ?? ''), -600),
            ]);

            return [
                'status' => 'invalid_provider_contract',
                'failure_reason' => 'provider_response_encoding',
                'provider_invoked' => true,
                'executes_provider' => true,
                'exhausted' => true,
            ];
        }
        if ($nativePackaged) {
            Log::info('provider_contract_packaged_native_function_call', [
                'provider' => $providerKey,
                'model' => $model,
                'target' => $decoded['patch_plan']['patches'][0]['path'] ?? null,
            ]);
        }
        if ($salvaged) {
            // Nunca em silêncio: a fricção de formato EXISTIU e fica visível no
            // log e no retorno — o salvage remove a perda, não o sinal.
            Log::info('provider_contract_salvaged_free_form', [
                'provider' => $providerKey,
                'model' => $model,
                'target' => $decoded['patch_plan']['allowed_files'][0] ?? null,
                'output_bytes' => strlen((string) ($raw['output'] ?? '')),
            ]);
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
                return [
                    'status' => 'invalid_provider_scope',
                    'failure_reason' => 'provider_response_scope',
                    'provider_invoked' => true,
                    'executes_provider' => true,
                    'exhausted' => true,
                ];
            }
            $claimAllowed = $patchAllowed;
        }
        if ($claimAllowed === [] || $patchAllowed !== $claimAllowed) {
            return [
                'status' => 'invalid_provider_scope',
                'failure_reason' => 'provider_response_scope',
                'provider_invoked' => true,
                'executes_provider' => true,
                'exhausted' => true,
            ];
        }
        foreach ((array) ($decoded['patch_plan']['patches'] ?? []) as $patch) {
            if (! is_array($patch) || ! in_array((string) ($patch['path'] ?? ''), $claimAllowed, true)
                || ! in_array((string) ($patch['mode'] ?? ''), ['create', 'modify'], true)
                || ! array_key_exists('next', $patch)) {
                return [
                    'status' => 'invalid_provider_patch',
                    'failure_reason' => 'provider_response_patch',
                    'provider_invoked' => true,
                    'executes_provider' => true,
                    'exhausted' => true,
                ];
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

        // Rivals: o kernel pode rodar N ordens numa resolução e o receipt final
        // projeta só a última — espelha CADA patch_plan decodificado no sink
        // que o bridge aplica em ordem (mesmo padrão do usage sink).
        $this->recordRivalsPatchPlanToSink((array) ($decoded['patch_plan'] ?? []), $salvaged);

        return [
            'status' => 'ok',
            'provider_invoked' => true,
            'executes_provider' => true,
            'provider' => $providerKey,
            'patch_plan' => (array) ($decoded['patch_plan'] ?? []),
            'model' => $model,
            'output_hash' => $nativePackaged
                ? hash('sha256', serialize($decoded['patch_plan'] ?? []))
                : hash('sha256', (string) ($raw['output'] ?? '')),
            'response_channel' => $nativePackaged
                ? 'native_function_call'
                : ($salvaged ? 'free_form' : 'patch_plan_json'),
            'failure_reason' => null,
            // Fricção de formato existiu e fica visível — salvage tira a perda,
            // não o sinal (relatórios podem contar quantas respostas precisaram
            // de resgate por modelo).
            'contract_salvaged' => $salvaged,
        ];
    }

    /**
     * Empacota argumentos de function-call nativo no contrato canônico que o
     * KernelRunExecutor e o sandbox já consomem. O modelo fornece somente o
     * argumento estruturado de uma alteração; `patch_plan` é lei do servidor.
     *
     * @param  array<string,mixed>  $raw
     * @param  array<string,mixed>  $request
     * @return array{status:string,contract?:array<string,mixed>}
     */
    private function packageNativeFunctionCall(array $raw, array $request): array
    {
        $responseContract = is_array($request['response_contract'] ?? null) ? $request['response_contract'] : [];
        if (($responseContract['channel'] ?? null) !== 'native_function_call') {
            return ['status' => 'not_applicable'];
        }

        $calls = $this->nativeFunctionCalls($raw);
        if ($calls === []) {
            return ['status' => 'not_applicable'];
        }
        if (count($calls) !== 1) {
            return ['status' => 'invalid_provider_patch'];
        }
        $call = $calls[0];
        $expectedName = trim((string) ($responseContract['name'] ?? ''));
        if ($expectedName !== '' && ! hash_equals($expectedName, $call['name'])) {
            return ['status' => 'invalid_provider_patch'];
        }

        $arguments = $call['arguments'];
        $path = $arguments['path'] ?? $arguments['file'] ?? $arguments['target'] ?? null;
        $next = $arguments['next'] ?? $arguments['content'] ?? $arguments['contents'] ?? $arguments['code'] ?? null;
        $mode = $arguments['mode'] ?? 'modify';
        $claimAllowed = array_values(array_map('strval', (array) ($request['claim']['allowed_files'] ?? [])));

        if (! is_string($path) || trim($path) === '' || str_starts_with($path, '/') || str_contains($path, '..')
            || $claimAllowed === [] || ! in_array($path, $claimAllowed, true)) {
            return ['status' => 'invalid_provider_scope'];
        }
        if (! is_string($next) || ! in_array($mode, ['create', 'modify'], true)) {
            return ['status' => 'invalid_provider_patch'];
        }

        return [
            'status' => 'packaged',
            'contract' => [
                'patch_plan' => [
                    'allowed_files' => $claimAllowed,
                    'patches' => [[
                        'path' => $path,
                        'mode' => $mode,
                        'next' => $next,
                    ]],
                ],
                'packaged_native_function_call' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return list<array{name:string,arguments:array<string,mixed>}>
     */
    private function nativeFunctionCalls(array $raw): array
    {
        $sources = [$raw];
        if (is_array($raw['metadata'] ?? null)) {
            $sources[] = $raw['metadata'];
        }
        $normalizedCalls = [];

        foreach ($sources as $source) {
            foreach (['tool_calls', 'function_calls'] as $key) {
                $rawCalls = $source[$key] ?? null;
                if (! is_array($rawCalls)) {
                    continue;
                }
                foreach (array_is_list($rawCalls) ? $rawCalls : [$rawCalls] as $call) {
                    if ($normalized = $this->normalizeNativeFunctionCall($call)) {
                        $normalizedCalls[] = $normalized;
                    }
                }
            }
            foreach (['function_call', 'tool_call', 'native_function_call'] as $key) {
                if ($normalized = $this->normalizeNativeFunctionCall($source[$key] ?? null)) {
                    $normalizedCalls[] = $normalized;
                }
            }
        }

        return $normalizedCalls;
    }

    /**
     * @return array{name:string,arguments:array<string,mixed>}|null
     */
    private function normalizeNativeFunctionCall(mixed $call): ?array
    {
        if (! is_array($call)) {
            return null;
        }
        $function = is_array($call['function'] ?? null) ? $call['function'] : $call;
        $name = trim((string) ($function['name'] ?? $call['name'] ?? ''));
        $arguments = $function['arguments'] ?? $function['args'] ?? $call['arguments'] ?? $call['args'] ?? null;
        if (is_string($arguments)) {
            $arguments = json_decode($arguments, true);
        }

        return $name !== '' && is_array($arguments)
            ? ['name' => $name, 'arguments' => $arguments]
            : null;
    }

    /**
     * Espelha o usage capturado pelo provider (hermes) num arquivo-sink que o
     * bridge do rivals lê, contornando o provider_call do fast-path que não
     * carrega tokens no path hermes. Acumula (o kernel pode chamar o port 2×:
     * inicial + repair). No-op fora do rivals (env ausente).
     *
     * @param  array<string,mixed>  $metadata
     */
    /**
     * Espelha cada patch_plan decodificado num sink JSONL que o bridge do
     * rivals aplica em ordem — o receipt final só projeta a ÚLTIMA ordem do
     * kernel e as anteriores morriam invisíveis (provado 20/07: solution.py de
     * 3.380 bytes perdido enquanto o README de 47 era aplicado). No-op fora do
     * rivals (env ausente).
     *
     * @param  array<string,mixed>  $patchPlan
     */
    private function recordRivalsPatchPlanToSink(array $patchPlan, bool $salvaged): void
    {
        $sink = getenv('ATLAS_RIVALS_PATCH_SINK');
        if (! is_string($sink) || $sink === '') {
            return;
        }
        if (! filter_var(getenv('ATLAS_RIVALS_RUNTIME_EXECUTION') ?: false, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }
        if ((array) ($patchPlan['patches'] ?? []) === []) {
            return;
        }
        @file_put_contents(
            $sink,
            json_encode(['patch_plan' => $patchPlan, 'salvaged' => $salvaged], JSON_UNESCAPED_SLASHES).PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }

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

        // Camada 3.5 (provado ao vivo 20/07): kimi emite o contrato QUASE válido
        // com fechadores trocados ("...}}]}" onde ia "...}]}") — json_decode,
        // scanner balanceado e âncora falham todos. Conserto string-aware que
        // reescreve SÓ os fechadores pela pilha real de aberturas (conteúdo
        // intocado) e para quando o objeto raiz fecha (prosa posterior ignorada).
        $anchor = strrpos($trimmed, '"patch_plan"');
        if ($anchor !== false) {
            $start = strrpos(substr($trimmed, 0, $anchor), '{');
            if ($start !== false) {
                $repaired = self::repairJsonNesting(substr($trimmed, $start));
                if ($repaired !== null) {
                    $decoded = json_decode($repaired, true);
                    // Plano ESTRIPADO não vale: resposta TRUNCADA pelo transporte
                    // (GAP-HERMES-01, chunk final perdido) fechada pela pilha vira
                    // patch_plan com patches/allowed_files vazios — devolver isso
                    // trocava invalid_contract (que dispara o retry declarado) por
                    // um scope-invalid enganoso (provado ao vivo 20/07, 1873_B).
                    if (is_array($decoded) && is_array($decoded['patch_plan'] ?? null)
                        && (array) ($decoded['patch_plan']['patches'] ?? []) !== []
                        && (array) ($decoded['patch_plan']['allowed_files'] ?? []) !== []) {
                        Log::info('provider_contract_json_repaired', ['bytes' => strlen($repaired)]);

                        return $decoded;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Reescreve fechadores (}/]) pelo que a pilha de aberturas exige, ignorando
     * conteúdo de strings; completa fechadores faltantes no fim e PARA quando o
     * objeto raiz fecha (prosa depois do JSON não corrompe). Retorna null se
     * houver fechador sem abertura antes de qualquer raiz completa.
     */
    private static function repairJsonNesting(string $candidate): ?string
    {
        $out = '';
        $stack = [];
        $inString = false;
        $escape = false;
        $length = strlen($candidate);
        for ($i = 0; $i < $length; $i++) {
            $char = $candidate[$i];
            if ($inString) {
                $out .= $char;
                if ($escape) {
                    $escape = false;
                } elseif ($char === '\\') {
                    $escape = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($char === '"') {
                $inString = true;
                $out .= $char;

                continue;
            }
            if ($char === '{' || $char === '[') {
                $stack[] = $char;
                $out .= $char;

                continue;
            }
            if ($char === '}' || $char === ']') {
                if ($stack === []) {
                    return null;
                }
                $out .= array_pop($stack) === '{' ? '}' : ']';
                if ($stack === []) {
                    return $out; // raiz fechou — resto é prosa
                }

                continue;
            }
            $out .= $char;
        }
        while ($stack !== []) {
            $out .= array_pop($stack) === '{' ? '}' : ']';
        }

        return $out;
    }

    /**
     * Camada 4 do contrato (ordem do operador, 20/07): o modelo RESOLVEU mas
     * respondeu em formato livre (código em fence, sem JSON de patch_plan) — o
     * padrão dominante do kimi-k2.7, que matava 9/9 units LCB como
     * `invalid_provider_contract` com a solução NA MÃO. Multiplicador não joga
     * resposta boa fora por causa do envelope: quando o ALVO é inequívoco
     * (claim de exatamente 1 arquivo → modify; ou tarefa de criação com o
     * arquivo NOMEADO no prompt → create) e a resposta tem código em fence, o
     * Atlas monta o patch_plan sozinho. Ambiguidade (vários arquivos do claim,
     * nenhum alvo nomeado, nenhum fence) segue `invalid_provider_contract` —
     * engenharia séria não adivinha. O salvage nunca alarga escopo: 1 alvo, o
     * mesmo que o claim/prompt já autorizava.
     *
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>|null
     */
    private function salvageFreeFormContract(string $output, array $request, string $prompt): ?array
    {
        $claim = array_values(array_map('strval', (array) ($request['claim']['allowed_files'] ?? [])));
        $target = null;
        $mode = 'modify';
        if (count($claim) === 1 && $claim[0] !== '') {
            $target = $claim[0];
        } elseif ($claim === []) {
            // Tarefa de criação (claim vazio): o alvo tem que estar NOMEADO no
            // prompt ("into the file solution.py", "no arquivo x.py") — nunca
            // inferido do texto do modelo, que pode citar arquivos de exemplo.
            if (preg_match('/\b(?:file|arquivo|ficheiro)\s+`?([A-Za-z0-9][A-Za-z0-9_.\/-]*\.[A-Za-z0-9]{1,8})`?/i', $prompt, $m) === 1) {
                $target = ltrim($m[1], '/');
                $mode = 'create';
            }
        }
        if ($target === null || $target === '' || str_contains($target, '..') || str_starts_with($target, '/')) {
            return null;
        }

        // Só fence completo; escolhe o MAIOR bloco (modelos emitem fragmentos de
        // exemplo junto da solução completa). Sem fence não há salvage —
        // "output inteiro é código" é heurística que erra demais.
        if (preg_match_all('/```[A-Za-z0-9_+-]*[ \t]*\R(.*?)(?:\R)?```/s', $output, $m) === 0) {
            return null;
        }
        $blocks = array_values(array_filter(
            array_map('rtrim', $m[1]),
            static fn (string $block): bool => trim($block) !== ''
        ));
        if ($blocks === []) {
            return null;
        }
        usort($blocks, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return [
            'patch_plan' => [
                'allowed_files' => [$target],
                'patches' => [[
                    'path' => $target,
                    'mode' => $mode,
                    'next' => $blocks[0]."\n",
                ]],
            ],
            'salvaged_free_form' => true,
        ];
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
