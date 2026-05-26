<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

use App\Models\AtlasProject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Atlas Self-Improvement Result Ledger v1.
 *
 * Persists the post-Obra outcome of a Self-Improvement proposal:
 * before/after snapshots + delta scorecard + invariant lock + regression
 * sentinel + learning packet + recommended next action. The ledger is the
 * canonical source of "did this proposal actually improve Atlas?".
 *
 * Hard rules:
 *   - Result entry NEVER promotes completion claim.
 *   - Result entry NEVER auto-runs Fast Path on the recommendation.
 *   - Result entry NEVER calls a provider.
 *   - Regression / invalid evidence outcomes block learning promotion AND
 *     update the human trust ledger with the canonical outcome (so the
 *     band drops honestly).
 *   - Recording requires proposal_id + obra_id + before_snapshot.
 *
 * Schemas:
 *   - atlas.self_improvement.result_ledger.v1
 *   - atlas.self_improvement.result_entry.v1
 *   - atlas.self_improvement.learning_packet.v1
 *
 * Storage: local disk at `atlas/self-improvement/result-ledger/`, mirrored
 * best-effort to `atlas_ledger_events`.
 */
class AtlasSelfImprovementResultLedgerService
{
    public const SCHEMA_VERSION = 'atlas.self_improvement.result_ledger.v1';

    public const ENTRY_SCHEMA_VERSION = 'atlas.self_improvement.result_entry.v1';

    public const LEARNING_SCHEMA_VERSION = 'atlas.self_improvement.learning_packet.v1';

    public const GRADE_REGRESSED = 'regressed';

    public const GRADE_NEUTRAL = 'neutral';

    public const GRADE_IMPROVED = 'improved';

    public const GRADE_MAJOR_IMPROVEMENT = 'major_improvement';

    public const GRADE_INVALID = 'invalid';

    /** @var list<string> */
    public const GRADES = [
        self::GRADE_REGRESSED,
        self::GRADE_NEUTRAL,
        self::GRADE_IMPROVED,
        self::GRADE_MAJOR_IMPROVEMENT,
        self::GRADE_INVALID,
    ];

    public const HISTORY_CAP = 100;

    private const STORAGE_DISK = 'local';

    private const STORAGE_PREFIX = 'atlas/self-improvement/result-ledger';

    public function __construct(
        private readonly AtlasSelfImprovementDeltaScorecardService $deltaScorecard,
        private readonly AtlasSelfImprovementInvariantLockService $invariantLock,
        private readonly AtlasSelfImprovementRegressionSentinelService $regressionSentinel,
        private readonly AtlasSelfImprovementHumanTrustLedgerService $humanTrustLedger,
    ) {}

    /**
     * Record a post-Obra result entry. Required inputs:
     *   - proposal_id: backlog id (or activation proposal id)
     *   - obra_id: AtlasProject id of the materialised Obra
     *   - before_snapshot: 13-metric scores baseline (from activation
     *     baseline)
     *   - after_snapshot: 13-metric scores measured AFTER the obra
     *     completed (operator/CI supplies this)
     *   - context (optional): expected_power_gain, human_review_outcome,
     *     accepted_risks, evidence_refs[]
     *
     * NEVER creates an Obra. NEVER promotes completion. Trust ledger is
     * updated as a side-effect with the appropriate self_improvement_*
     * outcome.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function record(array $payload): array
    {
        $proposalId = $this->stringOrNull($payload['proposal_id'] ?? null);
        $obraId = $this->stringOrNull($payload['obra_id'] ?? null);
        $before = is_array($payload['before_snapshot'] ?? null) ? $payload['before_snapshot'] : null;
        $after = is_array($payload['after_snapshot'] ?? null) ? $payload['after_snapshot'] : null;
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $reviewer = $this->stringOrNull($payload['reviewer'] ?? null);
        $reason = $this->stringOrNull($payload['reason'] ?? null);

        $blockers = [];
        if ($proposalId === null) {
            $blockers[] = 'proposal_id_required';
        }
        if ($obraId === null) {
            $blockers[] = 'obra_id_required';
        }
        if ($before === null) {
            $blockers[] = 'before_snapshot_required';
        }
        if ($after === null) {
            $blockers[] = 'after_snapshot_required';
        }
        if ($reviewer === null || $reason === null) {
            $blockers[] = 'reviewer_and_reason_required';
        }

        if ($blockers !== []) {
            return [
                'schema_version' => self::ENTRY_SCHEMA_VERSION,
                'status' => 'blocked',
                'blockers' => $blockers,
                'next_safe_action' => 'provide_missing_fields_then_retry',
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
                'auto_fast_path_executed' => false,
                'completion_claim_promoted' => false,
                'separated_from' => 'external_rivals_certification',
                'is_read_model' => false,
            ];
        }

        // From here on, $before, $after, $proposalId, $obraId, $reviewer,
        // $reason are guaranteed non-null by the blocker checks above.
        /** @var array<string,mixed> $before */
        /** @var array<string,mixed> $after */
        /** @var string $proposalId */
        /** @var string $obraId */
        /** @var string $reviewer */
        /** @var string $reason */

