<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-785 · Stewardship Priority Engine.
 *
 * Ranks findings/specs/work orders/branches/queue items by the operator's
 * current rule: maximize real advancement and robustness before cosmetic work.
 * The engine is deterministic and read-only; execution stays with owner gates.
 */
final class StewardshipPriorityEngineService implements StewardshipPriorityRanker
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.priority_engine.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    /** @var array<string,array<string,int>> */
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

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public const SCOPE_FACTORY_MAX = 'factory_max';

    /** @var list<string> */
    private const TERMINAL_STARVATION_REPLENISHMENT_UNLOCK_CATEGORIES = [
        'owner_runtime',
        'forge_authority',
        'scheduler',
        'merge',
        'product_mode',
    ];

    /** @var list<string> */
    private const TERMINAL_STARVATION_REPLENISHMENT_LADDER = [
        'merge',
        'deep_scan',
        'priority_backlog',
    ];

    /** @var list<string> */
    private const FACTORY_LEVERAGE_TERMS = [
        'ap786', 'ap790', 'autonomous', 'evolution', 'sandbox', 'materializer',
        'merge', 'governor', 'owner_runtime', 'senior_loop', 'provider', 'cursor',
        'dev_forge', 'priority', 'deep_finding', 'reliable24h', 'inbox', 'read_model',
        'evidence', 'worktree', 'stewardship', 'atlas_dev', 'forge',
    ];

    /**
     * Step 2 of 3 — context quality score entry seam.
     *
     * Validates bounded input keys and materializes {@see ContextQualityScoreContract}.
     * Empty input returns the default contract; no ranking boost wiring yet.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function contextQualityScore(array $input = []): array
    {
        if ($input === []) {
            return ContextQualityScoreContract::defaults()->toArray();
        }

        return ContextQualityScoreContract::fromArray(
            $this->validateContextQualityScoreInput($input)
        )->toArray();
    }

    public function rank(array $input): array
    {
        $areaId = (string) ($input['area_id'] ?? self::DEFAULT_AREA_ID);
        $focus = (string) ($input['focus'] ?? 'dev_forge');
        $scopeProfile = strtolower(trim((string) ($input['scope_profile'] ?? '')));
        $hasLiveForgeAuthority = (bool) ($input['has_live_forge_authority'] ?? false);
        $candidates = $this->candidates($input);
        if ($candidates === []) {
            $candidates = $this->canonicalSeedCandidates();
        }

        $ranked = [];
        foreach ($candidates as $index => $candidate) {
            $ranked[] = $this->applyContextQualityPriorityBoost(
                $input,
                $candidate,
                $this->scoreItem($candidate, $index, $scopeProfile, $hasLiveForgeAuthority),
            );
        }

        usort($ranked, static function (array $a, array $b): int {
            $laneOrder = ['now' => 0, 'next' => 1, 'later' => 2, 'blocked' => 3, 'completed' => 4];

            return (($laneOrder[(string) ($a['lane'] ?? 'later')] ?? 2) <=> ($laneOrder[(string) ($b['lane'] ?? 'later')] ?? 2))
                ?: ((float) ($b['final_priority_score'] ?? 0.0) <=> (float) ($a['final_priority_score'] ?? 0.0))
                ?: ((int) ($b['roi_score'] ?? 0) <=> (int) ($a['roi_score'] ?? 0))
                ?: ((int) ($a['original_index'] ?? 0) <=> (int) ($b['original_index'] ?? 0));
        });

        foreach ($ranked as $i => &$item) {
            $item['rank'] = $i + 1;
        }
        unset($item);

        $ranked = $this->applyPriorityBacklogMaterialization($ranked, $candidates, $scopeProfile, $input);

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-785',
            'status' => self::STATUS_READY,
            'area_id' => $areaId,
            'focus' => $focus,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-748', 'AP-765', 'AP-766', 'AP-767', 'AP-768', 'AP-776', 'AP-780', 'AP-785'],
            'scope_profile' => $scopeProfile !== '' ? $scopeProfile : 'balanced',
            'ranking_policy' => [
                'objective' => $scopeProfile === self::SCOPE_FACTORY_MAX
                    ? 'maximize_software_factory_execution_readiness_and_mergeable_roi'
                    : 'implement_in_order_of_largest_real_advancement_and_largest_possible_robustness',
                'score_components' => [
                    'advancement_score',
                    'robustness_score',
                    'operator_leverage_score',
                    'execution_safety_score',
                    'evidence_score',
                    'dependency_unlock_score',
                    'roi_score',
                    'execution_readiness_score',
                    'factory_leverage_score',
                    'risk_penalty',
                    'rejection_reason',
                    'final_priority_score',
                ],
                'canonical_expected_order' => [
                    'AP-783 integration lane promotion',
                    'live cycle audit / truth surface',
                    'owner runtime real execution bridge',
                    '24h scheduler with budgets/locks/kill switch',
                    'Product Mode controls/receipts',
                    'provider routing Opus/Sonnet/Gemini/Codex only after owner runtime boundaries are real',
                ],
                'deterministic' => true,
                'mutates_repo' => false,
            ],
            'candidate_count' => count($ranked),
            'priority_backlog_materialization' => $this->priorityBacklogMaterializationReport($ranked, $input),
            'top_candidate' => $ranked[0] ?? null,
            'ranked_items' => $ranked,
            // Backwards-compatible alias for older AP-771 consumers.
            'ranked_candidates' => $ranked,
            'claim_policy' => [
                'provider_invoked' => false,
                'branch_created' => false,
                'worktree_created' => false,
                'merge_performed' => false,
                'mutates_target_repo' => false,
                'deploys' => false,
                'touches_secrets' => false,
                'auto_approval' => false,
                'creates_runtime' => false,
                'creates_new_os' => false,
                'autonomous_selection_possible' => false,
                'operator_review_still_required_for_irreversible_actions' => true,
            ],
            'generated_at' => $this->now(),
        ];
        $payload['priority_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function candidates(array $input): array
    {
        if (is_array($input['candidates'] ?? null)) {
            return array_values(array_filter($input['candidates'], 'is_array'));
        }

        if (is_array($input['findings'] ?? null)) {
            return array_values(array_filter($input['findings'], 'is_array'));
        }

        if (is_array($input['deep_scan_report'] ?? null)) {
            return array_values(array_filter((array) ($input['deep_scan_report']['findings'] ?? []), 'is_array'));
        }

        if (is_array($input['branches'] ?? null)) {
            return array_values(array_filter($input['branches'], 'is_array'));
        }

        if (is_array($input['specs'] ?? null)) {
            return array_values(array_filter($input['specs'], 'is_array'));
        }

        if (is_array($input['work_orders'] ?? null)) {
            return array_values(array_filter($input['work_orders'], 'is_array'));
        }

        if (is_array($input['queue_items'] ?? null)) {
            return array_values(array_filter($input['queue_items'], 'is_array'));
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function scoreItem(array $candidate, int $index, string $scopeProfile = '', bool $hasLiveForgeAuthority = false): array
    {
        $factoryScores = $this->factoryExecutionScores($candidate, $scopeProfile, $hasLiveForgeAuthority);
        if (($factoryScores['rejection_reason'] ?? '') !== '') {
            return $this->rejectedFactoryRankedItem($candidate, $index, $factoryScores);
        }

        $type = $this->type($candidate);
        $files = $this->files($candidate);
        $baseline = self::TYPE_BASELINES[$type] ?? self::TYPE_BASELINES['gap'];
        $machineReasons = [];

        $advancement = $this->scoreWithOverrides($candidate, 'advancement_score', $baseline['advancement']);
        $robustness = $this->scoreWithOverrides($candidate, 'robustness_score', $baseline['robustness']);
        $operatorLeverage = $this->scoreWithOverrides($candidate, 'operator_leverage_score', $baseline['operator_leverage']);
        $executionSafety = $this->scoreWithOverrides($candidate, 'execution_safety_score', $baseline['execution_safety']);
        $evidence = $this->scoreWithOverrides($candidate, 'evidence_score', $baseline['evidence']);
        $dependencyUnlock = $this->scoreWithOverrides($candidate, 'dependency_unlock_score', $baseline['dependency_unlock']);

        $dependencyUnlock = min(100, $dependencyUnlock + count($this->listValue($candidate, 'dependency_unlocks')) * 8);
        $operatorLeverage = min(100, $operatorLeverage + ((int) ($candidate['operator_touchpoints_reduced'] ?? 0) * 5));
        $evidence = min(100, $evidence + count($this->listValue($candidate, 'evidence_refs')) * 5);

        if ($this->allDocsOrTests($files)) {
            $executionSafety = min(100, $executionSafety + 8);
            $evidence = min(100, $evidence + 6);
        }

        $riskPenalty = $this->riskPenalty($candidate, $files, $baseline['risk'], $machineReasons);
        $blocked = $this->isBlocked($candidate, $machineReasons);

        if ((bool) ($candidate['requires_owner_runtime_boundary'] ?? false)) {
            $machineReasons[] = 'owner_runtime_boundary_required_first';
        }

        $roiScore = (int) ($factoryScores['roi_score'] ?? 0);
        $executionReadiness = (int) ($factoryScores['execution_readiness_score'] ?? 0);
        $factoryLeverage = (int) ($factoryScores['factory_leverage_score'] ?? 0);
        if ($scopeProfile === self::SCOPE_FACTORY_MAX || (bool) ($candidate['factory_execution_ready'] ?? false)) {
            $advancement = max($advancement, $factoryLeverage);
            $robustness = max($robustness, $executionReadiness);
            $evidence = max($evidence, $executionReadiness);
            if ($this->isForgeAuthorityReadinessUnlock($candidate)) {
                $operatorLeverage = max($operatorLeverage, 90);
                $executionSafety = max($executionSafety, 90);
                $dependencyUnlock = max($dependencyUnlock, 96);
            }
            $riskPenalty = max($riskPenalty, (int) ($factoryScores['risk_penalty'] ?? 0));
            $machineReasons[] = 'factory_max_execution_scoring';
        }

        $score = $scopeProfile === self::SCOPE_FACTORY_MAX
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
        $lane = $this->lane($score, $riskPenalty, $blocked, $machineReasons);
        $itemId = (string) ($candidate['id'] ?? $candidate['item_id'] ?? $candidate['finding_id'] ?? $candidate['spec_id'] ?? $candidate['work_order_id'] ?? $candidate['branch_ref'] ?? 'candidate_'.$index);
        $completionStatus = (string) ($candidate['completion_status'] ?? 'pending');
        if ($completionStatus === 'completed' || (bool) ($candidate['implemented'] ?? false)) {
            $lane = 'completed';
            $score = 0.0;
            $machineReasons[] = 'completed_current_state';
        }
        $reasonMachine = $this->reasonMachine($type, $lane, $machineReasons);

        return [
            'rank' => null,
            'original_index' => $index,
            'item_id' => $itemId,
            'candidate_id' => $itemId,
            'ap_contract' => (string) ($candidate['ap_contract'] ?? $this->apFromId($itemId)),
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
            'priority_band' => $this->band($score),
            'lane' => $lane,
            'reason' => $this->humanReason($type, $lane, $reasonMachine),
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
            'recommended_execution_order' => $this->recommendedOrder($lane, $type),
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
    private function factoryExecutionScores(array $candidate, string $scopeProfile, bool $hasLiveForgeAuthority): array
    {
        $roi = (int) ($candidate['roi_score'] ?? 0);
        $readiness = (int) ($candidate['execution_readiness_score'] ?? 0);
        $leverage = (int) ($candidate['factory_leverage_score'] ?? 0);
        $risk = (int) ($candidate['risk_penalty'] ?? 0);
        $rejection = trim((string) ($candidate['rejection_reason'] ?? ''));

        if ($roi === 0 && $readiness === 0 && $leverage === 0) {
            $files = $this->files($candidate);
            $leverage = $this->inferFactoryLeverageScore($candidate, $files);
            $readiness = $this->inferExecutionReadinessScore($candidate, $files);
            $risk = $this->inferFactoryRiskPenalty($candidate, $hasLiveForgeAuthority);
            $roi = $this->clampScore((int) round(($leverage * 0.45) + ($readiness * 0.45) - ($risk * 0.35)));
        }

        if ($scopeProfile === self::SCOPE_FACTORY_MAX) {
            $kind = strtolower((string) ($candidate['kind'] ?? $candidate['type'] ?? ''));
            $owner = strtolower((string) ($candidate['owner_candidate'] ?? data_get($candidate, 'spec_seed.route_hint_owner', '')));
            if ($this->isForgeAuthorityReadinessUnlock($candidate)) {
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
            if ($rejection === '' && $this->allDocsOrTests($this->files($candidate)) && ! (bool) ($candidate['factory_execution_ready'] ?? false)) {
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
    private function rejectedFactoryRankedItem(array $candidate, int $index, array $factoryScores): array
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
            'affected_files' => $this->files($candidate),
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
    private function inferFactoryLeverageScore(array $candidate, array $files): int
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

        return $this->clampScore($score);
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  list<string>  $files
     */
    private function inferExecutionReadinessScore(array $candidate, array $files): int
    {
        $score = 12;
        if ($files !== []) {
            $score += 24;
        }
        $testsRequired = $this->listValue($candidate, 'tests_required');
        if ($testsRequired === [] && is_array(data_get($candidate, 'spec_seed.tests_required'))) {
            $testsRequired = array_values(array_filter(data_get($candidate, 'spec_seed.tests_required'), 'is_string'));
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

        return $this->clampScore($score);
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function inferFactoryRiskPenalty(array $candidate, bool $hasLiveForgeAuthority): int
    {
        $penalty = (int) ($candidate['risk_penalty'] ?? 0);
        $owner = strtolower((string) ($candidate['owner_candidate'] ?? ''));
        if ($owner === 'forge' && ! $hasLiveForgeAuthority) {
            $penalty = max($penalty, 80);
        }

        return $this->clampScore($penalty);
    }

    /** @param array<string,mixed> $candidate */
    private function isForgeAuthorityReadinessUnlock(array $candidate): bool
    {
        $haystack = strtolower(implode(' ', [
            (string) ($candidate['finding_id'] ?? ''),
            (string) ($candidate['id'] ?? ''),
            (string) ($candidate['item_id'] ?? ''),
            (string) ($candidate['ap_contract'] ?? ''),
            (string) ($candidate['origin_type'] ?? ''),
            (string) ($candidate['title'] ?? ''),
            (string) ($candidate['detail'] ?? ''),
            implode(' ', $this->listValue($candidate, 'evidence_refs')),
            implode(' ', $this->listValue($candidate, 'dependency_unlocks')),
            implode(' ', $this->files($candidate)),
        ]));

        return str_contains($haystack, 'ap789')
            || str_contains($haystack, 'real_forge_authority')
            || (str_contains($haystack, 'forge') && str_contains($haystack, 'authority'));
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function type(array $candidate): string
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

    private function scoreWithOverrides(array $candidate, string $key, int $default): int
    {
        if (array_key_exists($key, $candidate)) {
            return $this->clampScore((int) $candidate[$key]);
        }

        return $default;
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $machineReasons
     */
    private function riskPenalty(array $candidate, array $files, int $baseline, array &$machineReasons): int
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

        return $this->clampScore($penalty);
    }

    /**
     * @param  list<string>  $machineReasons
     */
    private function isBlocked(array $candidate, array &$machineReasons): bool
    {
        $blocked = false;
        if ((bool) ($candidate['requires_main_dirty'] ?? $candidate['main_dirty_required'] ?? false)) {
            $blocked = true;
        }
        if ((bool) ($candidate['requires_provider_without_sandbox'] ?? false)) {
            $blocked = true;
        }
        if ((bool) ($candidate['sensitive_data'] ?? false) && $this->listValue($candidate, 'required_gates') === []) {
            $blocked = true;
            $machineReasons[] = 'sensitive_without_required_gates';
        }

        return $blocked;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return list<string>
     */
    private function files(array $candidate): array
    {
        $files = $candidate['affected_files'] ?? $candidate['changed_files'] ?? data_get($candidate, 'gitkraken_review_surface.changed_files', []);
        if (! is_array($files)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $files), static fn (string $f): bool => $f !== ''));
    }

    /**
     * @return list<string>
     */
    private function listValue(array $candidate, string $key): array
    {
        $value = $candidate[$key] ?? [];

        return is_array($value)
            ? array_values(array_filter(array_map('strval', $value), static fn (string $v): bool => $v !== ''))
            : [];
    }

    /**
     * @param  list<string>  $files
     */
    private function allDocsOrTests(array $files): bool
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
    private function recommendedOrder(string $lane, string $type): array
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

    private function band(float $score): string
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
    private function lane(float $score, int $riskPenalty, bool $blocked, array $machineReasons): string
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
    private function reasonMachine(string $type, string $lane, array $machineReasons): array
    {
        $reasons = [
            'type:'.$type,
            'lane:'.$lane,
        ];

        return array_values(array_unique(array_merge($reasons, $machineReasons)));
    }

    /**
     * @param  list<string>  $reasonMachine
     */
    private function humanReason(string $type, string $lane, array $reasonMachine): string
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

    private function apFromId(string $itemId): string
    {
        return preg_match('/AP-\d+/', strtoupper($itemId), $m) === 1 ? $m[0] : '';
    }

    private function clampScore(int $value): int
    {
        return max(0, min(100, $value));
    }

    private function clamp01(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    /**
     * @param  list<array<string,mixed>>  $ranked
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function applyPriorityBacklogMaterialization(
        array $ranked,
        array $candidates,
        string $scopeProfile,
        array $input,
    ): array {
        if ($ranked === []) {
            return $ranked;
        }

        $sourcesById = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $id = (string) ($candidate['id'] ?? $candidate['item_id'] ?? $candidate['finding_id'] ?? '');
            if ($id !== '') {
                $sourcesById[$id] = $candidate;
            }
        }

        $terminalRebalance = $this->terminalBacklogRebalanceActive($input);
        $rebalanced = [];

        foreach ($ranked as $item) {
            $itemId = (string) ($item['item_id'] ?? '');
            $source = $sourcesById[$itemId] ?? (is_array($item['source'] ?? null) ? $item['source'] : []);
            $status = strtolower((string) ($item['completion_status'] ?? 'pending'));
            $unlockCategory = $this->priorityBacklogUnlockCategory($itemId, $source, $item);

            $replenishCompleted = $terminalRebalance
                && $this->terminalStarvationExhaustionActive($input)
                && $status === 'completed'
                && $unlockCategory !== ''
                && in_array($unlockCategory, self::TERMINAL_STARVATION_REPLENISHMENT_UNLOCK_CATEGORIES, true);

            if (($status !== 'completed' || $replenishCompleted) && $unlockCategory !== '') {
                if ($replenishCompleted) {
                    $item['completion_status'] = 'pending';
                    $item['priority_backlog_replenishment_anchor'] = true;
                    $item['lane'] = 'later';
                }
                $item = $this->enrichMaterializableBacklogItem($item, $source, $unlockCategory, $scopeProfile);
            }

            if ($terminalRebalance && $unlockCategory !== '' && ($status !== 'completed' || $replenishCompleted)) {
                $item = $this->rebalanceTerminalStarvationItem($item, $unlockCategory);
            }

            $rebalanced[] = $item;
        }

        if ($terminalRebalance && $this->terminalStarvationExhaustionActive($input)) {
            $rebalanced = $this->appendTerminalStarvationReplenishmentCandidates(
                $rebalanced,
                $scopeProfile,
                $input,
            );
        }

        if ($terminalRebalance || $scopeProfile === self::SCOPE_FACTORY_MAX) {
            usort($rebalanced, static function (array $a, array $b): int {
                $laneOrder = ['now' => 0, 'next' => 1, 'later' => 2, 'blocked' => 3, 'completed' => 4];

                return (($laneOrder[(string) ($a['lane'] ?? 'later')] ?? 2) <=> ($laneOrder[(string) ($b['lane'] ?? 'later')] ?? 2))
                    ?: ((float) ($b['final_priority_score'] ?? 0.0) <=> (float) ($a['final_priority_score'] ?? 0.0))
                    ?: ((int) ($b['roi_score'] ?? 0) <=> (int) ($a['roi_score'] ?? 0))
                    ?: ((int) ($a['original_index'] ?? 0) <=> (int) ($b['original_index'] ?? 0));
            });

            foreach ($rebalanced as $i => &$item) {
                $item['rank'] = $i + 1;
            }
            unset($item);
        }

        return $rebalanced;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function terminalBacklogRebalanceActive(array $input): bool
    {
        $stateHash = trim((string) ($input['terminal_backlog_state_hash'] ?? ''));
        $reasons = array_values(array_filter((array) ($input['terminal_backlog_rejection_reasons'] ?? []), 'is_string'));

        return $stateHash !== '' || $reasons !== [];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function terminalStarvationExhaustionActive(array $input): bool
    {
        $reasons = array_values(array_filter((array) ($input['terminal_backlog_rejection_reasons'] ?? []), 'is_string'));

        return in_array('no_executable_candidates_after_selection_pass', $reasons, true);
    }

    /**
     * @param  list<array<string,mixed>>  $ranked
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function appendTerminalStarvationReplenishmentCandidates(
        array $ranked,
        string $scopeProfile,
        array $input,
    ): array {
        $presentCategories = [];
        $presentSeedIds = [];
        foreach ($ranked as $item) {
            $itemId = (string) ($item['item_id'] ?? '');
            if ($itemId !== '') {
                $presentSeedIds[$itemId] = true;
            }
            $category = (string) ($item['priority_backlog_unlock_category'] ?? '');
            if ($category !== '' && strtolower((string) ($item['completion_status'] ?? 'pending')) !== 'completed') {
                $presentCategories[$category] = true;
            }
        }

        $nextIndex = count($ranked);
        foreach (self::TERMINAL_STARVATION_REPLENISHMENT_LADDER as $requiredCategory) {
            $seedId = $this->terminalReplenishmentSeedIdForCategory($requiredCategory);
            if (isset($presentSeedIds[$seedId])) {
                continue;
            }
            if ($requiredCategory === 'merge' && isset($presentCategories['merge'])) {
                continue;
            }

            $seed = $this->terminalReplenishmentSeedForCategory($requiredCategory);
            $scored = $this->scoreItem(
                $seed,
                $nextIndex,
                $scopeProfile,
                (bool) ($input['has_live_forge_authority'] ?? false),
            );
            $scored = $this->enrichMaterializableBacklogItem($scored, $seed, $requiredCategory, $scopeProfile);
            $scored = $this->rebalanceTerminalStarvationItem($scored, $requiredCategory);
            $scored['priority_backlog_replenishment_anchor'] = true;
            $ranked[] = $scored;
            $presentSeedIds[$seedId] = true;
            $presentCategories[$requiredCategory] = true;
            $nextIndex++;
        }

        return $ranked;
    }

    private function terminalReplenishmentSeedIdForCategory(string $category): string
    {
        return match ($category) {
            'merge' => 'terminal_backlog_replenish_merge_queue',
            'deep_scan' => 'terminal_backlog_replenish_deep_scan',
            'priority_backlog' => 'terminal_backlog_replenish_priority_backlog',
            default => 'terminal_backlog_replenish_'.$category,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function terminalReplenishmentSeedForCategory(string $category): array
    {
        return match ($category) {
            'merge' => [
                'id' => $this->terminalReplenishmentSeedIdForCategory('merge'),
                'title' => 'Replenish merge queue executable after terminal starvation',
                'ap_contract' => 'AP-772',
                'type' => 'safety_robustness_unlock',
                'dependency_unlocks' => ['merge_queue', 'integration_lane', 'repo_merge_lease'],
                'operator_touchpoints_reduced' => 2,
                'completion_status' => 'pending',
                'completion_evidence' => [
                    'service' => 'StewardshipMergeQueueService',
                    'contract' => 'docs/ap/AP-772-stewardship-merge-queue-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeQueueServiceTest.php',
                ],
            ],
            'deep_scan' => [
                'id' => $this->terminalReplenishmentSeedIdForCategory('deep_scan'),
                'title' => 'Replenish deep-scan candidate discovery after terminal starvation',
                'ap_contract' => 'AP-748',
                'type' => 'gap',
                'dependency_unlocks' => ['deep_scan', 'candidate_discovery', 'ap748'],
                'operator_touchpoints_reduced' => 2,
                'completion_status' => 'pending',
                'completion_evidence' => [
                    'service' => 'AreaFocusDeepFindingEngineService',
                    'contract' => 'docs/ap/AP-748-stewardship-release-outcome-bridge-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineServiceTest.php',
                ],
            ],
            'priority_backlog' => [
                'id' => $this->terminalReplenishmentSeedIdForCategory('priority_backlog'),
                'title' => 'Replenish priority backlog generation after terminal starvation',
                'ap_contract' => 'AP-785',
                'type' => 'safety_robustness_unlock',
                'dependency_unlocks' => ['priority_backlog', 'priority_engine', 'ap790'],
                'operator_touchpoints_reduced' => 2,
                'completion_status' => 'pending',
                'completion_evidence' => [
                    'service' => 'StewardshipPriorityEngineService',
                    'contract' => 'docs/ap/AP-785-stewardship-priority-engine-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineServiceTest.php',
                ],
            ],
            default => [
                'id' => $this->terminalReplenishmentSeedIdForCategory($category),
                'title' => 'Replenish '.$category.' executable after terminal starvation',
                'type' => 'gap',
                'completion_status' => 'pending',
                'completion_evidence' => [],
            ],
        };
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $item
     */
    private function priorityBacklogUnlockCategory(string $itemId, array $source, array $item): string
    {
        $haystack = strtolower(implode(' ', [
            $itemId,
            (string) ($source['type'] ?? ''),
            (string) ($source['kind'] ?? ''),
            (string) ($item['item_type'] ?? ''),
            (string) ($source['title'] ?? ''),
            (string) ($item['title'] ?? ''),
            implode(' ', $this->listValue($source, 'dependency_unlocks')),
            implode(' ', $this->listValue($item, 'dependency_unlocks')),
        ]));

        return match (true) {
            str_contains($haystack, 'owner_runtime') || str_contains($haystack, 'runtime_execution') => 'owner_runtime',
            str_contains($haystack, '24h_scheduler') || str_contains($haystack, 'continuous_24h') || str_contains($haystack, 'scheduler') => 'scheduler',
            str_contains($haystack, 'product_mode') || str_contains($haystack, 'controls_receipt') => 'product_mode',
            str_contains($haystack, 'provider_routing') || str_contains($haystack, 'provider_optimization') || str_contains($haystack, 'atlas_decide') || str_contains($haystack, 'ap789') || str_contains($haystack, 'forge_authority') || str_contains($haystack, 'real_forge_authority') => 'forge_authority',
            str_contains($haystack, 'senior_loop') || str_contains($haystack, 'repair_after_authority') => 'owner_runtime',
            str_contains($haystack, 'merge_queue') || str_contains($haystack, 'merge') => 'merge',
            str_contains($haystack, 'deep_scan') || str_contains($haystack, 'candidate_discovery') => 'deep_scan',
            str_contains($haystack, 'priority_backlog') || str_contains($haystack, 'priority_engine') => 'priority_backlog',
            default => '',
        };
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<string,mixed>  $source
     * @return array<string,mixed>
     */
    private function enrichMaterializableBacklogItem(
        array $item,
        array $source,
        string $unlockCategory,
        string $scopeProfile,
    ): array {
        $paths = $this->materializationPathsFromSource($source);
        if ($paths['runtime'] !== '') {
            $item['affected_files'] = array_values(array_unique(array_merge(
                (array) ($item['affected_files'] ?? []),
                [$paths['runtime']],
            )));
        }
        if ($paths['test'] !== '') {
            $item['tests_required'] = array_values(array_unique(array_merge(
                $this->listValue($item, 'tests_required'),
                [$paths['test']],
            )));
        }

        $item['priority_backlog_materializable'] = true;
        $item['priority_backlog_unlock_category'] = $unlockCategory;
        $item['factory_execution_ready'] = true;
        $item['owner_candidate'] = (string) ($source['owner_candidate'] ?? 'atlas_dev');

        if ($scopeProfile === self::SCOPE_FACTORY_MAX || (bool) ($item['factory_execution_ready'] ?? false)) {
            $item['execution_readiness_score'] = max((int) ($item['execution_readiness_score'] ?? 0), 92);
            $item['factory_leverage_score'] = max((int) ($item['factory_leverage_score'] ?? 0), 90);
            $item['roi_score'] = max((int) ($item['roi_score'] ?? 0), 88);
        }

        return $item;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function rebalanceTerminalStarvationItem(array $item, string $unlockCategory): array
    {
        $item['lane'] = 'now';
        $item['priority_band'] = $this->band((float) ($item['final_priority_score'] ?? 0.0));
        $item['reason_machine'] = array_values(array_unique(array_merge(
            (array) ($item['reason_machine'] ?? []),
            ['terminal_backlog_rebalance', 'unlock:'.$unlockCategory],
        )));
        $item['final_priority_score'] = max((float) ($item['final_priority_score'] ?? 0.0), 78.0);
        $item['priority_score'] = $item['final_priority_score'];
        $item['execution_readiness_score'] = max((int) ($item['execution_readiness_score'] ?? 0), 94);
        $item['factory_leverage_score'] = max((int) ($item['factory_leverage_score'] ?? 0), 92);
        $item['roi_score'] = max((int) ($item['roi_score'] ?? 0), 90);
        $item['risk_penalty'] = min((int) ($item['risk_penalty'] ?? 0), 24);
        $item['rejection_reason'] = '';

        return $item;
    }

    /**
     * @param  array<string,mixed>  $source
     * @return array{runtime:string,test:string}
     */
    private function materializationPathsFromSource(array $source): array
    {
        $files = $this->files($source);
        $runtime = $files[0] ?? '';
        $evidence = is_array($source['completion_evidence'] ?? null) ? $source['completion_evidence'] : [];
        if ($runtime === '' && is_string($evidence['service'] ?? null)) {
            $runtime = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/'.ltrim((string) $evidence['service'], '/').'.php';
        }
        $test = is_string($evidence['test'] ?? null) ? (string) $evidence['test'] : '';
        if ($test === '' && $runtime !== '') {
            $basename = basename($runtime, '.php');
            $test = 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/'.$basename.'Test.php';
        }

        return ['runtime' => $runtime, 'test' => $test];
    }

    /**
     * @param  list<array<string,mixed>>  $ranked
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function priorityBacklogMaterializationReport(array $ranked, array $input): array
    {
        $executable = array_values(array_filter(
            $ranked,
            static fn (array $item): bool => ($item['priority_backlog_materializable'] ?? false) === true
                && strtolower((string) ($item['lane'] ?? '')) === 'now'
                && strtolower((string) ($item['completion_status'] ?? 'pending')) !== 'completed',
        ));

        $categories = array_values(array_unique(array_filter(array_map(
            static fn (array $item): string => (string) ($item['priority_backlog_unlock_category'] ?? ''),
            $executable,
        ))));

        $rejectionReasons = array_values(array_filter((array) ($input['terminal_backlog_rejection_reasons'] ?? []), 'is_string'));
        $replenishmentGeneratedIds = array_values(array_map(
            static fn (array $item): string => (string) ($item['item_id'] ?? ''),
            array_filter(
                $ranked,
                static fn (array $item): bool => ($item['priority_backlog_replenishment_anchor'] ?? false) === true
                    && str_starts_with((string) ($item['item_id'] ?? ''), 'terminal_backlog_replenish_'),
            ),
        ));

        return [
            'schema_version' => 'atlas.software_company_stewardship.priority_backlog_materialization.v1',
            'terminal_backlog_rebalance' => $this->terminalBacklogRebalanceActive($input),
            'terminal_backlog_state_hash' => trim((string) ($input['terminal_backlog_state_hash'] ?? '')),
            'terminal_backlog_rejection_reason_count' => count($rejectionReasons),
            'executable_unlock_count' => count($executable),
            'executable_unlock_categories' => $categories,
            'executable_unlock_ids' => array_values(array_map(
                static fn (array $item): string => (string) ($item['item_id'] ?? ''),
                $executable,
            )),
            'replenishment_generated_count' => count($replenishmentGeneratedIds),
            'replenishment_generated_ids' => $replenishmentGeneratedIds,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $reason, string $detail): array
    {
        return [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-785',
            'status' => self::STATUS_BLOCKED,
            'area_id' => $areaId,
            'reason' => $reason,
            'detail' => $detail,
            'blockers' => [$reason],
            'claim_policy' => [
                'provider_invoked' => false,
                'branch_created' => false,
                'merge_performed' => false,
            ],
            'generated_at' => $this->now(),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function canonicalSeedCandidates(): array
    {
        return [
            [
                'id' => 'AP-783',
                'title' => 'AP-783 integration lane promotion',
                'ap_contract' => 'AP-783',
                'type' => 'integration_lane_promotion',
                'evidence_refs' => ['ap782_integration_lane', 'ap780_review_packet'],
                'dependency_unlocks' => ['owner_runtime_execution', 'truth_surface', '24h_loop'],
                'operator_touchpoints_reduced' => 4,
                'completion_status' => $this->canonicalCompletionStatus([
                    'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipIntegrationLanePromotionService.php',
                    'app/Console/Commands/AtlasSoftwareCompanyIntegrationLanePromoteCommand.php',
                    'docs/ap/AP-783-stewardship-integration-lane-promotion-contract.md',
                    'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipIntegrationLanePromotionServiceTest.php',
                ]),
                'completion_evidence' => [
                    'service' => 'StewardshipIntegrationLanePromotionService',
                    'cli' => 'atlas:software-company-stewardship:integration-lane-promote',
                    'contract' => 'docs/ap/AP-783-stewardship-integration-lane-promotion-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipIntegrationLanePromotionServiceTest.php',
                ],
            ],
            [
                'id' => 'live_cycle_audit_truth_surface',
                'title' => 'Live cycle audit / truth surface',
                'ap_contract' => 'AP-784',
                'type' => 'live_cycle_audit',
                'evidence_refs' => ['cycle_receipt', 'runner_receipt'],
                'dependency_unlocks' => ['no_false_complete', 'operator_visibility', 'provider_boundary_review'],
                'operator_touchpoints_reduced' => 3,
                'completion_status' => $this->canonicalCompletionStatus([
                    'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipLiveCycleAuditService.php',
                    'app/Console/Commands/AtlasSoftwareCompanyLiveCycleAuditCommand.php',
                    'docs/ap/AP-784-stewardship-live-cycle-audit-contract.md',
                    'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipLiveCycleAuditServiceTest.php',
                ]),
                'completion_evidence' => [
                    'service' => 'StewardshipLiveCycleAuditService',
                    'cli' => 'atlas:software-company-stewardship:live-cycle-audit',
                    'contract' => 'docs/ap/AP-784-stewardship-live-cycle-audit-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipLiveCycleAuditServiceTest.php',
                ],
            ],
            [
                'id' => 'owner_runtime_real_execution_bridge',
                'title' => 'Owner runtime real execution bridge',
                'ap_contract' => 'AP-767',
                'type' => 'owner_runtime_real_execution_bridge',
                'evidence_refs' => ['ap765_contract', 'ap767_contract'],
                'dependency_unlocks' => ['real_dev_forge_result', 'cycle_closure'],
                'operator_touchpoints_reduced' => 3,
                'completion_status' => $this->canonicalCompletionStatus([
                    'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php',
                    'docs/ap/AP-767-dev-forge-runtime-execution-bridge-contract.md',
                    'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutorTest.php',
                ]),
                'completion_evidence' => [
                    'service' => 'OwnerFlow/Ap786OwnerFlowExecutor',
                    'contract' => 'docs/ap/AP-767-dev-forge-runtime-execution-bridge-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutorTest.php',
                ],
            ],
            [
                'id' => 'continuous_24h_scheduler',
                'title' => '24h scheduler with budgets/locks/kill switch',
                'ap_contract' => 'AP-790',
                'type' => '24h_scheduler',
                'evidence_refs' => ['ap766_runner'],
                'dependency_unlocks' => ['continuous_operation', 'budgeted_loop'],
                'operator_touchpoints_reduced' => 2,
                'completion_status' => $this->canonicalCompletionStatus([
                    'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
                    'docs/ap/AP-790-reliable-24h-autonomous-loop-runner-contract.md',
                    'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerServiceTest.php',
                ]),
                'completion_evidence' => [
                    'service' => 'Reliable24hLoopRunnerService',
                    'contract' => 'docs/ap/AP-790-reliable-24h-autonomous-loop-runner-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerServiceTest.php',
                ],
            ],
            [
                'id' => 'product_mode_controls_receipts',
                'title' => 'Product Mode controls/receipts',
                'ap_contract' => 'AP-755',
                'type' => 'product_mode_controls',
                'evidence_refs' => ['ap754_controls', 'ap755_receipts'],
                'dependency_unlocks' => ['operator_policy_visibility'],
                'operator_touchpoints_reduced' => 2,
                'completion_status' => $this->canonicalCompletionStatus([
                    'app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptService.php',
                    'docs/ap/AP-755-product-mode-operational-control-receipts-contract.md',
                    'tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptServiceTest.php',
                ]),
                'completion_evidence' => [
                    'service' => 'ProductMode/ProductModeOperationalControlReceiptService',
                    'contract' => 'docs/ap/AP-755-product-mode-operational-control-receipts-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptServiceTest.php',
                ],
            ],
            [
                'id' => 'provider_routing_after_owner_boundaries',
                'title' => 'Provider routing authority bridge after owner boundaries',
                'ap_contract' => 'AP-789',
                'type' => 'safety_robustness_unlock',
                'evidence_refs' => ['ap787_forge_owner_runtime_dispatch', 'ap789_forge_authority_bootstrap'],
                'requires_provider_without_sandbox' => false,
                'dependency_unlocks' => ['provider_optimization', 'real_forge_authority', 'atlas_decide_topology'],
                'operator_touchpoints_reduced' => 3,
                'completion_status' => 'pending',
                'completion_evidence' => [
                    'service' => 'ForgeLiveAuthorityBootstrapService',
                    'contract' => 'docs/ap/AP-787-forge-owner-runtime-dispatch-bridge-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapServiceTest.php',
                ],
            ],
            [
                'id' => 'owner_senior_loop_repair_after_authority_blocker',
                'title' => 'Owner senior loop repair after authority blocker',
                'ap_contract' => 'AP-786',
                'type' => 'safety_robustness_unlock',
                'evidence_refs' => ['ap786_failed_gate_capsule', 'owner_runtime_senior_loop_execution_not_passed'],
                'requires_provider_without_sandbox' => false,
                'dependency_unlocks' => ['repair_loop', 'candidate_refill', 'provider_authority_progress'],
                'operator_touchpoints_reduced' => 3,
                'completion_status' => 'pending',
                'completion_evidence' => [
                    'service' => 'OwnerFlow/Ap786OwnerFlowExecutor',
                    'contract' => 'docs/ap/AP-786-autonomous-evolution-session-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutorTest.php',
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $relativePaths
     */
    private function canonicalCompletionStatus(array $relativePaths): string
    {
        foreach ($relativePaths as $path) {
            if (! is_file($this->repoPath($path))) {
                return 'pending';
            }
        }

        return 'completed';
    }

    private function repoPath(string $relativePath): string
    {
        $root = function_exists('base_path') ? base_path() : getcwd();

        return rtrim((string) $root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($relativePath, DIRECTORY_SEPARATOR);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['priority_hash'], $copy['generated_at']);

        return $copy;
    }

    /**
     * Step 3 of 3 — first ranking rule only: when rank input carries degraded
     * context certification, boost candidates tagged context_memory_retrieval_gap.
     * Per-candidate kinds and remaining rules are future steps.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $rankedItem
     * @return array<string,mixed>
     */
    private function applyContextQualityPriorityBoost(array $input, array $candidate, array $rankedItem): array
    {
        $contextInput = $input['context_quality_score'] ?? null;
        if (! is_array($contextInput) || $contextInput === []) {
            return $rankedItem;
        }

        if ($this->candidateFindingKind($candidate) !== ContextQualityScoreContract::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP) {
            return $rankedItem;
        }

        $contextQuality = $this->contextQualityScore(array_merge(
            $contextInput,
            ['finding_kind' => ContextQualityScoreContract::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP],
        ));
        $boostPoints = (int) ($contextQuality['outputs']['priority_boost_points'] ?? 0);
        if ($boostPoints <= 0) {
            return $rankedItem;
        }

        $boostedScore = round(min(100.0, (float) ($rankedItem['final_priority_score'] ?? 0.0) + $boostPoints), 2);
        $rankedItem['final_priority_score'] = $boostedScore;
        $rankedItem['priority_score'] = $boostedScore;
        $rankedItem['context_quality_priority_boost_points'] = $boostPoints;
        $rankedItem['reason_machine'] = array_values(array_unique(array_merge(
            (array) ($rankedItem['reason_machine'] ?? []),
            ['context_quality_priority_boost'],
        )));
        if (is_array($rankedItem['score_breakdown'] ?? null)) {
            $rankedItem['score_breakdown']['context_quality_priority_boost_points'] = $boostPoints;
            $rankedItem['score_breakdown']['final_priority_score'] = $boostedScore;
        }

        return $rankedItem;
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function candidateFindingKind(array $candidate): string
    {
        foreach (['finding_kind', 'kind', 'type', 'classification'] as $key) {
            $value = strtolower(trim((string) ($candidate[$key] ?? '')));
            if ($value === ContextQualityScoreContract::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP) {
                return ContextQualityScoreContract::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP;
            }
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function validateContextQualityScoreInput(array $input): array
    {
        $validated = [];
        foreach ([
            'area_id',
            'focus',
            'certification_quality_score',
            'certification_target_score',
            'certification_status',
            'finding_kind',
        ] as $key) {
            if (array_key_exists($key, $input)) {
                $validated[$key] = $input[$key];
            }
        }

        return $validated;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
