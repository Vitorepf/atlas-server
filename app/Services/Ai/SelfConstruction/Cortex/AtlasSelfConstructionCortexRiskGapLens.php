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
            $ev = ['inventory_blockers' => $invBlockers];
            $gaps[] = ['class' => self::GAP_STALE_CONTEXT, 'evidence' => $ev, 'originator_hints' => $this->hints(self::GAP_STALE_CONTEXT, $ev)];
        }

        $verification = is_array($facts['verification'] ?? null) ? $facts['verification'] : [];
        $knowledgeSync = is_array($facts['knowledge_sync'] ?? null) ? $facts['knowledge_sync'] : [];
        $missingReceipts = (isset($verification['server_side_green']) && ! (bool) $verification['server_side_green'])
            || (isset($knowledgeSync['conformant']) && ! (bool) $knowledgeSync['conformant']);
        if ($missingReceipts) {
            $ev = [
                'server_side_green'        => (bool) ($verification['server_side_green'] ?? false),
                'knowledge_sync_conformant' => (bool) ($knowledgeSync['conformant'] ?? false),
                'knowledge_sync_blockers'   => array_values((array) ($knowledgeSync['blockers'] ?? [])),
            ];
            $gaps[] = ['class' => self::GAP_MISSING_RECEIPTS, 'evidence' => $ev, 'originator_hints' => $this->hints(self::GAP_MISSING_RECEIPTS, $ev)];
        }

        $merge = is_array($facts['merge'] ?? null) ? $facts['merge'] : [];
        $posture = (string) ($merge['posture'] ?? '');
        if ($posture !== '' && $posture !== 'safe') {
            $ev = ['posture' => $posture];
            $gaps[] = ['class' => self::GAP_UNSAFE_MERGE, 'evidence' => $ev, 'originator_hints' => $this->hints(self::GAP_UNSAFE_MERGE, $ev)];
        }

        $queueHealth = is_array($facts['queue_health'] ?? null) ? $facts['queue_health'] : [];
        $malformed = max(0, (int) ($queueHealth['malformed_count'] ?? 0));
        if ($malformed > 0) {
            $ev = ['malformed_count' => $malformed];
            $gaps[] = ['class' => self::GAP_MALFORMED_QUEUE, 'evidence' => $ev, 'originator_hints' => $this->hints(self::GAP_MALFORMED_QUEUE, $ev)];
        }

        $sweep = is_array($facts['sweep_health'] ?? null) ? $facts['sweep_health'] : [];
        $gbCount = max(0, (int) ($queueHealth['repeated_give_back_count'] ?? 0));
        $unproved = (bool) ($sweep['coverage_unknown'] ?? false) || $gbCount >= 3;
        if ($unproved) {
            $ev = ['coverage_unknown' => (bool) ($sweep['coverage_unknown'] ?? false), 'repeated_give_back_count' => $gbCount];
            $gaps[] = ['class' => self::GAP_UNPROVED_RUNTIME, 'evidence' => $ev, 'originator_hints' => $this->hints(self::GAP_UNPROVED_RUNTIME, $ev)];
        }

        usort($gaps, static fn (array $a, array $b): int => strcmp($a['class'], $b['class']));

        return [
            'schema' => self::SCHEMA,
            'gaps'   => $gaps,
        ];
    }

    /**
     * Build task-origination hints for one gap class.
     * Derived from the class name and the triggering evidence — no external state invented.
     *
     * @param  array<string,mixed>  $evidence
     * @return array{suggested_lane:string, required_evidence:string, likely_owner_organ:string, avoid_proxy_warning:string}
     */
    private function hints(string $class, array $evidence): array
    {
        return match ($class) {
            self::GAP_STALE_CONTEXT => [
                'suggested_lane'      => 'source_refresh',
                'required_evidence'   => 'knowledge_sync_conformant:true',
                'likely_owner_organ'  => 'memory',
                'avoid_proxy_warning' => 'filing_task_count_does_not_fix_staleness',
            ],
            self::GAP_MISSING_RECEIPTS => [
                'suggested_lane'      => 'verification_closure',
                'required_evidence'   => 'server_side_green:true',
                'likely_owner_organ'  => 'certification',
                'avoid_proxy_warning' => 'green_self_report_is_not_a_receipt',
            ],
            self::GAP_UNSAFE_MERGE => [
                'suggested_lane'      => 'merge_safety_repair',
                'required_evidence'   => 'merge_posture:safe',
                'likely_owner_organ'  => 'governance',
                'avoid_proxy_warning' => 'do_not_merge_without_cert_gate_passage',
            ],
            self::GAP_MALFORMED_QUEUE => [
                'suggested_lane'      => 'queue_repair',
                'required_evidence'   => 'malformed_count:0',
                'likely_owner_organ'  => 'task_queue',
                'avoid_proxy_warning' => 'creating_new_tasks_does_not_repair_malformed_existing_tasks',
            ],
            self::GAP_UNPROVED_RUNTIME => [
                'suggested_lane'      => 'runtime_coverage',
                'required_evidence'   => 'coverage_unknown:false AND repeated_give_back_count:lt_3',
                'likely_owner_organ'  => 'runtime_health',
                'avoid_proxy_warning' => 'adding_test_count_without_addressing_coverage_gap_is_proxy',
            ],
            default => [
                'suggested_lane'      => 'general_repair',
                'required_evidence'   => 'gap_class_resolved:'.$class,
                'likely_owner_organ'  => 'cortex',
                'avoid_proxy_warning' => 'task_volume_is_not_evidence_of_gap_closure',
            ],
        };
    }
}
