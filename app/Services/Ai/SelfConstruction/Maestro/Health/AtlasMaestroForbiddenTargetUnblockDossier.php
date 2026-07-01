<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Health;

/**
 * Pure converter. Accepts a forbidden-self-target failure record and produces
 * a governed unblock dossier with a non-forbidden respec recommendation.
 *
 * Severity:
 *   high   — failure_reason === 'forbidden_self_target' OR forbidden path
 *             contains a core-system keyword (Brain, Gateway, Core, Harness, Immune).
 *   medium — forbidden_paths count >= 2 (and not already high).
 *   low    — otherwise.
 *
 * unblock_action priority:
 *   1. decompose_into_subtasks  — if forbidden_paths count > 3 (too many hot targets).
 *   2. respec_to_allowed_target — if allowed_paths are provided and usable.
 *   3. delegate_to_operator     — if high severity with no usable allowed_paths.
 *
 * respec_recommendation (AC2) is ALWAYS emitted and NEVER contains any of the
 * forbidden_paths. If no allowed_paths are given, a safe generic strategy is
 * suggested instead of a concrete path.
 *
 * requires_operator_review — true when severity is high.
 *
 * target_classification (per dossier / per grouped target):
 *   operator_only        — self_target or core-system path; never safely re-scopable for muscles.
 *   safely_rescopable     — not operator-only, and at least one non-forbidden allowed path exists.
 *   needs_further_scoping — not operator-only, but no allowed path was supplied yet.
 *
 * workaround_policy is a constant, always-present statement: the dossier NEVER recommends
 * bypassing forbidden-target policy and NEVER recommends a test-only packet as a workaround.
 *
 * buildGroup() groups multiple blocked packets by their forbidden target, aggregating
 * file:line evidence (task_id + path + line) and a single likely-safe unblock path per target,
 * so an operator reviewing many blocked packets sees one dossier per target, not one per packet.
 *
 * NO process execution, NO filesystem, NO providers. DETERMINISTIC.
 */
final class AtlasMaestroForbiddenTargetUnblockDossier
{
    public const SCHEMA = 'atlas.maestro.health.forbidden_target_unblock_dossier.v1';

    public const TARGET_CLASS_OPERATOR_ONLY   = 'operator_only';
    public const TARGET_CLASS_SAFELY_RESCOPABLE = 'safely_rescopable';
    public const TARGET_CLASS_NEEDS_SCOPING   = 'needs_further_scoping';

    private const CORE_KEYWORDS = ['Brain', 'Gateway', 'Harness', 'Core', 'Immune'];

