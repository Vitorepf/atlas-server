<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure mapper that converts model-amplifier weakness evidence into concrete
 * repair or routing tasks that improve future output quality.
 *
 * Weakness → Task family mapping:
 *   - weak_output → evidence_strengthening (strengthen evidence requirements)
 *   - context_loss → context_preservation (preserve context across rounds)
 *   - overconfident_green → green_verification (verify green claims)
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainModelAmplifierWeaknessToTaskMapper
{
    public const SCHEMA = 'atlas.external_brain.model_amplifier_weakness_to_task_mapper.v1';

    private const WEAKNESS_TO_TASK = [
        'weak_output' => 'evidence_strengthening',
        'context_loss' => 'context_preservation',
        'overconfident_green' => 'green_verification',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $weaknesses
     * @return array<string, mixed>
     */
    public function map(array $weaknesses): array
    {
        $tasks = [];

        foreach ($weaknesses as $weakness) {
            if (! is_array($weakness)) {
                continue;
            }

            $type = strtolower(trim((string) ($weakness['type'] ?? '')));
            $evidence = (string) ($weakness['evidence'] ?? '');
            $sourceTaskId = (string) ($weakness['source_task_id'] ?? '');

            $taskFamily = self::WEAKNESS_TO_TASK[$type] ?? null;

            if ($taskFamily !== null) {
                $tasks[] = [
                    'task_family' => $taskFamily,
                    'weakness_type' => $type,
                    'evidence' => $evidence,
                    'source_task_id' => $sourceTaskId,
                    'action' => 'repair',
                ];
            }
        }

        // Deduplicate by task_family + weakness_type.
        $seen = [];
        $deduped = [];
        foreach ($tasks as $task) {
            $key = $task['task_family'].':'.$task['weakness_type'];
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $deduped[] = $task;
            }
        }

        // Sort deterministically.
        usort($deduped, static function (array $a, array $b): int {
            return strcmp($a['task_family'], $b['task_family'])
                ?: strcmp($a['weakness_type'], $b['weakness_type']);
        });

        return [
            'schema_version' => self::SCHEMA,
            'tasks' => $deduped,
            'total_tasks' => count($deduped),
            'task_families' => array_values(array_unique(array_column($deduped, 'task_family'))),
        ];
    }
}
