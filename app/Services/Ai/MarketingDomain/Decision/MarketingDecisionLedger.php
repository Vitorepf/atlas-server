<?php

namespace App\Services\Ai\MarketingDomain\Decision;

use App\Models\AiMarketingDecisionLedgerEntry;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;

/**
 * MarketingDecisionLedger — the MOAT. Append-only record of (symptom + offer state + action +
 * lever) → result. Recording every decision and its outcome is what makes Atlas's marketing
 * judgment COMPOUND across offers: "for this symptom, this lever worked X/Y times". Stage-1 is
 * not one-shot cleverness — it is a learning curve the operator owns.
 */
class MarketingDecisionLedger
{
    public const OUTCOME_IMPROVED = 'improved';

    public const OUTCOME_NO_CHANGE = 'no_change';

    public const OUTCOME_WORSE = 'worse';

    public const OUTCOME_PENDING = 'pending';

    public const OUTCOMES = [
        self::OUTCOME_IMPROVED,
        self::OUTCOME_NO_CHANGE,
        self::OUTCOME_WORSE,
        self::OUTCOME_PENDING,
    ];

    /**
     * Record a decision (typically the `primary` from MarketingSymptomActionTree::diagnose()).
     *
     * @param  array<string,mixed>  $diagnosis  a primary diagnosis (stage/symptom/action/lever/…)
     * @param  array<string,mixed>  $context    campaign_ref, vsl_asset_id, niche, offer_state
     */
    public function record(array $diagnosis, array $context = []): AiMarketingDecisionLedgerEntry
    {
        $decidedAt = isset($context['decided_at'])
            ? Carbon::parse((string) $context['decided_at'])
            : Carbon::now();

        $offerState = (array) ($context['offer_state'] ?? []);

        $hash = MissionCanonicalHash::sha256([
            'campaign_ref' => $context['campaign_ref'] ?? null,
            'niche' => $context['niche'] ?? null,
            'stage' => $diagnosis['stage'] ?? null,
            'action' => $diagnosis['action'] ?? null,
            'offer_state' => $offerState,
            'decided_at' => $decidedAt->toIso8601String(),
        ]);

        return AiMarketingDecisionLedgerEntry::query()->create([
            'campaign_ref' => $this->str($context['campaign_ref'] ?? null),
            'vsl_asset_id' => $this->str($context['vsl_asset_id'] ?? null),
            'niche' => $this->str($context['niche'] ?? null),
            'stage' => (string) ($diagnosis['stage'] ?? 'unknown'),
            'symptom' => (string) ($diagnosis['symptom'] ?? ''),
            'action' => (string) ($diagnosis['action'] ?? 'unknown'),
            'lever' => (string) ($diagnosis['lever'] ?? ''),
            'vsl_block' => $this->str($diagnosis['vsl_block'] ?? null),
            'numeric_rule' => $this->str($diagnosis['numeric_rule'] ?? null),
            'predicted_effect' => $this->str($diagnosis['predicted_effect'] ?? null),
            'offer_state' => $offerState,
            'decided_at' => $decidedAt,
            'outcome' => self::OUTCOME_PENDING,
            'decision_hash' => $hash,
        ]);
    }

    /**
     * Close the loop: attach the measured result to a recorded decision.
     *
     * @param  array<string,mixed>  $metrics
     */
    public function attachResult(
        string $id,
        string $outcome,
        array $metrics = [],
        ?string $note = null,
    ): ?AiMarketingDecisionLedgerEntry {
        $entry = AiMarketingDecisionLedgerEntry::query()->find($id);
        if ($entry === null) {
            return null;
        }
        if (! in_array($outcome, self::OUTCOMES, true)) {
            $outcome = self::OUTCOME_NO_CHANGE;
        }

        $entry->update([
            'outcome' => $outcome,
            'result_metrics' => $metrics,
            'result_note' => $note,
            'measured_at' => Carbon::now(),
        ]);

        return $entry->refresh();
    }

    /**
     * Recall prior decisions (compounding read) for a niche/action/stage, with the win-rate
     * summary that lets the agent prefer levers that have actually worked here before.
     *
     * @param  array<string,mixed>  $filter  niche, action, stage, campaign_ref, limit
     * @return array<string,mixed>
     */
    public function recall(array $filter = []): array
    {
        $q = AiMarketingDecisionLedgerEntry::query();
        foreach (['niche', 'action', 'stage', 'campaign_ref'] as $col) {
            if (! empty($filter[$col])) {
                $q->where($col, (string) $filter[$col]);
            }
        }
        $limit = (int) ($filter['limit'] ?? 200);
        $entries = $q->orderByDesc('decided_at')->limit($limit)->get();

        return [
            'count' => $entries->count(),
            'entries' => $entries->map(static fn (AiMarketingDecisionLedgerEntry $e): array => [
                'id' => $e->id,
                'stage' => $e->stage,
                'action' => $e->action,
                'lever' => $e->lever,
                'outcome' => $e->outcome,
                'decided_at' => $e->decided_at?->toIso8601String(),
            ])->all(),
            'summary' => self::summarizeOutcomes($entries->all()),
        ];
    }

    /**
     * Pure compounding summary: per-action and per-stage win-rates among RESOLVED decisions.
     * win_rate = improved / (improved + worse); no_change is neutral, pending excluded. This is
     * the composed judgment — which lever earns its place for which symptom.
     *
     * @param  iterable<int,AiMarketingDecisionLedgerEntry|array<string,mixed>>  $entries
     * @return array<string,mixed>
     */
    public static function summarizeOutcomes(iterable $entries): array
    {
        $byAction = [];
        $byStage = [];

        foreach ($entries as $e) {
            $action = is_array($e) ? ($e['action'] ?? 'unknown') : $e->action;
            $stage = is_array($e) ? ($e['stage'] ?? 'unknown') : $e->stage;
            $outcome = is_array($e) ? ($e['outcome'] ?? null) : $e->outcome;

            self::tally($byAction, (string) $action, (string) $outcome);
            self::tally($byStage, (string) $stage, (string) $outcome);
        }

        return [
            'by_action' => self::finalizeRates($byAction),
            'by_stage' => self::finalizeRates($byStage),
        ];
    }

    /**
     * @param  array<string,array<string,int>>  $bucket
     */
    private static function tally(array &$bucket, string $key, string $outcome): void
    {
        $bucket[$key] ??= ['total' => 0, 'improved' => 0, 'worse' => 0, 'no_change' => 0, 'pending' => 0];
        $bucket[$key]['total']++;
        if (in_array($outcome, self::OUTCOMES, true)) {
            $bucket[$key][$outcome]++;
        }
    }

    /**
     * @param  array<string,array<string,int>>  $bucket
     * @return array<string,array<string,mixed>>
     */
    private static function finalizeRates(array $bucket): array
    {
        foreach ($bucket as $key => $counts) {
            $resolved = $counts['improved'] + $counts['worse'];
            $bucket[$key]['win_rate'] = $resolved > 0
                ? round($counts['improved'] / $resolved, 3)
                : null;
        }

        return $bucket;
    }

    private function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
