<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsDecideSignalProjectionService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderPerformanceLedgerService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Atlas Decide · Meta-Learning Loop Closure.
 *
 * Turns the advisory output of AtlasForgeRivalsDecideSignalProjectionService
 * into an authoritative (but operator-gated) routing recommendation that
 * Atlas Decide can consume per (task_category, role, framework) tuple.
 *
 * Authority doc:
 *   docs/engineering-knowledge-base/atlas-decide-meta-learning-loop-closure.md
 *
 * Hard invariants:
 *   - never calls a provider;
 *   - default mode for every recommendation is `shadow` — operator activates;
 *   - low confidence / stale data / close races stay shadow;
 *   - human_review_required signal blocks routing change;
 *   - rollback is one append-only `reset` receipt away.
 *
 * Schemas:
 *   - atlas.atlas_decide.routing_recommendation.v1  (per scope)
 *   - atlas.atlas_decide.routing_table.v1           (active fold)
 *   - atlas.atlas_decide.routing_activation.v1      (audit receipt)
 */
class AtlasDecideMetaLearningService
{
    public const RECOMMENDATION_SCHEMA = 'atlas.atlas_decide.routing_recommendation.v1';

    public const TABLE_SCHEMA = 'atlas.atlas_decide.routing_table.v1';

    public const ACTIVATION_SCHEMA = 'atlas.atlas_decide.routing_activation.v1';

    public const ADVISORY_MAP_SCHEMA = 'atlas.atlas_decide.rivals_advisory_map.v1';

    public const COST_OUTCOME_SCHEMA = 'atlas.atlas_decide.cost_outcome_route.v1';

    public const MODE_SHADOW = 'shadow';

    public const MODE_ACTIVE = 'active';

    public const ACTION_ACTIVATE = 'activate';

    public const ACTION_DEACTIVATE = 'deactivate';

    public const ACTION_RESET = 'reset';

    /** Delta below which we keep the recommendation in shadow even with high confidence. */
    public const CLOSE_RACE_DELTA = 3.0;

    public const ROUTING_BASIS_SCORE = 'score';

    public const ROUTING_BASIS_COST_OUTCOME = 'cost_outcome';

    public const AUTO_DEACTIVATION_SCHEMA = 'atlas.atlas_decide.auto_deactivation_sweep.v1';

    private ?string $activationLogPathOverride = null;

    private ?AtlasDecideLiveOutcomeFeedbackService $liveFeedback = null;

    public function __construct(
        private readonly AtlasForgeRivalsDecideSignalProjectionService $signalProjection,
        private readonly AtlasForgeRivalsProviderPerformanceLedgerService $ledger,
    ) {}

    /**
     * Opt-in seam wired by AppServiceProvider: when set, ADML can consult
     * the live outcome feedback ledger and auto-deactivate routes whose
     * observed success rate drops below the degradation threshold.
     */
    public function setLiveOutcomeFeedback(?AtlasDecideLiveOutcomeFeedbackService $svc): void
    {
        $this->liveFeedback = $svc;
    }

    /** Test seam: override the activation log path. */
    public function setActivationLogPathForTesting(?string $path): void
    {
        $this->activationLogPathOverride = $path;
    }

