<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence\Support;

use App\Services\Ai\Support\AiStringListNormalizer;

/**
 * Pure execution-optimization policy maps for AWIS next-session brain:
 * prefer/block/defer/standard command lanes, scoped execution routes, and
 * validation-tier routing. No I/O, no mother, no MissionCanonicalHash.
 */
final class ExecutionOptimizationPolicySupport
{
    /**
     * Classify provider-safe command lists into preferred / standard / deferred / blocked lanes.
     *
     * @param  list<string>  $avoidCommands
     * @param  list<string>  $slowCommands
     * @param  list<string>  $flakyCommands
     * @param  list<string>  $fastCommands
     * @param  list<string>  $heavyCommands
     * @param  list<string>  $rankedCandidates
     * @return array{
     *     preferred: list<string>,
     *     standard: list<string>,
     *     deferred: list<string>,
     *     blocked: list<string>
     * }
     */
    public static function classifyCommandLanes(
        array $avoidCommands,
        array $slowCommands,
        array $flakyCommands,
        array $fastCommands,
        array $heavyCommands,
        array $rankedCandidates,
    ): array {
        $blocked = AiStringListNormalizer::uniqueTrimmedStrings(array_merge($avoidCommands, $slowCommands));
        $deferred = AiStringListNormalizer::uniqueTrimmedStrings(array_merge(
            $heavyCommands,
            $slowCommands,
            $flakyCommands,
        ));

        $preferred = array_values(array_filter(
            AiStringListNormalizer::uniqueTrimmedStrings(array_merge($fastCommands, $rankedCandidates)),
            static fn (string $command): bool => ! in_array($command, $blocked, true)
                && ($fastCommands === [] || in_array($command, $fastCommands, true)),
        ));
        if ($preferred === []) {
            $preferred = array_values(array_filter(
                $rankedCandidates,
                static fn (string $command): bool => ! in_array($command, $blocked, true)
                    && ! in_array($command, $deferred, true),
            ));
        }
        $standard = array_values(array_filter(
            $rankedCandidates,
            static fn (string $command): bool => ! in_array($command, $blocked, true)
                && ! in_array($command, $preferred, true)
                && ! in_array($command, $deferred, true),
        ));

        return [
            'preferred' => $preferred,
            'standard' => $standard,
            'deferred' => $deferred,
            'blocked' => $blocked,
        ];
    }

    /**
     * Collapse execution-policy effectiveness profiles into refs + next adjustment.
     *
     * @param  array<int,mixed>  $policyProfiles
     * @return array{
     *     effective_policy_refs: list<string>,
     *     mixed_policy_refs: list<string>,
     *     failing_policy_refs: list<string>,
     *     observed_policy_count: int,
     *     needs_tighter_policy: bool,
     *     standard_command_limit: int,
     *     next_adjustment: string,
     *     deep_requires_operator: bool
     * }
     */
    public static function policyFeedbackFromProfiles(array $policyProfiles): array
    {
        $policyProfiles = array_values(array_filter($policyProfiles, 'is_array'));
        $effectivePolicyRefs = [];
        $mixedPolicyRefs = [];
        $failingPolicyRefs = [];
        foreach ($policyProfiles as $profile) {
            $policyRef = (string) ($profile['policy_ref'] ?? '');
            if ($policyRef === '') {
                continue;
            }

            match ((string) ($profile['effectiveness'] ?? 'unknown')) {
                'effective' => $effectivePolicyRefs[] = $policyRef,
                'mixed' => $mixedPolicyRefs[] = $policyRef,
                'failing' => $failingPolicyRefs[] = $policyRef,
                default => null,
            };
        }

        $needsTighterPolicy = $failingPolicyRefs !== [] || $mixedPolicyRefs !== [];
        $standardCommandLimit = $needsTighterPolicy ? 4 : 8;
        $nextAdjustment = 'collect_policy_outcome_feedback';
        if ($needsTighterPolicy) {
            $nextAdjustment = 'tighten_default_to_preferred_fast_commands';
        } elseif ($effectivePolicyRefs !== []) {
            $nextAdjustment = 'reuse_effective_policy_shape';
        }

        return [
            'effective_policy_refs' => AiStringListNormalizer::uniqueTrimmedScalarValues($effectivePolicyRefs),
            'mixed_policy_refs' => AiStringListNormalizer::uniqueTrimmedScalarValues($mixedPolicyRefs),
            'failing_policy_refs' => AiStringListNormalizer::uniqueTrimmedScalarValues($failingPolicyRefs),
            'observed_policy_count' => count($policyProfiles),
            'needs_tighter_policy' => $needsTighterPolicy,
            'standard_command_limit' => $standardCommandLimit,
            'next_adjustment' => $nextAdjustment,
            'deep_requires_operator' => true,
        ];
    }

