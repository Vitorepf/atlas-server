<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQueue;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Discovery\Supply\AtlasLoopRefillerPayloadNormalizer;

/**
 * Scope-repair input rebuilder for the Agent Control Plane task queue orchestrator.
 *
 * Extracted from AgentControlPlaneTaskQueueOrchestrator to reduce the
 * god-class. All methods are stateless.
 */
final class AgentControlPlaneScopeRepairInputRebuilder
{
    public static function isTestPath(string $path): bool
    {
        $p = ltrim(str_replace('\\', '/', trim($path)), '/');

        return str_contains($p, '/tests/') || str_starts_with($p, 'tests/') || str_ends_with($p, 'Test.php');
    }

    /**
     * The pétreo/removed paths a reopened packet must stop demanding in its
     * acceptance (worker can't commit them; the operator wires them). Union
     * of the inspector's removed-required-targets and any pétreo allowed/forbidden
     * path on the packet — matched in acceptance text by full path or basename.
     *
     * @param  array<string, mixed>  $packet
     * @param  list<string>  $removedTargets
     * @return list<string>
     */
    public static function petreoPathsToScrub(array $packet, array $removedTargets, AtlasLoopHarnessGuard $guard): array
    {
        $forbidden = AtlasLoopRefillerPayloadNormalizer::stringList((array) data_get($packet, 'normalized_scope.forbidden_files', data_get($packet, 'forbidden_files', [])));
        $petreoForbidden = array_values(array_filter($forbidden, fn (string $p): bool => $guard->isForbiddenSelfTarget($p)));

        return array_values(array_unique(array_merge($removedTargets, $petreoForbidden)));
    }

    /**
     * Builder input from a blocked packet KEEPING its scope (allowed/forbidden),
     * optionally dropping any acceptance criterion that references one of
     * $scrubPaths (full path or basename) — the operator-wiring demand the
     * worker cannot satisfy. If scrubbing empties acceptance, a minimal
     * buildable criterion is synthesised so the reopened packet stays
     * self-sufficient.
     *
     * @param  array<string, mixed>  $packet
     * @param  list<string>  $scrubPaths
     * @return array<string, mixed>
     */
    public static function repairInputKeepingScope(array $packet, array $scrubPaths): array
    {
        $acceptance = AtlasLoopRefillerPayloadNormalizer::stringList((array) data_get($packet, 'acceptance_criteria', []));
        if ($scrubPaths !== []) {
            $needles = [];
            foreach ($scrubPaths as $p) {
                $p = trim((string) $p);
                if ($p === '') {
                    continue;
                }
                $needles[] = $p;
                $needles[] = basename($p);
            }
            $acceptance = array_values(array_filter($acceptance, function (string $line) use ($needles): bool {
                foreach ($needles as $n) {
                    if ($n !== '' && str_contains($line, $n)) {
                        return false;
                    }
                }

                return true;
            }));
        }
        if ($acceptance === []) {
            $acceptance = ['Implement the listed allowed_files with their public API and a passing unit test; do not edit any forbidden_files (the operator wires those separately).'];
        }

        $objective = trim((string) data_get($packet, 'objective', ''));
        if ($scrubPaths !== []) {
            $objective .= ' Scope reconciliation: the pétreo path(s) ['.implode(', ', $scrubPaths).'] are operator-wired, not worker scope. Implement only the buildable allowed_files + tests; do not edit the pétreo path(s).';
        }

        return [
            'task_packet_id' => (string) data_get($packet, 'task_packet_id', ''),
            'objective' => $objective,
            // The seam decision survives every rebuild (repair, scope expansion) —
            // dropping it would strip the design contract from every later stage.
            'refactor_design_spec' => (array) data_get($packet, 'refactor_design_spec', []),
            'source' => (string) data_get($packet, 'source', 'operator_intake'),
            'operator_id' => (string) data_get($packet, 'operator_id', 'operator-unknown'),
            'parent_run_id' => (string) data_get($packet, 'parent_run_id', ''),
            'allowed_files' => AtlasLoopRefillerPayloadNormalizer::stringList((array) data_get($packet, 'normalized_scope.allowed_files', data_get($packet, 'allowed_files', []))),
            'scope_in' => AtlasLoopRefillerPayloadNormalizer::stringList((array) data_get($packet, 'normalized_scope.scope_in', data_get($packet, 'scope_in', []))),
            'scope_out' => AtlasLoopRefillerPayloadNormalizer::stringList((array) data_get($packet, 'normalized_scope.scope_out', data_get($packet, 'scope_out', []))),
            'forbidden_files' => AtlasLoopRefillerPayloadNormalizer::stringList((array) data_get($packet, 'normalized_scope.forbidden_files', data_get($packet, 'forbidden_files', []))),
            'acceptance_criteria' => $acceptance,
            'required_evidence' => AtlasLoopRefillerPayloadNormalizer::stringList((array) data_get($packet, 'evidence_requirements.required', data_get($packet, 'required_evidence', []))),
            'risk_level' => (string) data_get($packet, 'risk_classification.risk_level', data_get($packet, 'risk_level', 'low')),
            'max_runtime_seconds' => (int) data_get($packet, 'cost_budget_requirements.max_runtime_seconds', data_get($packet, 'max_runtime_seconds', 3600)),
            'max_token_budget' => (int) data_get($packet, 'cost_budget_requirements.max_token_budget', data_get($packet, 'max_token_budget', 0)),
            'workspace_policy' => (array) data_get($packet, 'workspace_policy', []),
            'continuation_context' => (array) data_get($packet, 'continuation_context', []),
            'lease_ttl_seconds' => (int) data_get($packet, 'lease_requirements.lease_ttl_seconds', 1800),
            'rollback_strategy' => (string) data_get($packet, 'rollback_requirements.rollback_strategy', 'plan_only'),
        ];
    }