    private const WORKAROUND_POLICY = [
        'bypass_forbidden_target_policy' => 'never_suggested',
        'test_only_workaround'           => 'never_suggested',
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function build(array $facts): array
    {
        $taskId           = (string) ($facts['task_id'] ?? '');
        $forbiddenPaths   = array_values(array_map('strval', (array) ($facts['forbidden_paths'] ?? [])));
        $originalObjective = (string) ($facts['original_objective'] ?? '');
        $failureReason    = (string) ($facts['failure_reason'] ?? '');
        $allowedPaths     = array_values(array_map('strval', (array) ($facts['allowed_paths'] ?? [])));

        $isSelfTarget  = $failureReason === 'forbidden_self_target';
        $isCoreTarget  = $this->touchesCoreSystem($forbiddenPaths);
        $severity      = $this->severity($isSelfTarget, $isCoreTarget, count($forbiddenPaths));
        $requiresReview = $severity === 'high';

        // Filter allowed_paths to exclude anything that overlaps forbidden_paths.
        $safeAllowed = array_values(array_filter(
            $allowedPaths,
            static fn (string $p): bool => ! in_array($p, $forbiddenPaths, true),
        ));

        $unblockAction      = $this->unblockAction(count($forbiddenPaths), $safeAllowed, $requiresReview);
        $respecRecommendation = $this->respecRecommendation($originalObjective, $safeAllowed, $forbiddenPaths);
        $targetClassification = $this->targetClassification($requiresReview, $safeAllowed);
        $lineEvidence         = (array) ($facts['forbidden_path_lines'] ?? []);

        return [
            'schema_version'          => self::SCHEMA,
            'task_id'                 => $taskId,
            'forbidden_paths'         => $forbiddenPaths,
            'severity'                => $severity,
            'requires_operator_review' => $requiresReview,
            'unblock_action'          => $unblockAction,
            'respec_recommendation'   => $respecRecommendation,
            'target_classification'   => $targetClassification,
            'evidence'                => $this->buildEvidence($taskId, $forbiddenPaths, $lineEvidence),
            'workaround_policy'       => self::WORKAROUND_POLICY,
            'diagnostics' => [
                'forbidden_path_count' => count($forbiddenPaths),
                'is_self_target'       => $isSelfTarget,
                'touches_core_system'  => $isCoreTarget,
                'has_allowed_paths'    => $safeAllowed !== [],
                'failure_reason'       => $failureReason,
            ],
        ];
    }

    /**
     * Groups multiple blocked packets by forbidden target, aggregating file:line evidence and a
     * single likely-safe unblock path per target (AC1). Each target is classified operator_only
     * or safely_rescopable (AC2/AC4), and every group still carries the constant workaround
     * refusal (AC3).
     *
     * @param  list<array<string,mixed>>  $blockedPackets  each shaped like build()'s $facts, plus optional forbidden_path_lines
     * @return array<string,mixed>
     */
    public function buildGroup(array $blockedPackets): array
    {
        $targets = [];

        foreach ($blockedPackets as $packet) {
            if (! is_array($packet)) {
                continue;
            }
            $taskId        = (string) ($packet['task_id'] ?? '');
            $failureReason = (string) ($packet['failure_reason'] ?? '');
            $isSelfTarget  = $failureReason === 'forbidden_self_target';
            $paths         = array_values(array_map('strval', (array) ($packet['forbidden_paths'] ?? [])));
            $lines         = (array) ($packet['forbidden_path_lines'] ?? []);
            $allowed       = array_values(array_map('strval', (array) ($packet['allowed_paths'] ?? [])));

            foreach ($paths as $path) {
                $targets[$path]['task_ids'][]     = $taskId;
                $targets[$path]['evidence'][]      = $this->evidenceRow($taskId, $path, $lines);
                $targets[$path]['is_self_target'] = ($targets[$path]['is_self_target'] ?? false) || $isSelfTarget;
                $reason                            = $failureReason !== '' ? $failureReason : 'unspecified';
                $targets[$path]['reasons'][$reason] = true;
                $targets[$path]['allowed_paths']   = array_values(array_unique(array_merge($targets[$path]['allowed_paths'] ?? [], $allowed)));
            }
        }

        $groups           = [];
        $operatorOnly      = [];
        $safelyRescopable  = [];

        foreach ($targets as $path => $data) {
            $isCoreTarget = $this->touchesCoreSystem([$path]);
            $isSelfTarget = (bool) ($data['is_self_target'] ?? false);
            $requiresReview = $isSelfTarget || $isCoreTarget;
            $safeAllowed  = array_values(array_filter($data['allowed_paths'] ?? [], static fn (string $p): bool => $p !== $path));
            $classification = $this->targetClassification($requiresReview, $safeAllowed);

            $groups[] = [
                'forbidden_target'          => $path,
                'task_ids'                  => array_values(array_unique($data['task_ids'] ?? [])),
                'evidence'                  => $data['evidence'] ?? [],
                'reasons'                   => array_keys($data['reasons'] ?? []),
                'target_classification'     => $classification,
                'requires_operator_review'  => $requiresReview,
                'likely_safe_unblock_path'  => $this->respecRecommendation('', $safeAllowed, [$path]),
                'workaround_policy'         => self::WORKAROUND_POLICY,
            ];

            if ($classification === self::TARGET_CLASS_OPERATOR_ONLY) {
                $operatorOnly[] = $path;
            } elseif ($classification === self::TARGET_CLASS_SAFELY_RESCOPABLE) {
                $safelyRescopable[] = $path;
            }
        }

        return [
            'schema_version'            => self::SCHEMA,
            'groups'                    => $groups,
            'operator_only_targets'     => $operatorOnly,
            'safely_rescopable_targets' => $safelyRescopable,
        ];
    }

    private function touchesCoreSystem(array $paths): bool
    {
        foreach ($paths as $path) {
            foreach (self::CORE_KEYWORDS as $kw) {
                if (str_contains($path, $kw)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function severity(bool $isSelfTarget, bool $isCoreTarget, int $forbiddenCount): string
    {
        if ($isSelfTarget || $isCoreTarget) {
            return 'high';
        }
        if ($forbiddenCount >= 2) {
            return 'medium';
        }

        return 'low';
    }

    private function unblockAction(int $forbiddenCount, array $safeAllowed, bool $requiresReview): string
    {
        if ($forbiddenCount > 3) {
            return 'decompose_into_subtasks';
        }
        if ($safeAllowed !== []) {
            return 'respec_to_allowed_target';
        }

        return 'delegate_to_operator';
    }

    /**
     * Produces a non-forbidden respec recommendation (AC2 guarantee).
     * Always references only paths outside the forbidden set.
     *
     * @param  list<string>  $safeAllowed
     * @param  list<string>  $forbiddenPaths
     * @return array<string,mixed>
     */
    private function respecRecommendation(string $objective, array $safeAllowed, array $forbiddenPaths): array
    {
        if ($safeAllowed !== []) {
            return [
                'strategy'        => 'target_allowed_interface_layer',
                'description'     => 'Respec the task to write only to allowed paths. Achieve the same capability by extending or wrapping through an allowed surface rather than modifying the forbidden target directly.',
                'suggested_paths' => array_slice($safeAllowed, 0, 3),
                'forbidden_paths_excluded' => $forbiddenPaths,
            ];
        }

        // No allowed_paths provided: generic wrapper strategy (still non-forbidden).
        return [
            'strategy'        => 'introduce_allowed_proxy_layer',
            'description'     => 'No allowed paths were supplied. Introduce a thin proxy or adapter class in a non-forbidden location that delegates to the forbidden target via its public interface, satisfying: ' . ($objective !== '' ? $objective : '(objective not specified)'),
            'suggested_paths' => [],
            'forbidden_paths_excluded' => $forbiddenPaths,
        ];
    }

    /**
     * @param  list<string>  $safeAllowed
     */
    private function targetClassification(bool $requiresReview, array $safeAllowed): string
    {
        if ($requiresReview) {
            return self::TARGET_CLASS_OPERATOR_ONLY;
        }

        return $safeAllowed !== [] ? self::TARGET_CLASS_SAFELY_RESCOPABLE : self::TARGET_CLASS_NEEDS_SCOPING;
    }

    /**
     * @param  list<string>  $forbiddenPaths
     * @param  array<string,mixed>  $lineEvidence
     * @return list<array<string,mixed>>
     */
    private function buildEvidence(string $taskId, array $forbiddenPaths, array $lineEvidence): array
    {
        return array_map(
            fn (string $path): array => $this->evidenceRow($taskId, $path, $lineEvidence),
            $forbiddenPaths,
        );
    }

    /**
     * @param  array<string,mixed>  $lineEvidence
     * @return array<string,mixed>
     */
    private function evidenceRow(string $taskId, string $path, array $lineEvidence): array
    {
        return [
            'task_id' => $taskId,
            'path'    => $path,
            'line'    => isset($lineEvidence[$path]) ? (int) $lineEvidence[$path] : null,
        ];
    }
}
