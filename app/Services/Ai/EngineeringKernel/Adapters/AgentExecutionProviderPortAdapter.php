<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Adapters;

use App\Models\AiJob;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\EngineeringKernel\ProviderPort;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\AgentExecutionProviderPortService;
use Closure;

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
            $job = new AiJob([
                'type' => 'atlas_self_construction_native_patch_plan',
                'status' => 'running',
                'provider' => $providerKey,
                'model' => $model,
                'payload' => ['provider' => $providerKey, 'model' => $model, 'route' => ['provider' => $providerKey, 'model' => $model]],
            ]);
            $result = $provider->run($job, $prompt);
            $raw = [
                'ok' => $result->ok,
                'output' => $result->output,
                'provider' => (string) ($result->metadata['provider'] ?? $result->metadata['provider_key'] ?? $provider->key()),
                'model' => (string) ($result->metadata['model'] ?? $result->metadata['actual_model'] ?? $job->model ?? ''),
                'error' => $result->errorMessage,
            ];
        }

        if (($raw['ok'] ?? false) !== true) {
            return ['status' => 'unavailable', 'provider_invoked' => true, 'executes_provider' => true, 'exhausted' => true];
        }
        if (($raw['provider'] ?? null) !== $providerKey || ($raw['model'] ?? null) !== $model) {
            return ['status' => 'provider_route_mismatch', 'provider_invoked' => true, 'executes_provider' => true, 'exhausted' => true];
        }
        $decoded = $this->decodeContract((string) ($raw['output'] ?? ''));
        if ($decoded === null) {
            \Illuminate\Support\Facades\Log::warning('provider_contract_decode_failed', [
                'provider' => $providerKey,
                'model' => $model,
                'output_bytes' => strlen((string) ($raw['output'] ?? '')),
                'output_tail' => substr((string) ($raw['output'] ?? ''), -600),
            ]);

            return ['status' => 'invalid_provider_contract', 'provider_invoked' => true, 'executes_provider' => true, 'exhausted' => true];
        }
        $claimAllowed = array_values(array_map('strval', (array) ($request['claim']['allowed_files'] ?? [])));
        $patchAllowed = array_values(array_map('strval', (array) ($decoded['patch_plan']['allowed_files'] ?? [])));
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

        \Illuminate\Support\Facades\Log::info('provider_patch_plan_decoded', [
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
