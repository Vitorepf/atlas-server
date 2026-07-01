<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Compiles 95-percent maturity-gap blockers into ordered, muscle-ready task chains instead of
 * loose one-off tasks. Context proof always precedes gate hardening, and runtime integration
 * always depends on both — the fixed blocker order below encodes that dependency shape. Two
 * gaps sharing the same `unblocker_id` collapse into ONE task node that both chains point to,
 * so a shared unblocker is never duplicated across gaps.
 *
 * Input shape:
 *   { gaps: list<{ gap_id:string, blockers: list<{
 *       type: 'missing_context'|'weak_gate'|'no_runtime_integration',
 *       unblocker_id?: string,
 *       allowed_files_hint?: list<string>,
 *       expected_delta?: string,
 *       acceptance_strength?: string,
 *   }> }> }
 *
 * Pure / deterministic. No I/O — callers persist/act on the returned chain.
 */
final class AtlasExternalBrainCapabilityGapTaskChainCompiler
{
    public const SCHEMA = 'atlas.self_construction.external_brain.capability_gap_task_chain_compiler.v1';

    /** Fixed dependency order: context proof before gate hardening before runtime integration. */
    private const BLOCKER_ORDER = [
        'missing_context' => 0,
        'weak_gate' => 1,
        'no_runtime_integration' => 2,
    ];

    private const BLOCKER_DEFAULT_ACCEPTANCE = [
        'missing_context' => 'context_proof_required',
        'weak_gate' => 'gate_hardening_proof_required',
        'no_runtime_integration' => 'runtime_integration_proof_required',
    ];

    private const BLOCKER_DEFAULT_EXPECTED_DELTA = [
        'missing_context' => 'closes the missing-context evidence gap for this capability',
        'weak_gate' => 'hardens the acceptance gate so it can no longer be satisfied by proxy evidence',
        'no_runtime_integration' => 'wires the capability into a real, invoked production call path',
    ];

    /**
     * @param  array{gaps?: list<array{gap_id?: string, blockers?: list<array<string,mixed>>}>}  $facts
     * @return array{schema:string, chain:list<array<string,mixed>>, gap_chains:array<string,list<string>>}
     */
    public function compile(array $facts): array
    {
        $chain = [];
        $gapChains = [];
        $taskIdByUnblockerKey = [];
        $chainIndexByUnblockerKey = [];

        foreach ((array) ($facts['gaps'] ?? []) as $gap) {
            if (! is_array($gap)) {
                continue;
            }
            $gapId = trim((string) ($gap['gap_id'] ?? ''));
            if ($gapId === '') {
                continue;
            }

            $blockers = array_values(array_filter(
                (array) ($gap['blockers'] ?? []),
                static fn ($b): bool => is_array($b) && array_key_exists('type', $b) && array_key_exists((string) $b['type'], self::BLOCKER_ORDER),
            ));
            usort($blockers, static fn (array $a, array $b): int => self::BLOCKER_ORDER[$a['type']] <=> self::BLOCKER_ORDER[$b['type']]);

            $gapTaskIds = [];
            $previousTaskId = null;

            foreach ($blockers as $blocker) {
                $type = (string) $blocker['type'];
                $unblockerId = trim((string) ($blocker['unblocker_id'] ?? ''));
                $unblockerKey = $unblockerId !== '' ? $unblockerId : $gapId.':'.$type;

                if (! array_key_exists($unblockerKey, $taskIdByUnblockerKey)) {
                    $taskId = $unblockerKey;
                    $taskIdByUnblockerKey[$unblockerKey] = $taskId;
                    $chainIndexByUnblockerKey[$unblockerKey] = count($chain);

                    $chain[] = [
                        'task_id' => $taskId,
                        'gap_id' => $gapId,
                        'gap_ids' => [$gapId],
                        'blocker_type' => $type,
                        'dependency_ids' => $previousTaskId !== null ? [$previousTaskId] : [],
                        'allowed_files_hint' => array_values((array) ($blocker['allowed_files_hint'] ?? [])),
                        'acceptance_strength' => (string) ($blocker['acceptance_strength'] ?? self::BLOCKER_DEFAULT_ACCEPTANCE[$type]),
                        'expected_delta' => (string) ($blocker['expected_delta'] ?? self::BLOCKER_DEFAULT_EXPECTED_DELTA[$type]),
                    ];
                } else {
                    // Shared unblocker: another gap references the same node — record it without duplicating the node.
                    $idx = $chainIndexByUnblockerKey[$unblockerKey];
                    if (! in_array($gapId, $chain[$idx]['gap_ids'], true)) {
                        $chain[$idx]['gap_ids'][] = $gapId;
                    }
                }

                $taskId = $taskIdByUnblockerKey[$unblockerKey];
                $gapTaskIds[] = $taskId;
                $previousTaskId = $taskId;
            }

            $gapChains[$gapId] = $gapTaskIds;
        }

        return [
            'schema' => self::SCHEMA,
            'chain' => $chain,
            'gap_chains' => $gapChains,
        ];
    }
}
