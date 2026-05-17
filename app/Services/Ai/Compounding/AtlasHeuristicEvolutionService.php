<?php

namespace App\Services\Ai\Compounding;

use App\Models\AiHeuristicUpdate;
use InvalidArgumentException;

class AtlasHeuristicEvolutionService
{
    public const SCHEMA_VERSION = 'atlas.ai.compounding.heuristic_update.v1';

    /**
     * @param  array<string,mixed>  $input
     */
    public function propose(array $input): AiHeuristicUpdate
    {
        $before = $this->requiredArray($input, 'before_state');
        $after = $this->requiredArray($input, 'after_state');
        $evidenceRefs = $this->requiredArray($input, 'evidence_refs');
        $rollbackPlan = $this->requiredArray($input, 'rollback_plan');
        $testRefs = $this->requiredArray($input, 'test_refs');
        $heuristicKey = $this->string($input['heuristic_key'] ?? null);

        if ($heuristicKey === null) {
            throw new InvalidArgumentException('heuristic_update_requires_key');
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'heuristic_key' => $heuristicKey,
            'flow_id' => $this->string($input['flow_id'] ?? null),
            'status' => (bool) ($input['apply'] ?? false) ? 'applied' : 'proposed',
            'before_state' => $before,
            'after_state' => $after,
            'evidence_refs' => $evidenceRefs,
            'rollback_plan' => $rollbackPlan,
            'test_refs' => $testRefs,
            'applied_at' => (bool) ($input['apply'] ?? false) ? now() : null,
        ];
        $payload['receipt_hash'] = CompoundingHash::make($payload);

        return AiHeuristicUpdate::query()->firstOrCreate(
            ['receipt_hash' => $payload['receipt_hash']],
            $payload,
        );
    }

    public function rollBack(AiHeuristicUpdate $update): AiHeuristicUpdate
    {
        $update->forceFill([
            'status' => 'rolled_back',
            'rolled_back_at' => now(),
        ])->save();

        return $update->refresh();
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<int|string,mixed>
     */
    private function requiredArray(array $input, string $key): array
    {
        $value = $input[$key] ?? null;
        if (! is_array($value) || $value === []) {
            throw new InvalidArgumentException('heuristic_update_requires_'.$key);
        }

        return $value;
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
