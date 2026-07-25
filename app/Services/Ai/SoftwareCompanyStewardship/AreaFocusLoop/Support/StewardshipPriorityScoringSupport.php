<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Support;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusScopeProfileNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;

/**
 * Pure priority scoring helpers for AP-785 Stewardship Priority Engine.
 *
 * Extracted from StewardshipPriorityEngineService private pure methods.
 * No I/O, no DI, no provider calls, no clock, no filesystem.
 */
final class StewardshipPriorityScoringSupport
{
    private function __construct()
    {
    }

    private const TYPE_BASELINES = [
        'integration_lane_promotion' => ['advancement' => 100, 'robustness' => 96, 'operator_leverage' => 90, 'execution_safety' => 90, 'evidence' => 92, 'dependency_unlock' => 100, 'risk' => 5],
        'live_cycle_audit' => ['advancement' => 96, 'robustness' => 98, 'operator_leverage' => 86, 'execution_safety' => 90, 'evidence' => 98, 'dependency_unlock' => 92, 'risk' => 8],
        'truth_surface' => ['advancement' => 94, 'robustness' => 96, 'operator_leverage' => 88, 'execution_safety' => 90, 'evidence' => 96, 'dependency_unlock' => 90, 'risk' => 8],
        'owner_runtime_real_execution_bridge' => ['advancement' => 94, 'robustness' => 90, 'operator_leverage' => 92, 'execution_safety' => 76, 'evidence' => 88, 'dependency_unlock' => 90, 'risk' => 20],
        'runtime_execution_bridge' => ['advancement' => 92, 'robustness' => 88, 'operator_leverage' => 90, 'execution_safety' => 74, 'evidence' => 86, 'dependency_unlock' => 88, 'risk' => 22],
        'continuous_scheduler' => ['advancement' => 88, 'robustness' => 91, 'operator_leverage' => 86, 'execution_safety' => 74, 'evidence' => 84, 'dependency_unlock' => 84, 'risk' => 22],
        '24h_scheduler' => ['advancement' => 88, 'robustness' => 91, 'operator_leverage' => 86, 'execution_safety' => 74, 'evidence' => 84, 'dependency_unlock' => 84, 'risk' => 22],
        'product_mode_controls' => ['advancement' => 70, 'robustness' => 76, 'operator_leverage' => 72, 'execution_safety' => 88, 'evidence' => 76, 'dependency_unlock' => 55, 'risk' => 10],
        'safety_robustness_unlock' => ['advancement' => 86, 'robustness' => 94, 'operator_leverage' => 78, 'execution_safety' => 86, 'evidence' => 86, 'dependency_unlock' => 82, 'risk' => 12],
        'branch_review_packet' => ['advancement' => 80, 'robustness' => 88, 'operator_leverage' => 82, 'execution_safety' => 88, 'evidence' => 88, 'dependency_unlock' => 74, 'risk' => 12],
        'provider_routing' => ['advancement' => 72, 'robustness' => 62, 'operator_leverage' => 78, 'execution_safety' => 42, 'evidence' => 54, 'dependency_unlock' => 58, 'risk' => 42],
        'ui_cosmetic' => ['advancement' => 18, 'robustness' => 18, 'operator_leverage' => 18, 'execution_safety' => 90, 'evidence' => 36, 'dependency_unlock' => 8, 'risk' => 4],
        'doc' => ['advancement' => 42, 'robustness' => 56, 'operator_leverage' => 34, 'execution_safety' => 94, 'evidence' => 66, 'dependency_unlock' => 28, 'risk' => 4],
        'test' => ['advancement' => 66, 'robustness' => 88, 'operator_leverage' => 48, 'execution_safety' => 88, 'evidence' => 86, 'dependency_unlock' => 54, 'risk' => 6],
        'bug' => ['advancement' => 78, 'robustness' => 84, 'operator_leverage' => 58, 'execution_safety' => 70, 'evidence' => 72, 'dependency_unlock' => 56, 'risk' => 18],
        'gap' => ['advancement' => 62, 'robustness' => 62, 'operator_leverage' => 52, 'execution_safety' => 74, 'evidence' => 58, 'dependency_unlock' => 48, 'risk' => 14],
        'cleanup' => ['advancement' => 36, 'robustness' => 48, 'operator_leverage' => 32, 'execution_safety' => 74, 'evidence' => 46, 'dependency_unlock' => 22, 'risk' => 12],
    ];

