<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Services\Ai\Concerns\RunsCliProcesses;
use App\Services\Ai\Policy\AtlasAiRuntimeSettings;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;

class JarvisMlxProvider implements AiProvider
{
    use RunsCliProcesses;

    public function __construct(
        private readonly AtlasAiRuntimeSettings $runtimeSettings,
    ) {}

    public function key(): string
    {
        return 'jarvis_mlx';
    }

    public function run(AiJob $job, string $prompt): AiProviderResult
    {
        return $this->runStreaming($job, $prompt);
    }

    public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
    {
        $provider = $this->runtimeSettings->providerConfig('jarvis_mlx');
        $binary = (string) ($provider['binary'] ?? 'python3');
        $scriptPath = base_path('../dissecar/huw-prosser/jarvis-mlx/repo/cli.py');

        $args = [$scriptPath];

        if ($model = $this->invocationModel($job, $provider)) {
            $args[] = '--model';
            $args[] = $model;
        }

        $result = $this->runProcessStreaming(
            command: array_values(array_merge([$binary], $args)),
            input: $prompt,
            timeoutSeconds: $job->timeout_seconds ?: 60,
            cwd: base_path(),
            onEvent: $onEvent,
            job: $job,
        );

        return $result;
    }

    public function health(): AiProviderHealthCheck
    {
        $provider = $this->runtimeSettings->providerConfig('jarvis_mlx');
        $binary = (string) ($provider['binary'] ?? 'python3');
        $scriptPath = base_path('../dissecar/huw-prosser/jarvis-mlx/repo/cli.py');

        return $this->checkCliRuntimeContract(
            check: $this->checkBinary($this->key(), $binary),
            binary: $binary,
            helpArgs: [$scriptPath, '--help'],
            requiredTokens: [
                '--model',
                '--permission-mode',
                '--add-dir',
            ],
            contractName: 'jarvis_mlx_provider.v1',
        );
    }

    private function invocationModel(AiJob $job, array $provider): ?string
    {
        $source = data_get($job->payload, 'model_identity_source') ?? data_get($job->metadata, 'model_identity_source');
        if (in_array($source, ['provider_default_identity', 'configured_model_identity'], true)) {
            return null;
        }

        $model = $job->model ?: ($provider['model'] ?? null);
        if (! is_string($model) && ! is_numeric($model)) {
            return null;
        }

        $model = trim((string) $model);
        if ($model === '' || str_ends_with($model, '_default')) {
            return null;
        }

        return $model;
    }
}
