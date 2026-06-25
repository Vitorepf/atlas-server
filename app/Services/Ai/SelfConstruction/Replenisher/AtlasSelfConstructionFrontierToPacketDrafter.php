<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Replenisher;

use RuntimeException;

/**
 * Converts NORMALIZED FRONTIER CONTRACTS into Agent Control Plane TASK PACKET input arrays.
 *
 * OUTPUT (per packet):
 *   { objective, scope_in:list<string>, allowed_files:list<string>,
 *     acceptance_criteria:list<string>, required_evidence:list<string>,
 *     depends_on:list<string>, wave:int, autonomy_contract:array<string,mixed>,
 *     suppression_key:string }
 *
 * INVARIANTS:
 *   - NEVER invents file paths beyond the frontier's allowed_file_candidates.
 *   - NEVER emits bare directories (rejects entries without basename '.').
 *   - REJECTS (throws RuntimeException) frontiers with empty evidence_obligations.
 *   - DETERMINISTIC suppression_key = sha256(frontier_id + '|' + sorted allowed_files + '|' +
 *     sorted acceptance_obligations).
 */
final class AtlasSelfConstructionFrontierToPacketDrafter
{
    public const SCHEMA = 'atlas.replenisher.packet_draft.v1';

    public const DEFAULT_AUTONOMY_CONTRACT = [
        'runtime_owner' => 'atlas_native',
        'execution_topology' => 'shared_local_main_with_scope_lock',
        'provider_prompt' => null,
    ];

    /**
     * @param  list<array<string,mixed>>  $frontiers  output of normalize() — only accepted entries
     * @param  array<string,int>          $waveByFrontierId   optional wave assignment (defaults to 1)
     * @param  array<string,list<string>> $dependsByFrontierId optional dependency mapping
     * @return list<array<string,mixed>>
     */
    public function draft(array $frontiers, array $waveByFrontierId = [], array $dependsByFrontierId = []): array
    {
        $seenKeys = [];
        $packets = [];

        foreach ($frontiers as $f) {
            if (! is_array($f) || ! isset($f['frontier_id'])) {
                continue;
            }
            $id = (string) $f['frontier_id'];
            $allowed = is_array($f['allowed_file_candidates'] ?? null) ? array_values(array_map('strval', $f['allowed_file_candidates'])) : [];
            foreach ($allowed as $p) {
                if ($p === '' || str_ends_with($p, '/') || ! str_contains(basename($p), '.')) {
                    throw new RuntimeException('drafter refuses: bare_directory_or_empty_path:'.$p);
                }
            }
            $evidence = is_array($f['evidence_obligations'] ?? null) ? array_values(array_map('strval', $f['evidence_obligations'])) : [];
            if ($evidence === []) {
                throw new RuntimeException('drafter refuses: empty_evidence_obligations:'.$id);
            }
            $acceptance = is_array($f['acceptance_obligations'] ?? null) ? array_values(array_map('strval', $f['acceptance_obligations'])) : [];
            sort($allowed, SORT_STRING);
            sort($acceptance, SORT_STRING);
            $key = hash('sha256', $id.'|'.implode(',', $allowed).'|'.implode(',', $acceptance));
            if (isset($seenKeys[$key])) {
                continue; // duplicate suppressed
            }
            $seenKeys[$key] = true;

            $packets[] = [
                'objective' => sprintf('Atlas-native delivery for frontier %s (%s)', $id, (string) ($f['capability_gap'] ?? '')),
                'scope_in' => array_values(array_filter($allowed, static fn (string $p): bool => ! (str_starts_with($p, 'tests/') || str_contains($p, '/Tests/')))),
                'allowed_files' => $allowed,
                'acceptance_criteria' => $acceptance,
                'required_evidence' => $evidence,
                'depends_on' => array_values(array_map('strval', $dependsByFrontierId[$id] ?? [])),
                'wave' => $waveByFrontierId[$id] ?? 1,
                'autonomy_contract' => self::DEFAULT_AUTONOMY_CONTRACT,
                'suppression_key' => $key,
                'frontier_id' => $id,
            ];
        }

        usort($packets, static fn (array $a, array $b): int => strcmp((string) $a['frontier_id'], (string) $b['frontier_id']));

        return $packets;
    }
}
