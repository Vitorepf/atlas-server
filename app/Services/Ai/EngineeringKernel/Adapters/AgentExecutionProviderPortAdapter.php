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
        $prompt = trim((string) ($request['prompt'] ?? ''));
        if ($providerKey === '' || $prompt === '') {
            return ['status' => 'invalid_request', 'provider_invoked' => false, 'executes_provider' => true];
        }

        if ($this->providerInvoker instanceof Closure) {
            $raw = ($this->providerInvoker)($providerKey, $prompt);
        } else {
            $provider = ($this->providers ?? app(AiProviderManager::class))->get($providerKey);
            $result = $provider->run(new AiJob([
                'type' => 'atlas_self_construction_native_patch_plan',
                'status' => 'running',
                'payload' => ['provider' => $providerKey],
            ]), $prompt);
            $raw = ['ok' => $result->ok, 'output' => $result->output, 'provider' => $providerKey, 'error' => $result->errorMessage];
        }

        if (($raw['ok'] ?? false) !== true) {
            return ['status' => 'unavailable', 'provider_invoked' => true, 'executes_provider' => true, 'exhausted' => true];
        }
        $decoded = $this->decodeContract((string) ($raw['output'] ?? ''));
        if ($decoded === null) {
            return ['status' => 'invalid_provider_contract', 'provider_invoked' => true, 'executes_provider' => true, 'exhausted' => true];
        }

        return [
            'status' => 'ok',
            'provider_invoked' => true,
            'executes_provider' => true,
            'provider' => $providerKey,
            'patch_plan' => (array) ($decoded['patch_plan'] ?? []),
            'command_plan' => (array) ($decoded['command_plan'] ?? []),
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

        return is_array($decoded)
            && is_array($decoded['patch_plan'] ?? null)
            && is_array($decoded['command_plan'] ?? null)
            ? $decoded
            : null;
    }
}
