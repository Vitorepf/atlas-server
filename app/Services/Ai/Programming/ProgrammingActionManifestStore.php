<?php

namespace App\Services\Ai\Programming;

use App\Models\AtlasProgrammingActionManifest;
use App\Services\Ai\Support\DatabaseTableAvailability;

class ProgrammingActionManifestStore
{
    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    public function persist(array $manifest): array
    {
        if (! $this->storageAvailable()) {
            return array_merge($manifest, [
                'storage' => [
                    'persisted' => false,
                    'reason' => 'atlas_programming_action_manifests_table_missing',
                ],
            ]);
        }

        $payloadHash = hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $model = AtlasProgrammingActionManifest::query()->updateOrCreate(
            ['action_id' => (string) ($manifest['action_id'] ?? '')],
            [
                'plan_id' => is_string($manifest['plan_id'] ?? null) ? $manifest['plan_id'] : null,
                'stage' => (string) ($manifest['stage'] ?? 'tool'),
                'tool' => (string) ($manifest['tool'] ?? 'unknown'),
                'programming_tool' => (bool) ($manifest['programming_tool'] ?? false),
                'permission_mode' => (string) ($manifest['permission_mode'] ?? 'read'),
                'dry_run' => (bool) ($manifest['dry_run'] ?? false),
                'gate_effect' => (string) ($manifest['gate_effect'] ?? 'not_applicable'),
                'next_action' => (string) ($manifest['next_action'] ?? 'continue'),
                'changed_files_json' => array_values(array_filter((array) ($manifest['changed_files'] ?? []), 'is_string')),
                'rollback_json' => (array) ($manifest['rollback'] ?? []),
                'inputs_json' => (array) ($manifest['inputs'] ?? []),
                'outputs_json' => (array) ($manifest['outputs'] ?? []),
                'payload_json' => $manifest,
                'payload_hash' => $payloadHash,
            ],
        );

        return array_merge($manifest, [
            'storage' => [
                'persisted' => true,
                'model_id' => $model->id,
                'table' => 'atlas_programming_action_manifests',
                'payload_hash' => $payloadHash,
            ],
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function forPlan(string $planId): array
    {
        if (! $this->storageAvailable()) {
            return [];
        }

        return AtlasProgrammingActionManifest::query()
            ->where('plan_id', $planId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (AtlasProgrammingActionManifest $manifest): array => array_merge(
                $manifest->payload_json ?? [],
                [
                    'storage' => [
                        'persisted' => true,
                        'model_id' => $manifest->id,
                        'table' => 'atlas_programming_action_manifests',
                        'payload_hash' => $manifest->payload_hash,
                    ],
                ],
            ))
            ->values()
            ->all();
    }

    private function storageAvailable(): bool
    {
        return DatabaseTableAvailability::has('atlas_programming_action_manifests');
    }
}