    /**
     * High-level scope-repair entry point: consolidates packet + inspector evidence
     * into a single rebuilt input with a deterministic next_action.
     *
     * next_action decision matrix:
     *  - give_back      : only test file(s) remain, OR the implementation target is
     *                     forbidden, OR acceptance contradicts live code (inspector
     *                     reports contradictory_acceptance).
     *  - operator_only  : surviving criteria require operator-only blockers (the
     *                     three runtime/human/provider criteria).
     *  - split_task      : multiple disjoint implementation targets with no shared
     *                     concern and inspector reports scope_too_broad.
     *  - repair_scope    : default — there are buildable implementation files and
     *                     the scope is repairable.
     *
     * @param  array<string, mixed>  $packet
     * @param  array<string, mixed>  $evidence
     *         rejected_files           : list<string>  files rejected by the guard
     *         inspector_reasons        : list<string>  reasons from quality inspector
     *         objective_symbols        : list<string>  symbols extracted from objective
     *         removed_targets          : list<string>  targets removed by scope repair
     *         forbidden_target_evidence: list<string>  evidence about forbidden targets
     * @return array<string, mixed>
     */
    public static function rebuild(array $packet, array $evidence = []): array
    {
        $allowedFiles = AtlasLoopRefillerPayloadNormalizer::stringList(
            (array) data_get($packet, 'normalized_scope.allowed_files', data_get($packet, 'allowed_files', [])),
        );
        $forbiddenFiles = AtlasLoopRefillerPayloadNormalizer::stringList(
            (array) data_get($packet, 'normalized_scope.forbidden_files', data_get($packet, 'forbidden_files', [])),
        );
        $rejectedFiles = AtlasLoopRefillerPayloadNormalizer::stringList(
            (array) ($evidence['rejected_files'] ?? []),
        );
        $inspectorReasons = AtlasLoopRefillerPayloadNormalizer::stringList(
            (array) ($evidence['inspector_reasons'] ?? []),
        );
        $objectiveSymbols = AtlasLoopRefillerPayloadNormalizer::stringList(
            (array) ($evidence['objective_symbols'] ?? []),
        );
        $removedTargets = AtlasLoopRefillerPayloadNormalizer::stringList(
            (array) ($evidence['removed_targets'] ?? []),
        );
        $forbiddenTargetEvidence = AtlasLoopRefillerPayloadNormalizer::stringList(
            (array) ($evidence['forbidden_target_evidence'] ?? []),
        );

        // Partition allowed_files into test paths and implementation candidates.
        $testPaths = array_values(array_filter(
            $allowedFiles,
            static fn (string $p): bool => self::isTestPath($p),
        ));
        $implementationCandidates = array_values(array_filter(
            $allowedFiles,
            static fn (string $p): bool => ! self::isTestPath($p),
        ));

        // Determine which implementation targets are forbidden.
        $forbiddenSet = array_flip($forbiddenFiles);
        $forbiddenImplTargets = array_values(array_filter(
            $implementationCandidates,
            static fn (string $p): bool => isset($forbiddenSet[$p]),
        ));

        // Build the inspector-reasons string for the objective reconciliation.
        $reasonsString = $inspectorReasons !== []
            ? implode('; ', $inspectorReasons)
            : '';

        // --- Decide next_action ----------------------------------------------------
        $nextAction = 'repair_scope';
        $repairImpossible = false;
        $blockedReason = null;

        // Condition 1: only test file(s) remain (no implementation candidates).
        if ($implementationCandidates === [] && $testPaths !== []) {
            $nextAction = 'give_back';
            $repairImpossible = true;
            $blockedReason = 'test_only_survivors_no_implementation_target';
        }

        // Condition 2: the implementation target is forbidden.
        if ($forbiddenImplTargets !== [] && count($forbiddenImplTargets) === count($implementationCandidates)) {
            $nextAction = 'give_back';
            $repairImpossible = true;
            $blockedReason = 'implementation_target_is_forbidden';
        }

        // Condition 3: acceptance contradicts live code.
        $reasonsLower = array_map('strtolower', $inspectorReasons);
        foreach ($reasonsLower as $reason) {
            if (str_contains($reason, 'contradictory_acceptance') || str_contains($reason, 'acceptance_contradicts')) {
                $nextAction = 'give_back';
                $repairImpossible = true;
                $blockedReason = 'acceptance_contradicts_live_code';
                break;
            }
        }

        // Condition 4: operator-only blockers in surviving criteria.
        if (! $repairImpossible) {
            foreach ($reasonsLower as $reason) {
                if (str_contains($reason, 'operator_only') || str_contains($reason, 'operator_handoff')) {
                    $nextAction = 'operator_only';
                    break;
                }
            }
        }

        // Condition 5: scope too broad → split_task.
        if (! $repairImpossible && $nextAction === 'repair_scope') {
            foreach ($reasonsLower as $reason) {
                if (str_contains($reason, 'scope_too_broad') || str_contains($reason, 'split_task')) {
                    $nextAction = 'split_task';
                    break;
                }
            }
        }

        // --- Build the rebuilt input ------------------------------------------------
        $objective = trim((string) data_get($packet, 'objective', ''));

        $evidenceNote = '';
        if ($rejectedFiles !== []) {
            $evidenceNote .= ' Rejected files: '.implode(', ', $rejectedFiles).'.';
        }
        if ($inspectorReasons !== []) {
            $evidenceNote .= ' Inspector reasons: '.$reasonsString.'.';
        }
        if ($forbiddenTargetEvidence !== []) {
            $evidenceNote .= ' Forbidden target evidence: '.implode(', ', $forbiddenTargetEvidence).'.';
        }
        if ($repairImpossible) {
            $evidenceNote .= ' REPAIR_IMPOSSIBLE: '.$blockedReason.'.';
        }

        $acceptance = AtlasLoopRefillerPayloadNormalizer::stringList(
            (array) data_get($packet, 'acceptance_criteria', []),
        );
        if ($acceptance === []) {
            $acceptance = ['Implement the listed allowed_files with their public API and a passing unit test; do not edit any forbidden_files (the operator wires those separately).'];
        }

        return [
            'task_packet_id' => (string) data_get($packet, 'task_packet_id', ''),
            'objective' => $objective.$evidenceNote,
            'refactor_design_spec' => (array) data_get($packet, 'refactor_design_spec', []),
            'source' => (string) data_get($packet, 'source', 'operator_intake'),
            'operator_id' => (string) data_get($packet, 'operator_id', 'operator-unknown'),
            'parent_run_id' => (string) data_get($packet, 'parent_run_id', ''),
            'allowed_files' => $allowedFiles,
            'scope_in' => AtlasLoopRefillerPayloadNormalizer::stringList(
                (array) data_get($packet, 'normalized_scope.scope_in', data_get($packet, 'scope_in', [])),
            ),
            'scope_out' => AtlasLoopRefillerPayloadNormalizer::stringList(
                (array) data_get($packet, 'normalized_scope.scope_out', data_get($packet, 'scope_out', [])),
            ),
            'forbidden_files' => $forbiddenFiles,
            'acceptance_criteria' => $acceptance,
            'required_evidence' => AtlasLoopRefillerPayloadNormalizer::stringList(
                (array) data_get($packet, 'evidence_requirements.required', data_get($packet, 'required_evidence', [])),
            ),
            'risk_level' => (string) data_get($packet, 'risk_classification.risk_level', data_get($packet, 'risk_level', 'low')),
            'max_runtime_seconds' => (int) data_get($packet, 'cost_budget_requirements.max_runtime_seconds', data_get($packet, 'max_runtime_seconds', 3600)),
            'max_token_budget' => (int) data_get($packet, 'cost_budget_requirements.max_token_budget', data_get($packet, 'max_token_budget', 0)),
            'workspace_policy' => (array) data_get($packet, 'workspace_policy', []),
            'continuation_context' => (array) data_get($packet, 'continuation_context', []),
            'lease_ttl_seconds' => (int) data_get($packet, 'lease_requirements.lease_ttl_seconds', 1800),
            'rollback_strategy' => (string) data_get($packet, 'rollback_requirements.rollback_strategy', 'plan_only'),
            // Evidence consolidation
            'rejected_files' => $rejectedFiles,
            'inspector_reasons' => $inspectorReasons,
            'objective_symbols' => $objectiveSymbols,
            'test_paths' => $testPaths,
            'implementation_candidates' => $implementationCandidates,
            'forbidden_target_evidence' => $forbiddenTargetEvidence,
            'removed_targets' => $removedTargets,
            // Decision
            'next_action' => $nextAction,
            'repair_impossible' => $repairImpossible,
            'repair_blocked_reason' => $blockedReason,
        ];
    }