    public function activationLogPath(): string
    {
        if ($this->activationLogPathOverride !== null) {
            return $this->activationLogPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/atlas_decide')
            : sys_get_temp_dir().'/atlas/atlas_decide';

        return $base.DIRECTORY_SEPARATOR.'routing_activations.jsonl';
    }

    /**
     * Build a recommendation for one scope.
     *
     * @param  array{task_category:string,role:string,framework?:?string}  $scope
     * @return array<string,mixed>
     */
    public function recommend(array $scope): array
    {
        $taskCategory = (string) ($scope['task_category'] ?? '');
        $role = (string) ($scope['role'] ?? '');
        $framework = $scope['framework'] ?? null;
        if ($framework === '') {
            $framework = null;
        }

        $signal = $this->signalProjection->project([
            'task_category' => $taskCategory,
            'role' => $role,
            'framework' => $framework ?? '',
        ]);

        return $this->recommendationFromSignal($signal, $taskCategory, $role, $framework);
    }

    /**
     * Build recommendations for every (task_category, role) cross-product
     * that has at least one valid ledger entry.
     *
     * @return list<array<string,mixed>>
     */
    public function recommendAll(): array
    {
        $entries = $this->ledger->loadEntries();
        $seen = [];
        $out = [];
        foreach ($entries as $e) {
            $task = (string) ($e['task_category'] ?? '');
            $role = (string) ($e['role'] ?? '');
            if ($task === '' || $role === '') {
                continue;
            }
            $framework = $e['framework'] ?? null;
            $key = $task.'|'.$role.'|'.($framework ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $this->recommend([
                'task_category' => $task,
                'role' => $role,
                'framework' => $framework,
            ]);
        }
        usort($out, static fn ($a, $b) => strcmp(
            $a['scope']['task_category'].$a['scope']['role'].($a['scope']['framework'] ?? ''),
            $b['scope']['task_category'].$b['scope']['role'].($b['scope']['framework'] ?? '')
        ));

        return $out;
    }

    /**
     * Build the read-only segment map Atlas Decide can inspect before making
     * its own routing decision. This is deliberately not an activation table:
     * Rivals emits measured evidence; Atlas Decide decides model routing.
     *
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function rivalsAdvisoryMap(array $filters = []): array
    {
        $map = $this->signalProjection->map($filters);
        $segments = [];

        foreach ((array) ($map['segments'] ?? []) as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $segments[] = [
                'scope' => [
                    'task_category' => $segment['task_category'] ?? null,
                    'difficulty_level' => $segment['difficulty_level'] ?? null,
                    'role' => $segment['role'] ?? null,
                ],
                'recommended_provider' => $segment['top_measured_provider'] ?? null,
                'recommended_model' => $segment['top_measured_model'] ?? null,
                'average_score' => $segment['top_average_score'] ?? null,
                'median_score' => $segment['top_median_score'] ?? null,
                'evidence_count' => (int) ($segment['top_valid_count'] ?? 0),
                'confidence' => $segment['top_confidence'] ?? AtlasForgeRivalsProviderPerformanceLedgerService::CONFIDENCE_INSUFFICIENT,
                'decision_readiness' => $segment['decision_readiness'] ?? 'insufficient_evidence',
                'advantage_band' => $segment['advantage_band'] ?? 'unknown',
                'score_stability' => $segment['top_score_stability'] ?? null,
                'cost_estimate' => $segment['top_average_cost_estimate'] ?? null,
                'duration_ms' => $segment['top_average_duration_ms'] ?? null,
                'tokens_used' => $segment['top_average_tokens_used'] ?? null,
                'runner_up' => $segment['runner_up'] ?? null,
                'gap_vs_runner_up' => $segment['gap_vs_runner_up'] ?? null,
                'should_explore_alternative' => (bool) ($segment['should_explore_alternative'] ?? true),
                'statistical_repeat_ready' => (bool) ($segment['statistical_repeat_ready'] ?? false),
                'missing_valid_repetitions' => (int) ($segment['missing_valid_repetitions'] ?? 0),
                'activation_mode' => self::MODE_SHADOW,
                'actionable_for_auto_routing' => false,
                'advisory_only' => true,
                'should_update_provider_topology' => false,
                'never_changes_atlas_decide_topology' => true,
                'owner_of_model_routing' => 'atlas_decide',
                'routing_effect' => 'none',
            ];
        }

        $envelope = [
            'schema_version' => self::ADVISORY_MAP_SCHEMA,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'source_schema_version' => $map['schema_version'] ?? null,
            'source_signal' => $map['signal'] ?? AtlasForgeRivalsDecideSignalProjectionService::SIGNAL_INSUFFICIENT,
            'filters' => $map['filters'] ?? [],
            'segment_count' => count($segments),
            'segments' => $segments,
            'statistical_repeat_readiness' => $map['statistical_repeat_readiness'] ?? null,
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
        ];
        $envelope['advisory_map_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema_version' => self::ADVISORY_MAP_SCHEMA,
            'source_schema_version' => $envelope['source_schema_version'],
            'filters' => $envelope['filters'],
            'segments' => $segments,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $envelope;
    }

    /**
     * Apply a routing action and append an audit receipt.
     *
     * @param  array{action:string,task_category?:string,role?:string,framework?:?string,actor?:string}  $input
     * @return array<string,mixed> the activation receipt that was written
     */
    public function applyAction(array $input): array
    {
        $action = (string) ($input['action'] ?? '');
        if (! in_array($action, [self::ACTION_ACTIVATE, self::ACTION_DEACTIVATE, self::ACTION_RESET], true)) {
            throw new InvalidArgumentException("Unknown action '{$action}'.");
        }
        $actor = (string) ($input['actor'] ?? 'operator');
        $at = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        if ($action === self::ACTION_RESET) {
            $receipt = [
                'schema_version' => self::ACTIVATION_SCHEMA,
                'action' => self::ACTION_RESET,
                'task_category' => null,
                'role' => null,
                'framework' => null,
                'previous_mode' => null,
                'new_mode' => null,
                'actor' => $actor,
                'at' => $at,
                'recommendation_hash_at_activation' => null,
            ];
            $this->appendReceipt($receipt);

            return $receipt;
        }

        $taskCategory = (string) ($input['task_category'] ?? '');
        $role = (string) ($input['role'] ?? '');
        $framework = $input['framework'] ?? null;
        if ($framework === '') {
            $framework = null;
        }
        if ($taskCategory === '' || $role === '') {
            throw new InvalidArgumentException('task_category and role are required for activate/deactivate.');
        }

        $rec = $this->recommend([
            'task_category' => $taskCategory,
            'role' => $role,
            'framework' => $framework,
        ]);

        if ($action === self::ACTION_ACTIVATE) {
            if (! ($rec['actionable'] ?? false)) {
                throw new InvalidArgumentException(
                    'Recommendation is not actionable: '.implode(',', (array) ($rec['reason'] ?? []))
                );
            }
        }

        $previousMode = $this->currentModeFor($taskCategory, $role, $framework);
        $newMode = $action === self::ACTION_ACTIVATE ? self::MODE_ACTIVE : self::MODE_SHADOW;

        $receipt = [
            'schema_version' => self::ACTIVATION_SCHEMA,
            'action' => $action,
            'task_category' => $taskCategory,
            'role' => $role,
            'framework' => $framework,
            'previous_mode' => $previousMode,
            'new_mode' => $newMode,
            'actor' => $actor,
            'at' => $at,
            'recommendation_hash_at_activation' => $rec['recommendation_hash'] ?? null,
            'routing_basis' => $rec['routing_basis'] ?? self::ROUTING_BASIS_SCORE,
            'recommended_provider' => $rec['recommended_provider'] ?? null,
            'recommended_model' => $rec['recommended_model'] ?? null,
            'fallback_provider' => $rec['fallback_provider'] ?? null,
            'fallback_model' => $rec['fallback_model'] ?? null,
            'estimated_savings_pct' => $rec['estimated_savings_pct'] ?? null,
            'cost_outcome_status' => data_get($rec, 'cost_outcome.status'),
        ];
        $this->appendReceipt($receipt);

        return $receipt;
    }

    /**
     * Deterministic fold of activation receipts into the live routing table.
     *
     * @return array<string,mixed>
     */
    public function routingTable(): array
    {
        $receipts = $this->loadReceipts();
        $state = [];
        $resetMark = null;
        foreach ($receipts as $r) {
            if (($r['action'] ?? null) === self::ACTION_RESET) {
                $state = [];
                $resetMark = $r['at'] ?? null;

                continue;
            }
            $task = $r['task_category'] ?? null;
            $role = $r['role'] ?? null;
            if ($task === null || $role === null) {
                continue;
            }
            $framework = $r['framework'] ?? null;
            $key = $task.'|'.$role.'|'.($framework ?? '');
            $state[$key] = $r;
        }

        $entries = [];
        $shadow = 0;
        foreach ($state as $key => $r) {
            $mode = $r['new_mode'] ?? self::MODE_SHADOW;
            if ($mode !== self::MODE_ACTIVE) {
                $shadow++;

                continue;
            }
            $rec = $this->recommend([
                'task_category' => (string) $r['task_category'],
                'role' => (string) $r['role'],
                'framework' => $r['framework'] ?? null,
            ]);
            $entries[] = [
                'task_category' => $r['task_category'],
                'role' => $r['role'],
                'framework' => $r['framework'] ?? null,
                'provider' => $rec['recommended_provider'] ?? null,
                'model' => $rec['recommended_model'] ?? null,
                'mode' => self::MODE_ACTIVE,
                'routing_basis' => $rec['routing_basis'] ?? ($r['routing_basis'] ?? self::ROUTING_BASIS_SCORE),
                'fallback_provider' => $rec['fallback_provider'] ?? ($r['fallback_provider'] ?? null),
                'fallback_model' => $rec['fallback_model'] ?? ($r['fallback_model'] ?? null),
                'estimated_savings_pct' => $rec['estimated_savings_pct'] ?? ($r['estimated_savings_pct'] ?? null),
                'certification_rate' => data_get($rec, 'cost_outcome.selected.certification_rate'),
                'activated_at' => $r['at'] ?? null,
                'activated_by' => $r['actor'] ?? 'operator',
            ];
        }
        usort($entries, static fn ($a, $b) => strcmp(
            $a['task_category'].$a['role'].($a['framework'] ?? ''),
            $b['task_category'].$b['role'].($b['framework'] ?? '')
        ));

        $envelope = [
            'schema_version' => self::TABLE_SCHEMA,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'active_entries' => count($entries),
            'shadow_entries' => $shadow,
            'last_reset_at' => $resetMark,
            'entries' => $entries,
        ];
        $envelope['table_hash'] = $this->tableHash($envelope);

        return $envelope;
    }

    /**
     * Look up the active routing for a scope, if any.
     * Returns null when the scope falls back to native Atlas Decide policy.
     *
     * @return array<string,mixed>|null
     */
    public function activeRouteFor(string $taskCategory, string $role, ?string $framework = null): ?array
    {
        if ($framework === '') {
            $framework = null;
        }
        foreach ($this->routingTable()['entries'] as $e) {
            if (($e['task_category'] ?? null) === $taskCategory
                && ($e['role'] ?? null) === $role
                && ($e['framework'] ?? null) === $framework
                && ($e['provider'] ?? null) !== null
                && ($e['model'] ?? null) !== null) {
                return [
                    'provider' => (string) $e['provider'],
                    'model' => (string) $e['model'],
                    'routing_basis' => $e['routing_basis'] ?? self::ROUTING_BASIS_SCORE,
                    'fallback_provider' => $e['fallback_provider'] ?? null,
                    'fallback_model' => $e['fallback_model'] ?? null,
                    'estimated_savings_pct' => $e['estimated_savings_pct'] ?? null,
                    'certification_rate' => $e['certification_rate'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * Sweep all active routes through the live outcome feedback ledger and
     * auto-deactivate those whose signal is `degrading` or `broken`. Provides
     * the closed feedback loop: ADML learns offline → activates → live calls
     * degrade → ADML deactivates without operator intervention.
     *
     * Each deactivation goes through {@see self::applyAction()} so the audit
     * trail is identical to manual operator action (with actor=actor arg).
     *
     * @return array<string,mixed> sweep envelope
     */
    public function autoDeactivateOnDegradation(string $actor = 'autonomous_feedback_loop'): array
    {
        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        $inspected = [];
        $deactivated = [];
        $kept = [];
        $signalSchema = AtlasDecideLiveOutcomeFeedbackService::SIGNAL_SCHEMA;

        if ($this->liveFeedback === null) {
            return [
                'schema_version' => self::AUTO_DEACTIVATION_SCHEMA,
                'generated_at' => $generatedAt,
                'feedback_wired' => false,
                'reason' => 'live_outcome_feedback_not_wired',
                'inspected' => [],
                'deactivated' => [],
                'kept' => [],
            ];
        }

        foreach ($this->routingTable()['entries'] ?? [] as $entry) {
            $task = (string) ($entry['task_category'] ?? '');
            $role = (string) ($entry['role'] ?? '');
            $framework = $entry['framework'] ?? null;
            $provider = (string) ($entry['provider'] ?? '');
            $model = $entry['model'] ?? null;
            if ($task === '' || $role === '' || $provider === '') {
                continue;
            }

            $signal = $this->liveFeedback->degradationSignal($task, $role, $framework, $provider, $model);
            $sig = (string) ($signal['signal'] ?? '');
            $inspected[] = [
                'task_category' => $task,
                'role' => $role,
                'framework' => $framework,
                'provider' => $provider,
                'model' => $model,
                'signal' => $sig,
                'sample_size' => (int) ($signal['sample_size'] ?? 0),
                'success_rate' => $signal['success_rate'] ?? null,
                'envelope_schema' => $signalSchema,
            ];

            if (in_array($sig, [
                AtlasDecideLiveOutcomeFeedbackService::SIGNAL_DEGRADING,
                AtlasDecideLiveOutcomeFeedbackService::SIGNAL_BROKEN,
            ], true)) {
                try {
                    $receipt = $this->applyAction([
                        'action' => self::ACTION_DEACTIVATE,
                        'task_category' => $task,
                        'role' => $role,
                        'framework' => $framework,
                        'actor' => $actor,
                    ]);
                    $deactivated[] = [
                        'task_category' => $task,
                        'role' => $role,
                        'framework' => $framework,
                        'provider' => $provider,
                        'model' => $model,
                        'signal' => $sig,
                        'success_rate' => $signal['success_rate'] ?? null,
                        'activation_receipt_at' => $receipt['at'] ?? null,
                    ];
                } catch (\Throwable $e) {
                    // Honest: deactivation may fail (e.g., already deactivated).
                    // Record it as kept with an explanatory tag so the sweep is auditable.
                    $kept[] = [
                        'task_category' => $task,
                        'role' => $role,
                        'framework' => $framework,
                        'provider' => $provider,
                        'model' => $model,
                        'signal' => $sig,
                        'note' => 'deactivation_failed:'.substr($e->getMessage(), 0, 120),
                    ];
                }
            } else {
                $kept[] = [
                    'task_category' => $task,
                    'role' => $role,
                    'framework' => $framework,
                    'provider' => $provider,
                    'model' => $model,
                    'signal' => $sig,
                ];
            }
        }

        $envelope = [
            'schema_version' => self::AUTO_DEACTIVATION_SCHEMA,
            'generated_at' => $generatedAt,
            'feedback_wired' => true,
            'inspected_count' => count($inspected),
            'deactivated_count' => count($deactivated),
            'kept_count' => count($kept),
            'inspected' => $inspected,
            'deactivated' => $deactivated,
            'kept' => $kept,
            'actor' => $actor,
        ];
        $envelope['sweep_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::AUTO_DEACTIVATION_SCHEMA,
            'generated_at' => $generatedAt,
            'deactivated' => array_map(static fn ($d) => [$d['task_category'], $d['role'], $d['framework'], $d['provider']], $deactivated),
        ], JSON_THROW_ON_ERROR));

        return $envelope;
    }

    // ---------- internals ----------

    /**
     * @param  array<string,mixed>  $signal
     */
    private function recommendationFromSignal(array $signal, string $taskCategory, string $role, ?string $framework): array
    {
        $sig = (string) ($signal['signal'] ?? AtlasForgeRivalsDecideSignalProjectionService::SIGNAL_INSUFFICIENT);
        $evidenceCount = (int) ($signal['evidence_count'] ?? 0);
        $confidence = (string) ($signal['confidence'] ?? AtlasForgeRivalsProviderPerformanceLedgerService::CONFIDENCE_INSUFFICIENT);
        $delta = (float) ($signal['top_vs_runner_up_score_gap'] ?? $signal['top_gap_vs_runner_up'] ?? 0.0);
        $latestAgeDays = $this->extractLatestAgeDays($signal);
        $stale = $latestAgeDays !== null && $latestAgeDays > AtlasForgeRivalsProviderPerformanceLedgerService::STALE_AGE_DAYS;
        $requiresHumanReview = $sig === AtlasForgeRivalsDecideSignalProjectionService::SIGNAL_HUMAN_REVIEW
            || (bool) ($signal['should_require_human_review'] ?? false);
        $costOutcome = $this->costOutcomeRoute($taskCategory, $role, $framework);

        $actionableConfidence = in_array(
            $confidence,
            [
                AtlasForgeRivalsProviderPerformanceLedgerService::CONFIDENCE_HIGH,
                AtlasForgeRivalsProviderPerformanceLedgerService::CONFIDENCE_MEDIUM,
            ],
            true
        );

        $reason = [];
        if ($sig === AtlasForgeRivalsDecideSignalProjectionService::SIGNAL_INSUFFICIENT) {
            $reason[] = 'insufficient_evidence';
        }
        if ($requiresHumanReview) {
            $reason[] = 'human_review_required';
        }
        if ($stale) {
            $reason[] = 'stale_evidence';
        }
        if (! $actionableConfidence) {
            $reason[] = 'confidence_below_threshold';
        }
        if ($delta > 0.0 && $delta < self::CLOSE_RACE_DELTA) {
            $reason[] = 'close_race_runner_up_in_play';
        }
        if ($reason === []) {
            $reason[] = 'evidence_strong';
            if ($delta >= self::CLOSE_RACE_DELTA) {
                $reason[] = 'delta_above_threshold';
            }
        }

        $actionable = $actionableConfidence
            && ! $requiresHumanReview
            && ! $stale
            && $sig === AtlasForgeRivalsDecideSignalProjectionService::SIGNAL_OK
            && ($delta === 0.0 || $delta >= self::CLOSE_RACE_DELTA);

        $routingBasis = self::ROUTING_BASIS_SCORE;
        $recommendedProvider = $this->canonicalProviderKey($signal['top_measured_provider'] ?? null);
        $recommendedModel = $this->canonicalModelForProvider($recommendedProvider, $signal['top_measured_model'] ?? null);
        $fallbackProvider = $this->canonicalProviderKey($signal['runner_up_provider'] ?? data_get($signal, 'alternative_measured_candidate.provider'));
        $fallbackModel = $this->canonicalModelForProvider($fallbackProvider, $signal['runner_up_model'] ?? data_get($signal, 'alternative_measured_candidate.model'));
        $estimatedSavingsPct = null;

        if (($costOutcome['enabled'] ?? false) === true) {
            if (($costOutcome['status'] ?? null) === 'ready') {
                $selected = (array) ($costOutcome['selected'] ?? []);
                $fallback = (array) ($costOutcome['fallback'] ?? []);
                $routingBasis = self::ROUTING_BASIS_COST_OUTCOME;
                $recommendedProvider = (string) ($selected['provider'] ?? $recommendedProvider);
                $recommendedModel = (string) ($selected['model'] ?? $recommendedModel);
                $fallbackProvider = $fallback['provider'] ?? $fallbackProvider;
                $fallbackModel = $fallback['model'] ?? $fallbackModel;
                $estimatedSavingsPct = $costOutcome['estimated_savings_pct'] ?? null;
                $actionable = true;
                $requiresHumanReview = false;
                $stale = false;
                $reason = [
                    'cost_outcome_ready',
                    'measured_cost_present',
                    'certification_preserved',
                    'operator_activation_required',
                ];
            } else {
                $actionable = false;
                foreach ((array) ($costOutcome['blockers'] ?? []) as $blocker) {
                    $blocker = (string) $blocker;
                    if ($blocker !== '') {
                        $reason[] = 'cost_outcome_'.$blocker;
                    }
                }
            }
        }

        $rec = [
            'schema_version' => self::RECOMMENDATION_SCHEMA,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'scope' => [
                'task_category' => $taskCategory,
                'role' => $role,
                'framework' => $framework,
            ],
            'signal' => $sig,
            'confidence' => $confidence,
            'evidence_count' => $evidenceCount,
            'stale_evidence' => $stale,
            'latest_age_days' => $latestAgeDays,
            'recommended_provider' => $recommendedProvider,
            'recommended_model' => $recommendedModel,
            'runner_up_provider' => $fallbackProvider,
            'runner_up_model' => $fallbackModel,
            'fallback_provider' => $fallbackProvider,
            'fallback_model' => $fallbackModel,
            'delta' => $delta,
            'use_full_power' => (bool) ($signal['should_use_full_power'] ?? false),
            'mode' => $this->currentModeFor($taskCategory, $role, $framework),
            'actionable' => $actionable,
            'requires_human_review' => $requiresHumanReview,
            'reason' => array_values(array_unique($reason)),
            'routing_basis' => $routingBasis,
            'estimated_savings_pct' => $estimatedSavingsPct,
            'cost_outcome' => $costOutcome,
            'rationale' => $this->rationale($signal, $actionable, $reason),
        ];
        $rec['recommendation_hash'] = $this->recommendationHash($rec);

        return $rec;
    }

    private function extractLatestAgeDays(array $signal): ?int
    {
        if (isset($signal['latest_age_days'])) {
            return (int) $signal['latest_age_days'];
        }
        if (! empty($signal['latest_run_ids'])) {
            // best-effort: caller already filtered to valid_for_ranking entries.
            return 0;
        }

        return null;
    }

    private function rationale(array $signal, bool $actionable, array $reason): string
    {
        if ($actionable) {
            return sprintf(
                'Measured-best for this scope: %s/%s with %d evidence entries (confidence=%s, delta=%.2f).',
                (string) ($signal['top_measured_provider'] ?? '—'),
                (string) ($signal['top_measured_model'] ?? '—'),
                (int) ($signal['evidence_count'] ?? 0),
                (string) ($signal['confidence'] ?? '—'),
                (float) ($signal['top_vs_runner_up_score_gap'] ?? 0.0)
            );
        }

        return 'Recommendation kept in shadow: '.implode(', ', $reason).'.';
    }

    private function currentModeFor(string $taskCategory, string $role, ?string $framework): string
    {
        foreach (array_reverse($this->loadReceipts()) as $r) {
            if (($r['action'] ?? null) === self::ACTION_RESET) {
                return self::MODE_SHADOW;
            }
            if (($r['task_category'] ?? null) === $taskCategory
                && ($r['role'] ?? null) === $role
                && (($r['framework'] ?? null) === $framework)) {
                return (string) ($r['new_mode'] ?? self::MODE_SHADOW);
            }
        }

        return self::MODE_SHADOW;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadReceipts(): array
    {
        $path = $this->activationLogPath();
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function appendReceipt(array $receipt): void
    {
        AppendOnlyJsonlStore::append($this->activationLogPath(), $receipt);
    }

    private function recommendationHash(array $rec): string
    {
        $canonical = [
            'schema' => self::RECOMMENDATION_SCHEMA,
            'scope' => $rec['scope'],
            'signal' => $rec['signal'],
            'confidence' => $rec['confidence'],
            'evidence_count' => $rec['evidence_count'],
            'stale' => $rec['stale_evidence'],
            'provider' => $rec['recommended_provider'],
            'model' => $rec['recommended_model'],
            'delta' => $rec['delta'],
            'actionable' => $rec['actionable'],
            'routing_basis' => $rec['routing_basis'] ?? self::ROUTING_BASIS_SCORE,
            'estimated_savings_pct' => $rec['estimated_savings_pct'] ?? null,
        ];

        return 'sha256:'.hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    private function tableHash(array $envelope): string
    {
        $canonical = [
            'schema' => self::TABLE_SCHEMA,
            'entries' => array_map(static fn (array $e): array => [
                'task_category' => $e['task_category'],
                'role' => $e['role'],
                'framework' => $e['framework'],
                'provider' => $e['provider'],
                'model' => $e['model'],
                'mode' => $e['mode'],
                'routing_basis' => $e['routing_basis'] ?? self::ROUTING_BASIS_SCORE,
            ], $envelope['entries']),
            'last_reset_at' => $envelope['last_reset_at'],
        ];

        return 'sha256:'.hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    /**
     * Cost×outcome routing is a guardrail on top of the existing ADML table,
     * not another router: the same activation receipt and gateway consult path
     * remain authoritative.
     *
     * @return array<string,mixed>
     */
    private function costOutcomeRoute(string $taskCategory, string $role, ?string $framework): array
    {
        $cfg = $this->costOutcomeConfig();
        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        $base = [
            'schema_version' => self::COST_OUTCOME_SCHEMA,
            'generated_at' => $generatedAt,
            'enabled' => (bool) $cfg['enabled'],
            'status' => 'disabled',
            'scope' => [
                'task_category' => $taskCategory,
                'role' => $role,
                'framework' => $framework,
            ],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'mutates_routing_table' => false,
            'activation_still_requires_operator_receipt' => true,
        ];

        if (! (bool) $cfg['enabled']) {
            return $base;
        }

        $entries = $this->relevantCostOutcomeEntries($taskCategory, $role, $framework);
        if ($entries === []) {
            return array_replace($base, [
                'status' => 'blocked',
                'blockers' => ['no_relevant_cost_outcome_evidence'],
                'candidate_count' => 0,
                'candidates' => [],
            ]);
        }

        $candidates = $this->costOutcomeCandidates($entries, $cfg);
        $blockers = [];
        foreach ($candidates as $candidate) {
            foreach ((array) ($candidate['blockers'] ?? []) as $blocker) {
                $blockers[$blocker] = true;
            }
        }

        $eligible = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => ($candidate['blockers'] ?? []) === []
        ));

        if ($eligible === []) {
            return array_replace($base, [
                'status' => 'blocked',
                'blockers' => array_values(array_keys($blockers ?: ['no_eligible_cost_outcome_candidate' => true])),
                'candidate_count' => count($candidates),
                'candidates' => $candidates,
            ]);
        }

        $bestScore = max(array_map(static fn (array $candidate): float => (float) ($candidate['average_score'] ?? 0.0), $eligible));
        $scoreFloor = max((float) $cfg['min_score'], $bestScore - (float) $cfg['max_score_drop']);
        $scorePreserving = array_values(array_filter(
            $eligible,
            static fn (array $candidate): bool => (float) ($candidate['average_score'] ?? 0.0) >= $scoreFloor
        ));

        if ($scorePreserving === []) {
            return array_replace($base, [
                'status' => 'blocked',
                'blockers' => ['score_floor_not_preserved'],
                'candidate_count' => count($candidates),
                'score_floor' => round($scoreFloor, 4),
                'candidates' => $candidates,
            ]);
        }

        usort($scorePreserving, static function (array $a, array $b): int {
            $cost = ((float) ($a['average_cost_estimate'] ?? INF)) <=> ((float) ($b['average_cost_estimate'] ?? INF));
            if ($cost !== 0) {
                return $cost;
            }
            $score = ((float) ($b['average_score'] ?? 0.0)) <=> ((float) ($a['average_score'] ?? 0.0));
            if ($score !== 0) {
                return $score;
            }

            return ((int) ($b['certified_count'] ?? 0)) <=> ((int) ($a['certified_count'] ?? 0));
        });

        $selected = $scorePreserving[0];
        $fallbackPool = $scorePreserving;
        usort($fallbackPool, static function (array $a, array $b): int {
            $score = ((float) ($b['average_score'] ?? 0.0)) <=> ((float) ($a['average_score'] ?? 0.0));
            if ($score !== 0) {
                return $score;
            }

            return ((int) ($b['certified_count'] ?? 0)) <=> ((int) ($a['certified_count'] ?? 0));
        });
        $fallback = $fallbackPool[0];
        $selectedCost = (float) ($selected['average_cost_estimate'] ?? 0.0);
        $fallbackCost = (float) ($fallback['average_cost_estimate'] ?? 0.0);
        $estimatedSavingsPct = $fallbackCost > 0.0
            ? round(max(0.0, (1.0 - ($selectedCost / $fallbackCost)) * 100.0), 2)
            : null;

        return array_replace($base, [
            'status' => 'ready',
            'blockers' => [],
            'candidate_count' => count($candidates),
            'eligible_candidate_count' => count($eligible),
            'score_floor' => round($scoreFloor, 4),
            'selected' => $selected,
            'fallback' => $fallback,
            'estimated_savings_pct' => $estimatedSavingsPct,
            'candidates' => $candidates,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function costOutcomeConfig(): array
    {
        $cfg = function_exists('config') ? (array) config('atlas.patamar4.adml_cost_outcome', []) : [];

        return [
            'enabled' => (bool) ($cfg['enabled'] ?? false),
            'min_evidence' => max(1, (int) ($cfg['min_evidence'] ?? 3)),
            'min_certification_rate' => max(0.0, min(1.0, (float) ($cfg['min_certification_rate'] ?? 0.8))),
            'min_score' => max(0.0, min(100.0, (float) ($cfg['min_score'] ?? 80.0))),
            'max_score_drop' => max(0.0, (float) ($cfg['max_score_drop'] ?? 3.0)),
            'require_measured_cost' => (bool) ($cfg['require_measured_cost'] ?? true),
            'min_cost_samples' => max(1, (int) ($cfg['min_cost_samples'] ?? 1)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function relevantCostOutcomeEntries(string $taskCategory, string $role, ?string $framework): array
    {
        return array_values(array_merge(
            $this->relevantLedgerEntries($taskCategory, $role, $framework),
            $this->relevantLiveOutcomeEntries($taskCategory, $role, $framework),
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function relevantLedgerEntries(string $taskCategory, string $role, ?string $framework): array
    {
        $task = strtolower(trim($taskCategory));
        $r = strtolower(trim($role));
        $fw = $framework === null ? null : strtolower(trim($framework));

        return array_values(array_filter($this->ledger->loadEntries(), static function (array $entry) use ($task, $r, $fw): bool {
            if (strtolower((string) ($entry['task_category'] ?? '')) !== $task) {
                return false;
            }
            if (strtolower((string) ($entry['role'] ?? '')) !== $r) {
                return false;
            }
            if ($fw !== null && strtolower((string) ($entry['framework'] ?? '')) !== $fw) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Live feedback is the warm runtime signal for L5-6. A row is admitted into
     * cost×outcome only when it carries both a successful call result and an
     * explicit quality score, so transport success alone never masquerades as
     * outcome certification.
     *
     * @return list<array<string,mixed>>
     */
    private function relevantLiveOutcomeEntries(string $taskCategory, string $role, ?string $framework): array
    {
        if ($this->liveFeedback === null) {
            return [];
        }

        $task = strtolower(trim($taskCategory));
        $r = strtolower(trim($role));
        $fw = $framework === null ? null : strtolower(trim($framework));
        $entries = [];

        foreach ($this->liveFeedback->listOutcomes() as $entry) {
            if (strtolower((string) ($entry['task_category'] ?? '')) !== $task) {
                continue;
            }
            if (strtolower((string) ($entry['role'] ?? '')) !== $r) {
                continue;
            }
            if ($fw !== null && strtolower((string) ($entry['framework'] ?? '')) !== $fw) {
                continue;
            }

            $quality = $entry['quality_score'] ?? null;
            $score = is_numeric($quality) ? max(0.0, min(100.0, (float) $quality * 100.0)) : null;

            $entries[] = [
                'evidence_source' => 'live_outcome_feedback',
                'source_schema_version' => $entry['schema_version'] ?? AtlasDecideLiveOutcomeFeedbackService::OUTCOME_SCHEMA,
                'recorded_at' => $entry['recorded_at'] ?? null,
                'run_id' => $entry['entry_hash'] ?? null,
                'task_category' => $entry['task_category'] ?? null,
                'role' => $entry['role'] ?? null,
                'framework' => $entry['framework'] ?? null,
                'provider' => $entry['provider'] ?? null,
                'model' => $entry['model'] ?? null,
                'result' => $entry['result'] ?? null,
                'score_total' => $score,
                'cost_estimate' => $entry['cost_usd'] ?? null,
                'tokens_used' => $entry['tokens_used'] ?? null,
                'quality_score' => $quality,
                'valid_for_ranking' => ($entry['result'] ?? null) === AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS && $score !== null,
                'tests_passed' => ($entry['result'] ?? null) === AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS && $score !== null,
                'replay_passed' => ($entry['result'] ?? null) === AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS && $score !== null,
                'hard_failures' => [],
            ];
        }

        return $entries;
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @param  array<string,mixed>  $cfg
     * @return list<array<string,mixed>>
     */
    private function costOutcomeCandidates(array $entries, array $cfg): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $provider = $this->canonicalProviderKey($entry['provider'] ?? null);
            $model = $this->canonicalModelForProvider($provider, $entry['model'] ?? null);
            if ($provider === null || $provider === '' || $model === null || $model === '') {
                continue;
            }
            $key = $provider.'|'.$model;
            $groups[$key] ??= [
                'provider' => $provider,
                'model' => $model,
                'total_count' => 0,
                'certified_count' => 0,
                'score_sum' => 0.0,
                'cost_sum' => 0.0,
                'cost_count' => 0,
                'token_sum' => 0,
                'token_count' => 0,
                'latest_recorded_at' => null,
                'latest_run_ids' => [],
                'evidence_sources' => [],
                'provider_resolvable' => $this->isKnownProviderKey($provider),
            ];

            $groups[$key]['total_count']++;
            $recordedAt = (string) ($entry['recorded_at'] ?? '');
            if ($recordedAt !== '' && ($groups[$key]['latest_recorded_at'] === null || $recordedAt > $groups[$key]['latest_recorded_at'])) {
                $groups[$key]['latest_recorded_at'] = $recordedAt;
            }
            $runId = (string) ($entry['run_id'] ?? '');
            if ($runId !== '' && ! in_array($runId, $groups[$key]['latest_run_ids'], true)) {
                $groups[$key]['latest_run_ids'][] = $runId;
            }
            $source = (string) ($entry['evidence_source'] ?? 'forge_rivals_provider_performance_ledger');
            if ($source !== '' && ! in_array($source, $groups[$key]['evidence_sources'], true)) {
                $groups[$key]['evidence_sources'][] = $source;
            }

            if (! $this->isCertifiedCostOutcomeEntry($entry)) {
                continue;
            }

            $groups[$key]['certified_count']++;
            $groups[$key]['score_sum'] += (float) ($entry['score_total'] ?? 0.0);

            $cost = $this->numericCost($entry['cost_estimate'] ?? null);
            if ($cost !== null) {
                $groups[$key]['cost_sum'] += $cost;
                $groups[$key]['cost_count']++;
            }
            if (isset($entry['tokens_used']) && is_numeric($entry['tokens_used']) && (int) $entry['tokens_used'] > 0) {
                $groups[$key]['token_sum'] += (int) $entry['tokens_used'];
                $groups[$key]['token_count']++;
            }
        }

        $candidates = [];
        foreach ($groups as $group) {
            $certifiedCount = (int) $group['certified_count'];
            $totalCount = max(1, (int) $group['total_count']);
            $certificationRate = round($certifiedCount / $totalCount, 4);
            $averageScore = $certifiedCount > 0 ? round((float) $group['score_sum'] / $certifiedCount, 4) : null;
            $averageCost = (int) $group['cost_count'] > 0 ? round((float) $group['cost_sum'] / (int) $group['cost_count'], 6) : null;
            $latestAgeDays = $group['latest_recorded_at'] !== null
                ? $this->ledger->ageDays((string) $group['latest_recorded_at'])
                : null;

            $blockers = [];
            if (! (bool) $group['provider_resolvable']) {
                $blockers[] = 'provider_not_resolvable_by_ai_provider_manager';
            }
            if ($certifiedCount < (int) $cfg['min_evidence']) {
                $blockers[] = 'insufficient_certified_evidence';
            }
            if ($certificationRate < (float) $cfg['min_certification_rate']) {
                $blockers[] = 'certification_rate_below_floor';
            }
            if ($latestAgeDays !== null && $latestAgeDays > AtlasForgeRivalsProviderPerformanceLedgerService::STALE_AGE_DAYS) {
                $blockers[] = 'stale_evidence';
            }
            if ((bool) $cfg['require_measured_cost'] && (int) $group['cost_count'] < (int) $cfg['min_cost_samples']) {
                $blockers[] = 'missing_measured_cost';
            }
            if ($averageScore === null || $averageScore < (float) $cfg['min_score']) {
                $blockers[] = 'score_below_floor';
            }

            $candidates[] = [
                'provider' => $group['provider'],
                'model' => $group['model'],
                'total_count' => $totalCount,
                'certified_count' => $certifiedCount,
                'certification_rate' => $certificationRate,
                'average_score' => $averageScore,
                'average_cost_estimate' => $averageCost,
                'cost_sample_count' => (int) $group['cost_count'],
                'average_tokens_used' => (int) $group['token_count'] > 0 ? (int) round((int) $group['token_sum'] / (int) $group['token_count']) : null,
                'confidence' => $this->ledger->confidenceFor($certifiedCount),
                'latest_recorded_at' => $group['latest_recorded_at'],
                'latest_age_days' => $latestAgeDays,
                'latest_run_ids' => array_slice((array) $group['latest_run_ids'], 0, 5),
                'evidence_sources' => array_values((array) $group['evidence_sources']),
                'provider_resolvable' => (bool) $group['provider_resolvable'],
                'blockers' => array_values(array_unique($blockers)),
            ];
        }

        usort($candidates, static fn (array $a, array $b): int => strcmp($a['provider'].$a['model'], $b['provider'].$b['model']));

        return $candidates;
    }

    /**
     * A route preserves outcome only when the local evidence says the result
     * passed replay/tests and had no hard failure. Human-review ties are still
     * allowed here because this is not a superiority claim; it is a cheaper
     * certified-route choice that remains operator-activated.
     */
    private function isCertifiedCostOutcomeEntry(array $entry): bool
    {
        if (($entry['evidence_source'] ?? null) === 'live_outcome_feedback') {
            return ($entry['result'] ?? null) === AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS
                && is_numeric($entry['quality_score'] ?? null)
                && is_numeric($entry['score_total'] ?? null)
                && (array) ($entry['hard_failures'] ?? []) === [];
        }

        return (bool) ($entry['valid_for_ranking'] ?? false)
            && (bool) ($entry['tests_passed'] ?? false)
            && (bool) ($entry['replay_passed'] ?? false)
            && (array) ($entry['hard_failures'] ?? []) === [];
    }

    private function numericCost(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $cost = (float) $value;

        return $cost > 0.0 ? $cost : null;
    }

    private function canonicalProviderKey(mixed $provider): ?string
    {
        $normalized = strtolower(trim((string) ($provider ?? '')));
        if ($normalized === '') {
            return null;
        }

        $aliases = array_merge([
            'anthropic_claude' => 'claude_cli',
            'claude' => 'claude_cli',
            'claude_code' => 'claude_cli',
            'openai_codex' => 'codex_cli',
            'openai_gpt' => 'codex_cli',
            'codex' => 'codex_cli',
            'minimax' => 'minimax_m27_cli',
            'minimax_m3' => 'minimax_m27_cli',
            'minimax-m3' => 'minimax_m27_cli',
            'minimax_m27' => 'minimax_m27_cli',
            'minimax_m27_cli' => 'minimax_m27_cli',
            'hermes' => 'hermes_cli',
            'hermes_cli' => 'hermes_cli',
            'gemini' => 'gemini_cli',
            'google_gemini' => 'gemini_cli',
        ], $this->configuredProviderAliases());

        return $aliases[$normalized] ?? $normalized;
    }

    /**
     * @return array<string,string>
     */
    private function configuredProviderAliases(): array
    {
        $configured = function_exists('config') ? (array) config('atlas.patamar4.adml_provider_aliases', []) : [];
        $aliases = [];
        foreach ($configured as $from => $to) {
            $from = strtolower(trim((string) $from));
            $to = strtolower(trim((string) $to));
            if ($from !== '' && $to !== '') {
                $aliases[$from] = $to;
            }
        }

        return $aliases;
    }

    private function canonicalModelForProvider(?string $provider, mixed $model): ?string
    {
        $model = trim((string) ($model ?? ''));
        if ($model === '') {
            return null;
        }
        $lower = strtolower($model);
        if ($provider === 'minimax_m27_cli' && ($lower === 'm3' || str_contains($lower, 'minimax'))) {
            return (string) (function_exists('config') ? config('atlas.ai.providers.minimax_m27_cli.model', 'MiniMax-M3') : 'MiniMax-M3');
        }

        return $model;
    }

    private function isKnownProviderKey(string $provider): bool
    {
        $known = [
            'claude_cli',
            'codex_cli',
            'gemini_cli',
            'jarvis_cli',
            'hermes_cli',
            'minimax_m27_cli',
        ];
        if (function_exists('config')) {
            $providers = config('atlas.ai.providers', []);
            if (is_array($providers)) {
                $known = array_values(array_unique(array_merge($known, array_map('strval', array_keys($providers)))));
            }
        }

        return in_array($provider, $known, true);
    }
}
