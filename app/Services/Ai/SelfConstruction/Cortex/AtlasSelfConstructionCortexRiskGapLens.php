<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Cortex;

/**
 * Self-Construction Cortex LENS. Projects RISK and MISSING-CONTEXT gaps from existing facts so that
 * Strategy + Control Plane can reason over them. Does NOT decide priority and does NOT create tasks.
 *
 * INPUT FACTS:
 *   { source_inventory:{blockers:list<string>},
 *     queue_health:{malformed_count?:int, repeated_give_back_count?:int},
 *     sweep_health:{coverage_unknown?:bool},
 *     verification:{server_side_green?:bool},
 *     merge:{posture?:string},   // 'safe' | 'unsafe' | 'unknown'
 *     knowledge_sync:{conformant?:bool, blockers?:list<string>} }
 *
 * EMITTED RISK GAP CLASSES (every match becomes a row):
 *   stale_context          — source_inventory.blockers contains a 'missing_required_source:*' or any *stale*
 *   missing_receipts       — verification.server_side_green=false OR knowledge_sync.conformant=false
 *   unsafe_merge_posture   — merge.posture != 'safe'
 *   malformed_queue        — queue_health.malformed_count > 0
 *   unproved_runtime_path  — sweep_health.coverage_unknown=true OR queue_health.repeated_give_back_count >= 3
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope (gaps sorted by class).
 *   - NO scalar score / rank.
 *   - PURE — caller decides what to do; this lens only reports.
 */
final class AtlasSelfConstructionCortexRiskGapLens
{
    public const SCHEMA = 'atlas.cortex.risk_gap_lens.v1';

    public const GAP_STALE_CONTEXT = 'stale_context';

    public const GAP_MISSING_RECEIPTS = 'missing_receipts';

    public const GAP_UNSAFE_MERGE = 'unsafe_merge_posture';

    public const GAP_MALFORMED_QUEUE = 'malformed_queue';

    public const GAP_UNPROVED_RUNTIME = 'unproved_runtime_path';

    /**
     * @param  array<string,array<string,mixed>>  $facts
     * @return array{schema:string, gaps:list<array{class:string, evidence:array<string,mixed>}>}
     */
    public function project(array $facts): array
    {
        $gaps = [];

        $inventory = is_array($facts['source_inventory'] ?? null) ? $facts['source_inventory'] : [];
        $invBlockersRaw = is_array($inventory['blockers'] ?? null) ? array_map('strval', $inventory['blockers']) : [];
        $invBlockers = array_values(array_unique(array_filter(
            array_map('trim', $invBlockersRaw),
            static fn (string $b): bool => $b !== '',
        )));
        sort($invBlockers, SORT_STRING);
        $hasStale = false;
        foreach ($invBlockers as $b) {
            if (str_contains($b, 'missing_required_source') || str_contains($b, 'stale')) {
                $hasStale = true;
                break;
            }
        }
        if ($hasStale) {
            $gaps[] = ['class' => self::GAP_STALE_CONTEXT, 'evidence' => ['inventory_blockers' => $invBlockers]];
        }

        $verification = is_array($facts['verification'] ?? null) ? $facts['verification'] : [];
        $knowledgeSync = is_array($facts['knowledge_sync'] ?? null) ? $facts['knowledge_sync'] : [];
        $missingReceipts = (isset($verification['server_side_green']) && ! (bool) $verification['server_side_green'])
            || (isset($knowledgeSync['conformant']) && ! (bool) $knowledgeSync['conformant']);
        if ($missingReceipts) {
            $gaps[] = ['class' => self::GAP_MISSING_RECEIPTS, 'evidence' => [
                'server_side_green' => (bool) ($verification['server_side_green'] ?? false),
                'knowledge_sync_conformant' => (bool) ($knowledgeSync['conformant'] ?? false),
                'knowledge_sync_blockers' => array_values((array) ($knowledgeSync['blockers'] ?? [])),
            ]];
        }

        $merge = is_array($facts['merge'] ?? null) ? $facts['merge'] : [];
        $posture = (string) ($merge['posture'] ?? '');
        if ($posture !== '' && $posture !== 'safe') {
            $gaps[] = ['class' => self::GAP_UNSAFE_MERGE, 'evidence' => ['posture' => $posture]];
        }

        $queueHealth = is_array($facts['queue_health'] ?? null) ? $facts['queue_health'] : [];
        $malformed = max(0, (int) ($queueHealth['malformed_count'] ?? 0));
        if ($malformed > 0) {
            $gaps[] = ['class' => self::GAP_MALFORMED_QUEUE, 'evidence' => ['malformed_count' => $malformed]];
        }

        $sweep = is_array($facts['sweep_health'] ?? null) ? $facts['sweep_health'] : [];
        $gbCount = max(0, (int) ($queueHealth['repeated_give_back_count'] ?? 0));
        $unproved = (bool) ($sweep['coverage_unknown'] ?? false) || $gbCount >= 3;
        if ($unproved) {
            $gaps[] = ['class' => self::GAP_UNPROVED_RUNTIME, 'evidence' => ['coverage_unknown' => (bool) ($sweep['coverage_unknown'] ?? false), 'repeated_give_back_count' => $gbCount]];
        }

        usort($gaps, static fn (array $a, array $b): int => strcmp($a['class'], $b['class']));

        return [
            'schema' => self::SCHEMA,
            'gaps' => $gaps,
        ];
    }
}
