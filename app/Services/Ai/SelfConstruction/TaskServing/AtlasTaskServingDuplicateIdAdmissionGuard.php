<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

/**
 * Blocks batch enqueue specs that reuse the same task_packet_id with different
 * task content before any partial enqueue can occur.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasTaskServingDuplicateIdAdmissionGuard
{
    public const SCHEMA = 'atlas.self_construction.task_serving.duplicate_id_admission_guard.v1';

    public const VERDICT_ADMIT = 'admit';
    public const VERDICT_REJECT = 'reject';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function admit(array $input): array
    {
        $specs = is_array($input['specs'] ?? null) ? $input['specs'] : [];

        $byId = [];
        $duplicates = [];
        $admitted = [];

        foreach ($specs as $spec) {
            if (! is_array($spec)) {
                continue;
            }

            $id = (string) ($spec['task_packet_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $hash = $this->hash($spec);

            if (isset($byId[$id])) {
                if ($byId[$id]['hash'] !== $hash) {
                    $duplicates[] = [
                        'task_packet_id' => $id,
                        'reason' => 'duplicate_id_with_different_content',
                        'first_hash' => $byId[$id]['hash'],
                        'second_hash' => $hash,
                    ];
                }

                continue;
            }

            $byId[$id] = ['hash' => $hash, 'spec' => $spec];
            $admitted[] = $spec;
        }

        $verdict = $duplicates === [] ? self::VERDICT_ADMIT : self::VERDICT_REJECT;

        return [
            'schema_version' => self::SCHEMA,
            'verdict' => $verdict,
            'admitted_specs' => $admitted,
            'admitted_count' => count($admitted),
            'duplicate_violations' => $duplicates,
            'duplicate_count' => count($duplicates),
            'blocks_enqueue' => $verdict === self::VERDICT_REJECT,
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function hash(array $spec): string
    {
        $canonical = [
            'objective' => (string) ($spec['objective'] ?? ''),
            'allowed_files' => array_values((array) ($spec['allowed_files'] ?? [])),
            'acceptance_criteria' => array_values((array) ($spec['acceptance_criteria'] ?? [])),
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
