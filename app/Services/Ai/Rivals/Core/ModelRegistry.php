<?php

namespace App\Services\Ai\Rivals2\Core;

/**
 * Modelos que o operador consegue usar no Mac (CLI/API/local), config-driven.
 * Custos aqui são HINTS de planejamento; custo real vem sempre do RunReceipt.
 */
class ModelRegistry
{
    /** @return array<string, array> */
    public function all(): array
    {
        return config('atlas_rivals2.models', []);
    }

    /** @return array<string, array> */
    public function enabled(): array
    {
        return array_filter($this->all(), fn (array $m) => ($m['enabled'] ?? false) === true);
    }

    public function get(string $modelId): ?array
    {
        $model = $this->all()[$modelId] ?? null;

        return $model === null ? null : $model + ['model_id' => $modelId];
    }

    public function isUsable(string $modelId): bool
    {
        $model = $this->get($modelId);

        return $model !== null && ($model['enabled'] ?? false) === true;
    }
}
