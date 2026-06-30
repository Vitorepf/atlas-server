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
 * NO process execution, NO filesystem, NO providers. DETERMINISTIC.
 */
final class AtlasMaestroForbiddenTargetUnblockDossier
{
    public const SCHEMA = 'atlas.maestro.health.forbidden_target_unblock_dossier.v1';

    private const CORE_KEYWORDS = ['Brain', 'Gateway', 'Harness', 'Core', 'Immune'];

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

        return [
            'schema_version'          => self::SCHEMA,
            'task_id'                 => $taskId,
            'forbidden_paths'         => $forbiddenPaths,
            'severity'                => $severity,
            'requires_operator_review' => $requiresReview,
            'unblock_action'          => $unblockAction,
            'respec_recommendation'   => $respecRecommendation,
            'diagnostics' => [
                'forbidden_path_count' => count($forbiddenPaths),
                'is_self_target'       => $isSelfTarget,
                'touches_core_system'  => $isCoreTarget,
                'has_allowed_paths'    => $safeAllowed !== [],
                'failure_reason'       => $failureReason,
            ],
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
}
