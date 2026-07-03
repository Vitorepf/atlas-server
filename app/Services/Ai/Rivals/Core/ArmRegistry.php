<?php

namespace App\Services\Ai\Rivals\Core;

use InvalidArgumentException;

/**
 * Braço = model_id × runtime. O objetivo 2 (Atlas uplift) depende disto:
 * mesmo modelo, runtimes diferentes (bare vs atlas_dev/forge/loop).
 */
class ArmRegistry
{
    public function __construct(private readonly ModelRegistry $models = new ModelRegistry)
    {
    }

    /** @return array{arm_id: string, model_id: string, runtime: string} */
    public function makeArm(string $modelId, string $runtime): array
    {
        if (! $this->models->isUsable($modelId)) {
            throw new InvalidArgumentException("rivals_unknown_or_disabled_model:{$modelId}");
        }
        $runtimes = config('atlas_rivals.runtimes', []);
        if (! in_array($runtime, $runtimes, true)) {
            throw new InvalidArgumentException("rivals_unknown_runtime:{$runtime}");
        }

        return [
            'arm_id' => "{$modelId}@{$runtime}",
            'model_id' => $modelId,
            'runtime' => $runtime,
        ];
    }

    /** "model@runtime" → arm array (valida os dois lados). */
    public function parse(string $armSpec): array
    {
        $parts = explode('@', $armSpec, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException("rivals_invalid_arm_spec:{$armSpec}");
        }

        return $this->makeArm($parts[0], $parts[1]);
    }
}