    /**
     * @param  array<string, mixed>  $packet
     * @param  list<string>  $forbiddenAllowed
     * @return array<string, mixed>
     */
    public static function repairInputWithoutForbiddenTargets(array $packet, array $forbiddenAllowed): array
    {
        $blocked = array_fill_keys($forbiddenAllowed, true);
        $allowed = array_values(array_filter(
            AtlasLoopRefillerPayloadNormalizer::stringList((array) data_get($packet, 'normalized_scope.allowed_files', data_get($packet, 'allowed_files', []))),
            static fn (string $path): bool => ! isset($blocked[$path]),
        ));
        $scopeIn = array_values(array_filter(
            AtlasLoopRefillerPayloadNormalizer::stringList((array) data_get($packet, 'normalized_scope.scope_in', data_get($packet, 'scope_in', []))),
            static fn (string $path): bool => ! isset($blocked[$path]),
        ));
        $forbidden = array_values(array_unique(array_merge(
            AtlasLoopRefillerPayloadNormalizer::stringList((array) data_get($packet, 'normalized_scope.forbidden_files', data_get($packet, 'forbidden_files', []))),
            $forbiddenAllowed,
        )));
        sort($forbidden);

        $allTestOnly = $allowed !== [] && array_reduce(
            $allowed,
            static fn (bool $carry, string $p): bool => $carry && self::isTestPath($p),
            true,
        );

        $objective = trim((string) data_get($packet, 'objective', ''));
        $removed = implode(', ', $forbiddenAllowed);
        $repairNote = " Scope repair: {$removed} was removed from allowed_files because Atlas cannot safely commit forbidden self-targets. Implement only the remaining allowed_files and do not edit the removed path(s).";
        if ($allTestOnly) {
            $repairNote .= ' BLOCKED: only test file(s) survive after forbidden-target removal — no implementation file remains, so this packet cannot be reopened as muscle-ready. Origination must add a real implementation target before this repair can proceed.';
        }

        return [
            'task_packet_id' => (string) data_get($packet, 'task_packet_id', ''),
            'objective' => $objective.$repairNote,
            'source' => (string) data_get($packet, 'source', 'operator_intake'),
            'operator_id' => (string) data_get($packet, 'operator_id', 'operator-unknown'),
            'parent_run_id' => (string) data_get($packet, 'parent_run_id', ''),
            'allowed_files' => $allowed,
            'scope_in' => array_values(array_unique(array_merge($scopeIn, $allowed))),
            'scope_out' => AtlasLoopRefillerPayloadNormalizer::stringList((array) data_get($packet, 'normalized_scope.scope_out', data_get($packet, 'scope_out', []))),
            'forbidden_files' => $forbidden,
            'acceptance_criteria' => AtlasLoopRefillerPayloadNormalizer::stringList((array) data_get($packet, 'acceptance_criteria', [])),
            'required_evidence' => AtlasLoopRefillerPayloadNormalizer::stringList((array) data_get($packet, 'evidence_requirements.required', data_get($packet, 'required_evidence', []))),
            'risk_level' => (string) data_get($packet, 'risk_classification.risk_level', data_get($packet, 'risk_level', 'low')),
            'max_runtime_seconds' => (int) data_get($packet, 'cost_budget_requirements.max_runtime_seconds', data_get($packet, 'max_runtime_seconds', 3600)),
            'max_token_budget' => (int) data_get($packet, 'cost_budget_requirements.max_token_budget', data_get($packet, 'max_token_budget', 0)),
            'workspace_policy' => (array) data_get($packet, 'workspace_policy', []),
            'continuation_context' => (array) data_get($packet, 'continuation_context', []),
            'lease_ttl_seconds' => (int) data_get($packet, 'lease_requirements.lease_ttl_seconds', 1800),
            'rollback_strategy' => (string) data_get($packet, 'rollback_requirements.rollback_strategy', 'plan_only'),
            'repair_blocked_reason' => $allTestOnly ? 'test_only_survivors_after_forbidden_removal' : null,
        ];
    }
}
