<?php

namespace App\Services\Ai\Rivals\Core;

use InvalidArgumentException;

/**
 * Braço = model_id × runtime (+ bindings nativos para suites externas).
 */
class ArmRegistry
{
    public function __construct(private readonly ModelRegistry $models = new ModelRegistry) {}

    /**
     * @return array{
     *   arm_id: string,
     *   model_id: string,
     *   runtime: string,
     *   cli_model: string,
     *   native_agent: ?string,
     *   suite_id: ?string,
     *   source_repo: ?string,
     *   native_binding_id: ?string
     * }
     */
    public function makeArm(
        string $modelId,
        string $runtime,
        ?string $suiteId = null,
        ?string $nativeAgent = null,
        ?string $sourceRepo = null,
    ): array {
        if (! $this->models->isUsable($modelId)) {
            throw new InvalidArgumentException("rivals_unknown_or_disabled_model:{$modelId}");
        }
        $runtimes = config('atlas_rivals.runtimes', []);
        if (! in_array($runtime, $runtimes, true)) {
            throw new InvalidArgumentException("rivals_unknown_runtime:{$runtime}");
        }
        if ($runtime !== 'bare' && $modelId !== 'local_fake_model') {
            $cmd = config("atlas_rivals.runtime_commands.{$runtime}");
            if (! is_string($cmd) || trim($cmd) === '') {
                throw new InvalidArgumentException("rivals_runtime_command_not_configured:{$runtime}");
            }
        }

        $model = $this->models->get($modelId) ?? [];
        $cliModel = (string) ($model['cli_model'] ?? $modelId);
        $nativeModel = (string) (
            ($suiteId !== null ? ($model['native_models'][$suiteId] ?? null) : null)
            ?? $cliModel
        );
        $agent = $nativeAgent;
        if ($agent === null && $suiteId !== null) {
            $agent = (string) (
                ($model['native_agents'][$suiteId] ?? null)
                ?? config("atlas_rivals.benchmarks.repos.{$suiteId}.native_agent_default")
                ?? $suiteId
            );
        }
        $source = $sourceRepo ?? $suiteId;
        $bindingId = ($suiteId !== null && $agent !== null)
            ? "{$nativeModel}|{$agent}|{$source}|{$runtime}"
            : null;

        return [
            'arm_id' => "{$modelId}@{$runtime}",
            'model_id' => $modelId,
            'runtime' => $runtime,
            'cli_model' => $cliModel,
            'native_model' => $nativeModel,
            'native_agent' => $agent,
            'suite_id' => $suiteId,
            'source_repo' => $source,
            'native_binding_id' => $bindingId,
        ];
    }

    /** "model@runtime" → arm array (valida os dois lados). */
    public function parse(string $armSpec, ?string $suiteId = null): array
    {
        $parts = explode('@', $armSpec, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException("rivals_invalid_arm_spec:{$armSpec}");
        }

        return $this->makeArm($parts[0], $parts[1], $suiteId);
    }
}
