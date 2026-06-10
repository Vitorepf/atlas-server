<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringBlueprint;
use App\Models\AtlasTask;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;

class EngineeringBlueprintSnapshotService
{
    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $blueprint
     * @return array<string,mixed>|null
     */
    public function currentForTask(AtlasTask $task, array $contract, array $blueprint): ?array
    {
        if (! $this->available() || ! $task->exists) {
            return null;
        }

        $contentHash = $this->contentHash($contract, $blueprint);
        $current = AtlasEngineeringBlueprint::query()
            ->where('task_id', $task->id)
            ->where('status', 'frozen')
            ->latest('version')
            ->latest('frozen_at')
            ->first();

        if (! $current) {
            return null;
        }

        return [
            ...$this->toArray($current),
            'matches_current_content' => $current->content_hash === $contentHash,
        ];
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $blueprint
     * @return array<string,mixed>|null
     */
    public function freezeForTask(AtlasTask $task, array $contract, array $blueprint): ?array
    {
        if (! $this->available() || ! $task->exists) {
            return null;
        }

        $contentHash = $this->contentHash($contract, $blueprint);
        $existing = AtlasEngineeringBlueprint::query()
            ->where('task_id', $task->id)
            ->where('content_hash', $contentHash)
            ->latest('version')
            ->first();

        if ($existing) {
            if ($existing->status !== 'frozen') {
                $existing->forceFill([
                    'status' => 'frozen',
                    'frozen_at' => $existing->frozen_at ?? now(),
                ])->save();
            }

            return [
                ...$this->toArray($existing->refresh()),
                'matches_current_content' => true,
            ];
        }

        return DB::transaction(function () use ($task, $contract, $blueprint, $contentHash): array {
            $version = ((int) AtlasEngineeringBlueprint::query()
                ->where('task_id', $task->id)
                ->max('version')) + 1;

            AtlasEngineeringBlueprint::query()
                ->where('task_id', $task->id)
                ->where('status', 'frozen')
                ->update(['status' => 'superseded']);

            $snapshot = AtlasEngineeringBlueprint::query()->create([
                'task_id' => $task->id,
                'project_id' => $task->project_id,
                'project_step_id' => $task->project_step_id,
                'status' => 'frozen',
                'version' => max(1, $version),
                'source' => 'atlas_engineering_contract',
                'contract_json' => $contract,
                'blueprint_json' => $blueprint,
                'content_hash' => $contentHash,
                'frozen_at' => now(),
            ]);

            return [
                ...$this->toArray($snapshot),
                'matches_current_content' => true,
            ];
        });
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $blueprint
     */
    public function contentHash(array $contract, array $blueprint): string
    {
        $payload = $this->sortRecursive([
            'contract' => $contract,
            'blueprint' => collect($blueprint)
                ->except(['generated_at'])
                ->all(),
        ]);
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', is_string($encoded) ? $encoded : '');
    }

    private function available(): bool
    {
        return DatabaseTableAvailability::has('atlas_engineering_blueprints');
    }

    /**
     * @return array<string,mixed>
     */
    private function toArray(AtlasEngineeringBlueprint $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'task_id' => $snapshot->task_id,
            'project_id' => $snapshot->project_id,
            'project_step_id' => $snapshot->project_step_id,
            'status' => $snapshot->status,
            'version' => $snapshot->version,
            'source' => $snapshot->source,
            'content_hash' => $snapshot->content_hash,
            'frozen_at' => $snapshot->frozen_at?->toJSON(),
            'created_at' => $snapshot->created_at?->toJSON(),
            'updated_at' => $snapshot->updated_at?->toJSON(),
        ];
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $mapped = array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);

        if (! $isList) {
            ksort($mapped);
        }

        return $mapped;
    }
}