    /**
     * Static validation-tier definition map (instant / standard / deep).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function validationTierDefinitions(bool $deepRequiresOperator = true): array
    {
        return [
            'instant' => [
                'max_command_count' => 2,
                'prefer_performance_grade' => 'fast',
                'max_expected_duration_ms' => 60_000,
                'requires_effective_or_fast_route' => true,
            ],
            'standard' => [
                'max_command_count' => 4,
                'allow_performance_grades' => ['fast', 'normal', 'heavy'],
                'max_expected_duration_ms' => 300_000,
                'default_for_unknown_routes' => true,
            ],
            'deep' => [
                'requires_operator_or_high_risk_context' => $deepRequiresOperator,
                'allow_deferred_commands' => true,
                'max_expected_duration_ms' => 900_000,
                'required_for_mixed_or_failing_routes' => true,
            ],
        ];
    }

    /**
     * @param  array<int,mixed>  $profiles
     * @param  array<int,string>  $blocked
     * @param  array<int,string>  $deferred
     * @param  array<string,string>  $routeFeedback
     * @param  array<string,string>  $tierFeedback
     * @return array<int,array<string,mixed>>
     */
    public static function scopedExecutionRoutes(
        array $profiles,
        array $blocked,
        array $deferred,
        array $routeFeedback,
        array $tierFeedback,
        string $routeKind,
    ): array {
        $routes = [];
        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            $key = trim((string) ($profile['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $commands = AiStringListNormalizer::uniqueSingleLineStrings($profile['commands'] ?? []);
            $blockedCommands = array_values(array_intersect($commands, $blocked));
            $deferredCommands = array_values(array_diff(array_intersect($commands, $deferred), $blockedCommands));
            $preferredCommands = array_values(array_diff($commands, $blockedCommands, $deferredCommands));
            $routeRef = $routeKind.':'.hash('sha256', $key);
            $feedbackEffectiveness = $routeFeedback[$routeRef] ?? null;
            if (in_array($feedbackEffectiveness, ['mixed', 'failing'], true)) {
                $deferredCommands = AiStringListNormalizer::uniqueTrimmedStrings(array_merge($deferredCommands, $preferredCommands));
                $preferredCommands = [];
            }
            $routeMode = $preferredCommands !== []
                ? 'prefer_scope_commands'
                : ($deferredCommands !== [] || $blockedCommands !== [] ? 'deep_validation_only' : 'observe_more');
            $validationTier = self::validationTierForExecutionRoute(
                $feedbackEffectiveness ?? 'unknown',
                (string) ($profile['performance_grade'] ?? 'unknown'),
                $routeMode,
                $preferredCommands,
                $deferredCommands,
                $blockedCommands,
                $tierFeedback,
            );
            $routes[] = [
                'key' => $key,
                'route_ref' => $routeRef,
                'observed_count' => (int) ($profile['observed_count'] ?? 0),
                'performance_grade' => (string) ($profile['performance_grade'] ?? 'unknown'),
                'duration_ms_p95' => is_numeric($profile['duration_ms_p95'] ?? null) ? (int) $profile['duration_ms_p95'] : null,
                'preferred_commands' => array_slice($preferredCommands, 0, 4),
                'deferred_commands' => array_slice($deferredCommands, 0, 4),
                'blocked_commands' => array_slice($blockedCommands, 0, 4),
                'feedback_effectiveness' => $feedbackEffectiveness ?? 'unknown',
                'route_mode' => $routeMode,
                'recommended_validation_tier' => $validationTier['tier'],
                'validation_reason' => $validationTier['reason'],
            ];
        }

        return array_slice($routes, 0, 8);
    }

    /**
     * @param  array<int,string>  $preferredCommands
     * @param  array<int,string>  $deferredCommands
     * @param  array<int,string>  $blockedCommands
     * @param  array<string,string>  $tierFeedback
     * @return array{tier:string,reason:string}
     */
    public static function validationTierForExecutionRoute(
        string $feedbackEffectiveness,
        string $performanceGrade,
        string $routeMode,
        array $preferredCommands,
        array $deferredCommands,
        array $blockedCommands,
        array $tierFeedback = [],
    ): array {
        if (in_array($feedbackEffectiveness, ['mixed', 'failing'], true)) {
            return ['tier' => 'deep', 'reason' => 'route_feedback_requires_guarded_validation'];
        }

        if ($routeMode === 'deep_validation_only' || $blockedCommands !== []) {
            return ['tier' => 'deep', 'reason' => 'scope_contains_blocked_or_slow_commands'];
        }

        if ($feedbackEffectiveness === 'effective' && $performanceGrade === 'fast' && $preferredCommands !== []) {
            if (in_array(($tierFeedback['tier:instant'] ?? 'unknown'), ['mixed', 'failing'], true)) {
                return ['tier' => 'standard', 'reason' => 'instant_tier_feedback_guarded'];
            }

            return ['tier' => 'instant', 'reason' => 'effective_fast_scope_route'];
        }

        if ($preferredCommands !== [] && $deferredCommands === []) {
            return ['tier' => 'standard', 'reason' => 'scope_has_stable_preferred_commands'];
        }

        return ['tier' => 'standard', 'reason' => 'observe_route_until_feedback_is_stronger'];
    }

    /**
     * @param  array<int,array<string,mixed>>  $areaRoutes
     * @param  array<int,array<string,mixed>>  $stackRoutes
     * @param  array<string,string>  $tierFeedback
     * @return array<string,mixed>
     */
    public static function validationTierRoutingSummary(
        array $areaRoutes,
        array $stackRoutes,
        array $tierFeedback = [],
    ): array {
        $routes = array_merge($areaRoutes, $stackRoutes);
        $tierCounts = ['instant' => 0, 'standard' => 0, 'deep' => 0];
        foreach ($routes as $route) {
            $tier = (string) ($route['recommended_validation_tier'] ?? 'standard');
            if (! array_key_exists($tier, $tierCounts)) {
                $tier = 'standard';
            }
            $tierCounts[$tier]++;
        }

        return [
            'schema_version' => 'atlas.awis.validation_tier_routing.v1',
            'mode' => 'route_and_risk_aware_validation_depth',
            'default_tier' => 'standard',
            'instant_route_count' => $tierCounts['instant'],
            'standard_route_count' => $tierCounts['standard'],
            'deep_route_count' => $tierCounts['deep'],
            'route_count' => count($routes),
            'tier_feedback' => [
                'enabled' => true,
                'instant_effectiveness' => $tierFeedback['tier:instant'] ?? 'unknown',
                'standard_effectiveness' => $tierFeedback['tier:standard'] ?? 'unknown',
                'deep_effectiveness' => $tierFeedback['tier:deep'] ?? 'unknown',
                'instant_guarded' => in_array(($tierFeedback['tier:instant'] ?? 'unknown'), ['mixed', 'failing'], true),
                'raw_logs_returned' => false,
            ],
            'selection_policy' => [
                'effective_fast_routes_use_instant_validation' => true,
                'unknown_routes_use_standard_validation' => true,
                'mixed_or_failing_routes_use_deep_validation' => true,
                'raw_logs_returned' => false,
            ],
            'raw_logs_returned' => false,
        ];
    }

    /**
     * @param  array<int,mixed>  $profiles
     * @return array<string,string>
     */
    public static function routeEffectivenessFeedback(array $profiles, string $routeKind): array
    {
        $feedback = [];
        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            $routeRef = (string) ($profile['route_ref'] ?? '');
            if (! str_starts_with($routeRef, $routeKind.':')) {
                continue;
            }
            $effectiveness = (string) ($profile['effectiveness'] ?? 'unknown');
            if (in_array($effectiveness, ['effective', 'mixed', 'failing'], true)) {
                $feedback[$routeRef] = $effectiveness;
            }
        }

        return $feedback;
    }

    /**
     * @param  array<int,mixed>  $profiles
     * @return array<string,string>
     */
    public static function validationTierEffectivenessFeedback(array $profiles): array
    {
        $feedback = [];
        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            $tierRef = (string) ($profile['tier_ref'] ?? '');
            if (preg_match('/^tier:(instant|standard|deep)$/', $tierRef) !== 1) {
                continue;
            }
            $effectiveness = (string) ($profile['effectiveness'] ?? 'unknown');
            if (in_array($effectiveness, ['effective', 'mixed', 'failing'], true)) {
                $feedback[$tierRef] = $effectiveness;
            }
        }

        return $feedback;
    }
}