    private const FACTORY_LEVERAGE_TERMS = [
        'ap786', 'ap790', 'autonomous', 'evolution', 'sandbox', 'materializer',
        'merge', 'governor', 'owner_runtime', 'senior_loop', 'provider', 'cursor',
        'dev_forge', 'priority', 'deep_finding', 'reliable24h', 'inbox', 'read_model',
        'evidence', 'worktree', 'stewardship', 'atlas_dev', 'forge',
    ];

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public static function scoreItem(array $candidate, int $index, string $scopeProfile = '', bool $hasLiveForgeAuthority = false): array
    {
        $factoryScores = self::factoryExecutionScores($candidate, $scopeProfile, $hasLiveForgeAuthority);
        if (($factoryScores['rejection_reason'] ?? '') !== '') {
            return self::rejectedFactoryRankedItem($candidate, $index, $factoryScores);
        }

        $type = self::type($candidate);
        $files = self::files($candidate);
        $baseline = self::TYPE_BASELINES[$type] ?? self::TYPE_BASELINES['gap'];
        $machineReasons = [];

        $advancement = self::scoreWithOverrides($candidate, 'advancement_score', $baseline['advancement']);
        $robustness = self::scoreWithOverrides($candidate, 'robustness_score', $baseline['robustness']);
        $operatorLeverage = self::scoreWithOverrides($candidate, 'operator_leverage_score', $baseline['operator_leverage']);
        $executionSafety = self::scoreWithOverrides($candidate, 'execution_safety_score', $baseline['execution_safety']);
        $evidence = self::scoreWithOverrides($candidate, 'evidence_score', $baseline['evidence']);
        $dependencyUnlock = self::scoreWithOverrides($candidate, 'dependency_unlock_score', $baseline['dependency_unlock']);

        $dependencyUnlock = min(100, $dependencyUnlock + count(self::listValue($candidate, 'dependency_unlocks')) * 8);
        $operatorLeverage = min(100, $operatorLeverage + ((int) ($candidate['operator_touchpoints_reduced'] ?? 0) * 5));
        $evidence = min(100, $evidence + count(self::listValue($candidate, 'evidence_refs')) * 5);

        if (self::allDocsOrTests($files)) {
            $executionSafety = min(100, $executionSafety + 8);
            $evidence = min(100, $evidence + 6);
        }

        $riskPenalty = self::riskPenalty($candidate, $files, $baseline['risk'], $machineReasons);
        $blocked = self::isBlocked($candidate, $machineReasons);

        if ((bool) ($candidate['requires_owner_runtime_boundary'] ?? false)) {
            $machineReasons[] = 'owner_runtime_boundary_required_first';
        }

        $roiScore = (int) ($factoryScores['roi_score'] ?? 0);
        $executionReadiness = (int) ($factoryScores['execution_readiness_score'] ?? 0);
        $factoryLeverage = (int) ($factoryScores['factory_leverage_score'] ?? 0);
        if ($scopeProfile === AreaFocusScopeProfileNormalizer::FACTORY_MAX || (bool) ($candidate['factory_execution_ready'] ?? false)) {
            $advancement = max($advancement, $factoryLeverage);
            $robustness = max($robustness, $executionReadiness);
            $evidence = max($evidence, $executionReadiness);
            if (self::isForgeAuthorityReadinessUnlock($candidate)) {
                $operatorLeverage = max($operatorLeverage, 90);
                $executionSafety = max($executionSafety, 90);
                $dependencyUnlock = max($dependencyUnlock, 96);
            }
            $riskPenalty = max($riskPenalty, (int) ($factoryScores['risk_penalty'] ?? 0));
            $machineReasons[] = 'factory_max_execution_scoring';
        }

        $score = $scopeProfile === AreaFocusScopeProfileNormalizer::FACTORY_MAX
            ? ($advancement * 0.18)
                + ($robustness * 0.16)
                + ($operatorLeverage * 0.10)
                + ($executionSafety * 0.10)
                + ($evidence * 0.08)
                + ($dependencyUnlock * 0.10)
                + ($roiScore * 0.14)
                + ($executionReadiness * 0.12)
                + ($factoryLeverage * 0.14)
                - ($riskPenalty * 0.55)
            : ($advancement * 0.26)
                + ($robustness * 0.22)
                + ($operatorLeverage * 0.14)
                + ($executionSafety * 0.14)
                + ($evidence * 0.12)
                + ($dependencyUnlock * 0.12)
                + ($roiScore * 0.08)
                + ($executionReadiness * 0.06)
                + ($factoryLeverage * 0.06)
                - ($riskPenalty * 0.55);

        $score = round(max(0, min(100, $score)), 2);
        $lane = self::lane($score, $riskPenalty, $blocked, $machineReasons);
        $itemId = (string) ($candidate['id'] ?? $candidate['item_id'] ?? $candidate['finding_id'] ?? $candidate['spec_id'] ?? $candidate['work_order_id'] ?? $candidate['branch_ref'] ?? 'candidate_'.$index);
        $completionStatus = (string) ($candidate['completion_status'] ?? 'pending');
        if ($completionStatus === 'completed' || (bool) ($candidate['implemented'] ?? false)) {
            $lane = 'completed';
            $score = 0.0;
            $machineReasons[] = 'completed_current_state';
        }
        $reasonMachine = self::reasonMachine($type, $lane, $machineReasons);

        return [
            'rank' => null,
            'original_index' => $index,
            'item_id' => $itemId,
            'candidate_id' => $itemId,
            'ap_contract' => (string) ($candidate['ap_contract'] ?? self::apFromId($itemId)),
            'title' => (string) ($candidate['title'] ?? $candidate['summary'] ?? $candidate['branch_ref'] ?? 'Untitled stewardship candidate'),
            'item_type' => $type,
            'kind' => $type,
            'affected_files' => $files,
            'advancement_score' => $advancement,
            'robustness_score' => $robustness,
            'operator_leverage_score' => $operatorLeverage,
            'execution_safety_score' => $executionSafety,
            'evidence_score' => $evidence,
            'dependency_unlock_score' => $dependencyUnlock,
            'roi_score' => $roiScore,
            'execution_readiness_score' => $executionReadiness,
            'factory_leverage_score' => $factoryLeverage,
            'risk_penalty' => $riskPenalty,
            'rejection_reason' => (string) ($factoryScores['rejection_reason'] ?? ''),
            'final_priority_score' => $score,
            // Backwards-compatible aliases for older AP-771 consumers.
            'priority_score' => $score,
            'priority_band' => self::band($score),
            'lane' => $lane,
            'reason' => self::humanReason($type, $lane, $reasonMachine),
            'reason_machine' => $reasonMachine,
            'score_breakdown' => [
                'advancement_score' => $advancement,
                'robustness_score' => $robustness,
                'operator_leverage_score' => $operatorLeverage,
                'execution_safety_score' => $executionSafety,
                'evidence_score' => $evidence,
                'dependency_unlock_score' => $dependencyUnlock,
                'roi_score' => $roiScore,
                'execution_readiness_score' => $executionReadiness,
                'factory_leverage_score' => $factoryLeverage,
                'risk_penalty' => $riskPenalty,
                'rejection_reason' => (string) ($factoryScores['rejection_reason'] ?? ''),
                'final_priority_score' => $score,
            ],
            'recommended_execution_order' => self::recommendedOrder($lane, $type),
            'autonomy_hint' => $lane === 'blocked' ? 'blocked_until_gates_clear' : 'operator_review_required',
            'source' => $candidate,
            'completion_status' => $completionStatus === 'completed' ? 'completed' : 'pending',
            'completion_evidence' => (array) ($candidate['completion_evidence'] ?? []),
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public static function factoryExecutionScores(array $candidate, string $scopeProfile, bool $hasLiveForgeAuthority): array
    {
        $roi = (int) ($candidate['roi_score'] ?? 0);
        $readiness = (int) ($candidate['execution_readiness_score'] ?? 0);
        $leverage = (int) ($candidate['factory_leverage_score'] ?? 0);
        $risk = (int) ($candidate['risk_penalty'] ?? 0);
        $rejection = trim((string) ($candidate['rejection_reason'] ?? ''));

        if ($roi === 0 && $readiness === 0 && $leverage === 0) {
            $files = self::files($candidate);
            $leverage = self::inferFactoryLeverageScore($candidate, $files);
            $readiness = self::inferExecutionReadinessScore($candidate, $files);
            $risk = self::inferFactoryRiskPenalty($candidate, $hasLiveForgeAuthority);
            $roi = self::clampScore((int) round(($leverage * 0.45) + ($readiness * 0.45) - ($risk * 0.35)));
        }

        if ($scopeProfile === AreaFocusScopeProfileNormalizer::FACTORY_MAX) {
            $kind = strtolower((string) ($candidate['kind'] ?? $candidate['type'] ?? ''));
            $owner = strtolower((string) ($candidate['owner_candidate'] ?? data_get($candidate, 'spec_seed.route_hint_owner', '')));
            if (self::isForgeAuthorityReadinessUnlock($candidate)) {
                $roi = max($roi, 100);
                $readiness = max($readiness, 100);
                $leverage = max($leverage, 100);
                $risk = min($risk === 0 ? 4 : $risk, 4);
            }
            if ($rejection === '' && in_array($kind, ['doc', 'ui_cosmetic'], true)) {
                $rejection = 'factory_max_rejects_low_leverage_doc_or_evidence_work';
            }
            if ($owner === 'forge' && ! $hasLiveForgeAuthority) {
                $rejection = 'factory_max_rejects_forge_without_live_authority';
                $risk = max($risk, 80);
            }
            if ($rejection === '' && self::allDocsOrTests(self::files($candidate)) && ! (bool) ($candidate['factory_execution_ready'] ?? false)) {
                if (in_array($kind, ['doc', 'risk', 'test'], true)) {
                    $rejection = 'factory_max_rejects_low_leverage_doc_or_evidence_work';
                }
            }
        }

        return [
            'roi_score' => $roi,
            'execution_readiness_score' => $readiness,
            'factory_leverage_score' => $leverage,
            'risk_penalty' => $risk,
            'rejection_reason' => $rejection,
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $factoryScores
     * @return array<string,mixed>
     */
    public static function rejectedFactoryRankedItem(array $candidate, int $index, array $factoryScores): array
    {
        $itemId = (string) ($candidate['id'] ?? $candidate['item_id'] ?? $candidate['finding_id'] ?? 'candidate_'.$index);
        $rejection = (string) ($factoryScores['rejection_reason'] ?? 'factory_candidate_rejected');

        return [
            'rank' => null,
            'original_index' => $index,
            'item_id' => $itemId,
            'candidate_id' => $itemId,
            'title' => (string) ($candidate['title'] ?? 'Rejected factory candidate'),
            'item_type' => (string) ($candidate['kind'] ?? 'gap'),
            'kind' => (string) ($candidate['kind'] ?? 'gap'),
            'affected_files' => self::files($candidate),
            'roi_score' => (int) ($factoryScores['roi_score'] ?? 0),
            'execution_readiness_score' => (int) ($factoryScores['execution_readiness_score'] ?? 0),
            'factory_leverage_score' => (int) ($factoryScores['factory_leverage_score'] ?? 0),
            'risk_penalty' => (int) ($factoryScores['risk_penalty'] ?? 80),
            'rejection_reason' => $rejection,
            'final_priority_score' => 0.0,
            'priority_score' => 0.0,
            'priority_band' => 'P3_defer',
            'lane' => 'blocked',
            'reason' => 'Rejected for factory_max: '.$rejection,
            'reason_machine' => ['lane:blocked', 'rejection:'.$rejection],
            'score_breakdown' => $factoryScores,
            'recommended_execution_order' => ['do_not_execute', 'clear_rejection_reason_first'],
            'autonomy_hint' => 'blocked_until_gates_clear',
            'source' => $candidate,
            'completion_status' => 'pending',
            'completion_evidence' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  list<string>  $files
     */
    public static function inferFactoryLeverageScore(array $candidate, array $files): int
    {
        $haystack = strtolower(implode(' ', [
            (string) ($candidate['title'] ?? ''),
            (string) ($candidate['detail'] ?? ''),
            (string) ($candidate['origin_type'] ?? ''),
            implode(' ', $files),
        ]));
        $score = 24;
        foreach (self::FACTORY_LEVERAGE_TERMS as $term) {
            if ($term !== '' && str_contains($haystack, $term)) {
                $score += 8;
            }
        }
        if ((string) ($candidate['origin'] ?? '') === 'factory_max_seed') {
            $score += 20;
        }

        return self::clampScore($score);
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  list<string>  $files
     */
    public static function inferExecutionReadinessScore(array $candidate, array $files): int
    {
        $score = 12;
        if ($files !== []) {
            $score += 24;
        }
        $testsRequired = self::listValue($candidate, 'tests_required');
        $seedTestsRequired = data_get($candidate, 'spec_seed.tests_required');
        if ($testsRequired === [] && is_array($seedTestsRequired)) {
            $testsRequired = AreaFocusStringListNormalizer::coercedStringValues($seedTestsRequired);
        }
        if ($testsRequired !== []) {
            $score += 28;
        }
        if (is_array($candidate['acceptance'] ?? null) && $candidate['acceptance'] !== []) {
            $score += 16;
        }
        if ((bool) ($candidate['factory_execution_ready'] ?? false)) {
            $score += 20;
        }

        return self::clampScore($score);
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    public static function inferFactoryRiskPenalty(array $candidate, bool $hasLiveForgeAuthority): int
    {
        $penalty = (int) ($candidate['risk_penalty'] ?? 0);
        $owner = strtolower((string) ($candidate['owner_candidate'] ?? ''));
        if ($owner === 'forge' && ! $hasLiveForgeAuthority) {
            $penalty = max($penalty, 80);
        }

        return self::clampScore($penalty);
    }

    /** @param array<string,mixed> $candidate */
    public static function isForgeAuthorityReadinessUnlock(array $candidate): bool
    {
        $haystack = strtolower(implode(' ', [
            (string) ($candidate['finding_id'] ?? ''),
            (string) ($candidate['id'] ?? ''),
            (string) ($candidate['item_id'] ?? ''),
            (string) ($candidate['ap_contract'] ?? ''),
            (string) ($candidate['origin_type'] ?? ''),
            (string) ($candidate['title'] ?? ''),
            (string) ($candidate['detail'] ?? ''),
            implode(' ', self::listValue($candidate, 'evidence_refs')),
            implode(' ', self::listValue($candidate, 'dependency_unlocks')),
            implode(' ', self::files($candidate)),
        ]));

        return str_contains($haystack, 'ap789')
            || str_contains($haystack, 'real_forge_authority')
            || (str_contains($haystack, 'forge') && str_contains($haystack, 'authority'));
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    public static function type(array $candidate): string
    {
        $value = strtolower((string) ($candidate['type'] ?? $candidate['kind'] ?? $candidate['classification'] ?? 'gap'));
        $ap = strtoupper((string) ($candidate['ap_contract'] ?? $candidate['id'] ?? ''));

        return match (true) {
            str_contains($ap, 'AP-783') => 'integration_lane_promotion',
            str_contains($ap, 'AP-784') => 'live_cycle_audit',
            str_contains($value, 'truth') || str_contains($value, 'audit') => 'truth_surface',
            str_contains($value, 'owner_runtime') || str_contains($value, 'runtime_execution') => 'owner_runtime_real_execution_bridge',
            str_contains($value, 'scheduler') || str_contains($value, '24h') => '24h_scheduler',
            str_contains($value, 'product_mode') || str_contains($value, 'controls') || str_contains($value, 'receipt') => 'product_mode_controls',
            str_contains($value, 'provider') => 'provider_routing',
            str_contains($value, 'ui') || str_contains($value, 'cosmetic') => 'ui_cosmetic',
            isset(self::TYPE_BASELINES[$value]) => $value,
            default => 'gap',
        };
    }

    public static function scoreWithOverrides(array $candidate, string $key, int $default): int
    {
        if (array_key_exists($key, $candidate)) {
            return self::clampScore((int) $candidate[$key]);
        }

        return $default;
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $machineReasons
     */
    public static function riskPenalty(array $candidate, array $files, int $baseline, array &$machineReasons): int
    {
        $penalty = $baseline;
        if ((bool) ($candidate['requires_main_dirty'] ?? $candidate['main_dirty_required'] ?? false)) {
            $penalty += 45;
            $machineReasons[] = 'main_dirty_required';
        }
        if ((bool) ($candidate['requires_provider_without_sandbox'] ?? false)) {
            $penalty += 45;
            $machineReasons[] = 'provider_without_sandbox';
        }
        if ((bool) ($candidate['merge_conflict_detected'] ?? $candidate['conflict_risk'] ?? false)) {
            $penalty += 30;
            $machineReasons[] = 'merge_conflict_risk';
        }
        if ((bool) ($candidate['sensitive_data'] ?? false)) {
            $penalty += 40;
            $machineReasons[] = 'sensitive_data';
        }
        foreach ($files as $file) {
            if (str_starts_with($file, 'routes/') || str_starts_with($file, 'config/') || str_contains($file, 'Command.php')) {
                $penalty += 6;
            }
        }

        return self::clampScore($penalty);
    }

    /**
     * @param  list<string>  $machineReasons
     */
    public static function isBlocked(array $candidate, array &$machineReasons): bool
    {
        $blocked = false;
        if ((bool) ($candidate['requires_main_dirty'] ?? $candidate['main_dirty_required'] ?? false)) {
            $blocked = true;
        }
        if ((bool) ($candidate['requires_provider_without_sandbox'] ?? false)) {
            $blocked = true;
        }
        if ((bool) ($candidate['sensitive_data'] ?? false) && self::listValue($candidate, 'required_gates') === []) {
            $blocked = true;
            $machineReasons[] = 'sensitive_without_required_gates';
        }

        return $blocked;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return list<string>
     */
    public static function files(array $candidate): array
    {
        $files = $candidate['affected_files'] ?? $candidate['changed_files'] ?? data_get($candidate, 'gitkraken_review_surface.changed_files', []);
        if (! is_array($files)) {
            return [];
        }

        return AreaFocusStringListNormalizer::stringifiedNonEmptyValues($files);
    }

    /**
     * @return list<string>
     */
    public static function listValue(array $candidate, string $key): array
    {
        $value = $candidate[$key] ?? [];

        return is_array($value) ? AreaFocusStringListNormalizer::stringifiedNonEmptyValues($value) : [];
    }

    /**
     * @param  list<string>  $files
     */
    public static function allDocsOrTests(array $files): bool
    {
        if ($files === []) {
            return false;
        }

        foreach ($files as $file) {
            if (! str_starts_with($file, 'docs/') && ! str_starts_with($file, 'tests/') && ! str_ends_with($file, 'Test.php')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public static function recommendedOrder(string $lane, string $type): array
    {
        return match ($lane) {
            'now' => ['implement_now_after_owner_gate', 'keep_operator_review_for_irreversible_actions'],
            'next' => ['queue_after_now_lane', 'preserve_evidence_and_review_surface'],
            'completed' => ['do_not_reimplement', 'advance_to_next_highest_incomplete_candidate'],
            'blocked' => ['do_not_execute', 'clear_machine_readable_blockers_first'],
            default => $type === 'provider_routing'
                ? ['defer_until_owner_runtime_boundaries_are_real']
                : ['keep_in_later_backlog'],
        };
    }

    public static function band(float $score): string
    {
        return match (true) {
            $score >= 85 => 'P0_maximum_advancement',
            $score >= 70 => 'P1_high_advancement',
            $score >= 50 => 'P2_standard',
            default => 'P3_defer',
        };
    }

    /**
     * @param  list<string>  $machineReasons
     */
    public static function lane(float $score, int $riskPenalty, bool $blocked, array $machineReasons): string
    {
        if ($blocked || in_array('sensitive_without_required_gates', $machineReasons, true) || $riskPenalty >= 80) {
            return 'blocked';
        }
        if ($score >= 72 && $riskPenalty < 55) {
            return 'now';
        }
        if ($score >= 50 && $riskPenalty < 70) {
            return 'next';
        }

        return 'later';
    }

    /**
     * @return list<string>
     */
    public static function reasonMachine(string $type, string $lane, array $machineReasons): array
    {
        $reasons = [
            'type:'.$type,
            'lane:'.$lane,
        ];

        return AreaFocusStringListNormalizer::uniqueMergedStringValues($reasons, $machineReasons);
    }

    /**
     * @param  list<string>  $reasonMachine
     */
    public static function humanReason(string $type, string $lane, array $reasonMachine): string
    {
        if ($lane === 'blocked') {
            return 'Blocked because required safety gates are missing: '.implode(', ', array_slice($reasonMachine, 2));
        }
        if ($lane === 'completed') {
            return 'Already implemented in the current repo state; skip reimplementation and move to the next incomplete candidate.';
        }

        return match ($type) {
            'integration_lane_promotion' => 'Highest priority because it promotes integration visibility and unlocks the real execution lane without mutating main.',
            'live_cycle_audit', 'truth_surface' => 'High priority because it increases truth/auditability before claiming more autonomy.',
            'owner_runtime_real_execution_bridge', 'runtime_execution_bridge' => 'High priority because real owner execution is the next operational gap after safe integration.',
            '24h_scheduler', 'continuous_scheduler' => 'Priority because recurring operation needs budgets, locks and kill switch before unattended use.',
            'product_mode_controls' => 'Priority after runtime and scheduler gates because it improves operator control and receipts.',
            'provider_routing' => 'Deferred until owner runtime boundaries are real; routing providers early would add risk without execution authority.',
            default => 'Ranked by advancement, robustness, operator leverage, execution safety, evidence, dependency unlocks and risk.',
        };
    }

    public static function apFromId(string $itemId): string
    {
        return preg_match('/AP-\d+/', strtoupper($itemId), $m) === 1 ? $m[0] : '';
    }

    public static function clampScore(int $value): int
    {
        return max(0, min(100, $value));
    }


    /**
     * @param  list<array<string,mixed>>  $ranked
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
}
