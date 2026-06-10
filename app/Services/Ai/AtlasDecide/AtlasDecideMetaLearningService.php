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

    public const MODE_SHADOW = 'shadow';

    public const MODE_ACTIVE = 'active';

    public const ACTION_ACTIVATE = 'activate';

    public const ACTION_DEACTIVATE = 'deactivate';

    public const ACTION_RESET = 'reset';

    /** Delta below which we keep the recommendation in shadow even with high confidence. */
    public const CLOSE_RACE_DELTA = 3.0;

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
     * @return array{provider:string,model:string}|null
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
            'recommended_provider' => $signal['top_measured_provider'] ?? null,
            'recommended_model' => $signal['top_measured_model'] ?? null,
            'runner_up_provider' => $signal['runner_up_provider'] ?? data_get($signal, 'alternative_measured_candidate.provider'),
            'runner_up_model' => $signal['runner_up_model'] ?? data_get($signal, 'alternative_measured_candidate.model'),
            'delta' => $delta,
            'use_full_power' => (bool) ($signal['should_use_full_power'] ?? false),
            'mode' => $this->currentModeFor($taskCategory, $role, $framework),
            'actionable' => $actionable,
            'requires_human_review' => $requiresHumanReview,
            'reason' => array_values(array_unique($reason)),
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
            ], $envelope['entries']),
            'last_reset_at' => $envelope['last_reset_at'],
        ];

        return 'sha256:'.hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}