        // The caller may pass snapshots in two shapes:
        //   (a) flat metric scores (legacy/simple) — entire snapshot is fed
        //       to DeltaScorecard and to InvariantLock/RegressionSentinel.
        //   (b) canonical shape { metrics: {...}, ...state... } — metrics
        //       go to DeltaScorecard while the full snapshot drives
        //       InvariantLock / RegressionSentinel (which expect
        //       `completion_audit` / `docs_health` blocks).
        $beforeMetrics = is_array($before['metrics'] ?? null) ? $before['metrics'] : $before;
        $afterMetrics = is_array($after['metrics'] ?? null) ? $after['metrics'] : $after;

        // 1. Compute delta scorecard.
        $delta = $this->deltaScorecard->compute($beforeMetrics, $afterMetrics, array_merge($context, [
            'proposal_id' => $proposalId,
        ]));

        // 2. Run invariant lock + regression sentinel against the after
        //    snapshot (use empty implementation diff — caller can pass via
        //    context if richer signal is required).
        $invariant = $this->invariantLock->evaluate($after, [], $context['proposal_packet'] ?? []);
        $regression = $this->regressionSentinel->scan($before, $after, []);

        // 3. Determine grade — defensive policy: regression OR invariant
        //    violation OR severe regression sentinel finding forces a
        //    regressed / invalid grade regardless of delta recommendation.
        $grade = $this->deriveGrade($delta, $invariant, $regression, $context);

        // 4. Build learning packet. Regressions never produce a usable
        //    `new_rule_candidate`; they record the failure mode.
        $learningPacket = $this->buildLearningPacket($grade, $delta, $invariant, $regression, $context);

        // 5. Build canonical result entry.
        $entryId = 'res_'.(string) Str::ulid();
        $entry = [
            'schema_version' => self::ENTRY_SCHEMA_VERSION,
            'result_entry_id' => $entryId,
            'proposal_id' => $proposalId,
            'obra_id' => $obraId,
            'before_snapshot_hash' => $this->hashJson($before),
            'after_snapshot_hash' => $this->hashJson($after),
            'delta_scorecard' => $delta,
            'delta_grade' => $grade,
            'evidence_strength' => $this->evidenceStrength($context),
            'human_review_outcome' => $this->stringOrNull($context['human_review_outcome'] ?? null),
            'reviewer' => $reviewer,
            'reason' => $reason,
            'accepted_risks' => array_values((array) ($context['accepted_risks'] ?? [])),
            'regressions_detected' => array_values((array) ($regression['findings'] ?? [])),
            'invariants_preserved' => ($invariant['status'] ?? 'unknown') === 'passed',
            'invariant_violations' => array_values((array) ($invariant['violations'] ?? [])),
            'trust_delta' => $this->trustDeltaFor($grade),
            'trust_outcome_recorded' => $this->trustOutcomeFor($grade),
            'recommended_next_action' => $this->nextActionFor($grade),
            'should_become_rule' => $grade === self::GRADE_MAJOR_IMPROVEMENT && $learningPacket['confidence'] >= 0.7,
            'learning_packet' => $learningPacket,
            'recorded_at' => Carbon::now()->toIso8601String(),
            'evidence_refs' => array_values(array_unique(array_merge(
                (array) ($context['evidence_refs'] ?? []),
                ['proposal:'.$proposalId, 'obra:'.$obraId, 'result_entry:'.$entryId],
            ))),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'completion_claim_promoted' => false,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => false,
        ];

