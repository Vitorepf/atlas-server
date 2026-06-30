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
