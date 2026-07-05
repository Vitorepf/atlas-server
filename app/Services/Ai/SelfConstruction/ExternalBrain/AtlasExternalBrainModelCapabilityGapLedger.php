<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Ledgers model capability gaps observed during task work so
 * originator batches target recurring weaknesses.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasExternalBrainModelCapabilityGapLedger
{
    public const SCHEMA = 'atlas.self_construction.external_brain_model_capability_gap_ledger.v1';

    /**
     * @param  array<int, array<string, mixed>>  $gaps
     * @return array<string, mixed>
     */
    public function ledger(array $gaps): array
    {
        $entries = [];

        foreach ($gaps as $gap) {
            if (! is_array($gap)) {
                continue;
            }
            $capability = (string) ($gap['capability'] ?? '');
            $evidence = (string) ($gap['evidence'] ?? '');
            $taskFamily = (string) ($gap['task_family'] ?? '');
            $freshness = (string) ($gap['freshness'] ?? 'recent');

            $key = $capability.'|'.$evidence.'|'.$taskFamily;

            $entries[$key] = [
                'capability' => $capability,
                'evidence' => $evidence,
                'task_family' => $taskFamily,
                'freshness' => $freshness,
                'occurrence_count' => ($entries[$key]['occurrence_count'] ?? 0) + 1,
            ];
        }

        $entries = array_values($entries);

        return [
            'schema' => self::SCHEMA,
            'gaps' => $entries,
            'gap_count' => count($entries),
            'capabilities' => array_values(array_unique(array_column($entries, 'capability'))),
        ];
    }
}