        // 6. Persist locally + mirror to ledger.
        $this->saveEntry($entry);
        $this->updateRegistry($entry);

        // 7. Update human trust ledger (best-effort) with the canonical
        //    self_improvement_* outcome for this grade.
        $this->recordTrustOutcome($entry);

        return $entry;
    }

    /**
     * Find a single result entry by id.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $resultEntryId): ?array
    {
        return $this->loadEntry($resultEntryId);
    }

    /**
     * List all entries linked to a proposal.
     *
     * @return list<array<string,mixed>>
     */
    public function listForProposal(string $proposalId): array
    {
        return array_values(array_filter(
            $this->loadRegistry()['entries'] ?? [],
            fn (mixed $e): bool => is_array($e) && (string) ($e['proposal_id'] ?? '') === $proposalId,
        ));
    }

    /**
     * List all entries linked to an Obra.
     *
     * @return list<array<string,mixed>>
     */
    public function listForObra(string $obraId): array
    {
        return array_values(array_filter(
            $this->loadRegistry()['entries'] ?? [],
            fn (mixed $e): bool => is_array($e) && (string) ($e['obra_id'] ?? '') === $obraId,
        ));
    }

    /**
     * Aggregate ledger snapshot.
     *
     * @param  array{grade?: ?string, proposal_id?: ?string}  $filters
     * @return array<string,mixed>
     */
    public function snapshot(array $filters = []): array
    {
        $registry = $this->loadRegistry();
        $entries = is_array($registry['entries'] ?? null) ? $registry['entries'] : [];

        $gradeFilter = $this->stringOrNull($filters['grade'] ?? null);
        $proposalFilter = $this->stringOrNull($filters['proposal_id'] ?? null);

        $filtered = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if ($gradeFilter !== null && ($entry['delta_grade'] ?? null) !== $gradeFilter) {
                continue;
            }
            if ($proposalFilter !== null && ($entry['proposal_id'] ?? null) !== $proposalFilter) {
                continue;
            }
            $filtered[] = $entry;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'entries' => $filtered,
            'counters' => $this->computeCounters($entries),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'completion_claim_promoted' => false,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => true,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Internals

    /**
     * @param  array<string,mixed>  $delta
     * @param  array<string,mixed>  $invariant
     * @param  array<string,mixed>  $regression
     * @param  array<string,mixed>  $context
     */
    private function deriveGrade(array $delta, array $invariant, array $regression, array $context): string
    {
        $evidenceStrength = $this->evidenceStrength($context);
        if ($evidenceStrength === 'invalid') {
            return self::GRADE_INVALID;
        }

        $invariantPassed = ($invariant['status'] ?? 'unknown') === 'passed';
        $regressionStatus = (string) ($regression['status'] ?? 'unknown');
        if (! $invariantPassed) {
            return self::GRADE_REGRESSED;
        }
        if ($regressionStatus === 'blocked') {
            return self::GRADE_REGRESSED;
        }

        $hardRegression = (bool) ($delta['hard_regression_detected'] ?? false);
        if ($hardRegression) {
            return self::GRADE_REGRESSED;
        }

        $recommendation = (string) ($delta['recommendation'] ?? 'hold');
        if ($recommendation === AtlasSelfImprovementDeltaScorecardService::RECOMMEND_ROLLBACK) {
            return self::GRADE_REGRESSED;
        }

        $normalized = (float) ($delta['normalized_score'] ?? 0.0);
        if ($normalized >= 25.0 && $recommendation === AtlasSelfImprovementDeltaScorecardService::RECOMMEND_PROMOTE) {
            return self::GRADE_MAJOR_IMPROVEMENT;
        }
        if ($normalized > 0.0) {
            return self::GRADE_IMPROVED;
        }

        return self::GRADE_NEUTRAL;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function evidenceStrength(array $context): string
    {
        $refs = (array) ($context['evidence_refs'] ?? []);
        $explicit = $this->stringOrNull($context['evidence_strength'] ?? null);
        if ($explicit !== null && in_array($explicit, ['strong', 'moderate', 'weak', 'invalid'], true)) {
            return $explicit;
        }
        // Default heuristic: invalid when no refs at all; weak with 1; moderate 2-3; strong 4+.
        $count = count(array_filter($refs, static fn (mixed $r): bool => is_string($r) && $r !== ''));

        return match (true) {
            $count === 0 => 'invalid',
            $count === 1 => 'weak',
            $count <= 3 => 'moderate',
            default => 'strong',
        };
    }

    /**
     * @param  array<string,mixed>  $delta
     * @param  array<string,mixed>  $invariant
     * @param  array<string,mixed>  $regression
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function buildLearningPacket(string $grade, array $delta, array $invariant, array $regression, array $context): array
    {
        $what = $this->stringOrNull($context['what_changed'] ?? null) ?? 'unspecified';
        $why = $this->stringOrNull($context['why_it_mattered'] ?? null) ?? 'unspecified';
        $confidence = match ($grade) {
            self::GRADE_MAJOR_IMPROVEMENT => 0.9,
            self::GRADE_IMPROVED => 0.7,
            self::GRADE_NEUTRAL => 0.4,
            self::GRADE_REGRESSED => 0.2,
            self::GRADE_INVALID => 0.0,
            default => 0.3,
        };
        $newRule = $grade === self::GRADE_MAJOR_IMPROVEMENT
            ? $this->stringOrNull($context['new_rule_candidate'] ?? null)
            : null;
        $rollback = ! in_array($grade, [self::GRADE_IMPROVED, self::GRADE_MAJOR_IMPROVEMENT], true)
            ? ($context['rollback_recommendation'] ?? 'review_rollback_runbook_for_obra')
            : null;

        return [
            'schema_version' => self::LEARNING_SCHEMA_VERSION,
            'what_changed' => $what,
            'why_it_mattered' => $why,
            'evidence_supporting_improvement' => array_values((array) ($context['evidence_refs'] ?? [])),
            'what_failed_or_was_missing' => array_values(array_filter(array_merge(
                (array) ($regression['findings'] ?? []),
                (array) ($invariant['violations'] ?? []),
            ))),
            'new_rule_candidate' => $newRule,
            'future_trigger_conditions' => array_values((array) ($context['future_trigger_conditions'] ?? [])),
            'rollback_recommendation' => $rollback,
            'confidence' => $confidence,
        ];
    }

    private function trustDeltaFor(string $grade): float
    {
        return match ($grade) {
            self::GRADE_MAJOR_IMPROVEMENT => 1.0,
            self::GRADE_IMPROVED => 0.4,
            self::GRADE_NEUTRAL => 0.0,
            self::GRADE_REGRESSED => -0.5,
            self::GRADE_INVALID => -0.2,
            default => 0.0,
        };
    }

    private function trustOutcomeFor(string $grade): string
    {
        return match ($grade) {
            self::GRADE_MAJOR_IMPROVEMENT => AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_MAJOR_IMPROVEMENT,
            self::GRADE_IMPROVED => AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_IMPROVED,
            self::GRADE_NEUTRAL => AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_NEUTRAL,
            self::GRADE_REGRESSED => AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_REGRESSED,
            self::GRADE_INVALID => AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_INVALID_EVIDENCE,
            default => AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_NEUTRAL,
        };
    }

    private function nextActionFor(string $grade): string
    {
        return match ($grade) {
            self::GRADE_MAJOR_IMPROVEMENT => 'consider_promoting_rule_candidate_with_human_review',
            self::GRADE_IMPROVED => 'broaden_scope_or_continue_capability_in_next_cycle',
            self::GRADE_NEUTRAL => 'gather_more_evidence_or_re-evaluate_proposal_value',
            self::GRADE_REGRESSED => 'rollback_or_replan_proposal_repair_regression',
            self::GRADE_INVALID => 'gather_evidence_then_re-measure_result',
            default => 'inspect_result_entry_and_decide_manually',
        };
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function recordTrustOutcome(array $entry): void
    {
        $obraId = $this->stringOrNull($entry['obra_id'] ?? null);
        $project = null;
        if ($obraId !== null) {
            try {
                $project = AtlasProject::query()->whereKey($obraId)->first();
            } catch (Throwable) {
                $project = null;
            }
        }

        $payload = [
            'outcome' => (string) ($entry['trust_outcome_recorded'] ?? ''),
            'proposal_id' => $this->stringOrNull($entry['proposal_id'] ?? null),
            'reviewer' => $this->stringOrNull($entry['reviewer'] ?? null),
            'reason' => $this->stringOrNull($entry['reason'] ?? null),
            'area' => (string) ($entry['delta_grade'] ?? 'unknown'),
        ];

        try {
            $this->humanTrustLedger->record($project, $payload);
        } catch (Throwable) {
            // best-effort
        }
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return array<string,int>
     */
    private function computeCounters(array $entries): array
    {
        $counters = [
            'total' => 0,
            'regressed' => 0,
            'neutral' => 0,
            'improved' => 0,
            'major_improvement' => 0,
            'invalid' => 0,
            'invariants_violated' => 0,
        ];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $counters['total']++;
            $grade = (string) ($entry['delta_grade'] ?? 'neutral');
            if (isset($counters[$grade])) {
                $counters[$grade]++;
            }
            if (($entry['invariants_preserved'] ?? true) === false) {
                $counters['invariants_violated']++;
            }
        }

        return $counters;
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function saveEntry(array $entry): void
    {
        $disk = Storage::disk(self::STORAGE_DISK);
        $path = self::STORAGE_PREFIX.'/'.$entry['result_entry_id'].'.json';
        $disk->put($path, (string) json_encode(
            $entry,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        if (Schema::hasTable('atlas_ledger_events')) {
            try {
                DB::table('atlas_ledger_events')->insert([
                    'event_id' => (string) Str::ulid(),
                    'schema_version' => self::ENTRY_SCHEMA_VERSION,
                    'event_type' => 'SELF_IMPROVEMENT_RESULT_LEDGER_'.strtoupper((string) $entry['delta_grade']),
                    'emitter_stage' => 'self_improvement_result_ledger',
                    'emitter_version' => 'v1',
                    'payload' => json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'occurred_at' => (string) ($entry['recorded_at'] ?? Carbon::now()->toIso8601String()),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            } catch (Throwable) {
                // best-effort
            }
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadEntry(string $entryId): ?array
    {
        $disk = Storage::disk(self::STORAGE_DISK);
        $path = self::STORAGE_PREFIX.'/'.$entryId.'.json';
        if (! $disk->exists($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) $disk->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function updateRegistry(array $entry): void
    {
        $disk = Storage::disk(self::STORAGE_DISK);
        $path = self::STORAGE_PREFIX.'/_registry.json';
        $current = ['entries' => []];
        if ($disk->exists($path)) {
            try {
                $decoded = json_decode((string) $disk->get($path), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $current = $decoded;
                }
            } catch (Throwable) {
                $current = ['entries' => []];
            }
        }
        $entries = is_array($current['entries'] ?? null) ? $current['entries'] : [];
        $entries = array_values(array_filter(
            $entries,
            fn (mixed $row): bool => is_array($row) && (string) ($row['result_entry_id'] ?? '') !== (string) $entry['result_entry_id'],
        ));
        array_unshift($entries, $entry);
        $entries = array_slice($entries, 0, self::HISTORY_CAP);

        $disk->put($path, (string) json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'entries' => $entries,
            'updated_at' => Carbon::now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string,mixed>
     */
    private function loadRegistry(): array
    {
        $disk = Storage::disk(self::STORAGE_DISK);
        $path = self::STORAGE_PREFIX.'/_registry.json';
        if (! $disk->exists($path)) {
            return ['entries' => []];
        }
        try {
            $decoded = json_decode((string) $disk->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['entries' => []];
        }

        return is_array($decoded) ? $decoded : ['entries' => []];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashJson(array $payload): string
    {
        return hash('sha256', (string) json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
