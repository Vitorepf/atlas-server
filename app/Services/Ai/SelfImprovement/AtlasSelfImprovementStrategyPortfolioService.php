<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Atlas Self-Improvement Strategy Portfolio.
 *
 * Level 6 of the governance ladder governs PORTFOLIO BALANCING, not unbounded
 * execution. This service buckets candidate proposals into the 8 canonical
 * categories, returns balance health, and recommends the next category to
 * pull from so the Atlas does not collapse into "only quick wins" or "only
 * core runtime".
 *
 * Hard rules:
 *   - NEVER promotes;
 *   - NEVER calls a provider;
 *   - NEVER bypasses Power Gate / Invariant Lock / Delta Scorecard /
 *     Regression Sentinel (it just chooses what to spec next).
 *
 * Schema: atlas.self_improvement.strategy_portfolio.v1
 */
class AtlasSelfImprovementStrategyPortfolioService
{
    public const SCHEMA_VERSION = 'atlas.self_improvement.strategy_portfolio.v1';

    public const BUCKET_QUICK_WINS = 'quick_wins';
    public const BUCKET_CORE_RUNTIME = 'core_runtime';
    public const BUCKET_ENTERPRISE_RELIABILITY = 'enterprise_reliability';
    public const BUCKET_PROVIDER_INTELLIGENCE = 'provider_intelligence';
    public const BUCKET_OPERATOR_EXPERIENCE = 'operator_experience';
    public const BUCKET_RIVALS_EVALUATION = 'rivals_evaluation';
    public const BUCKET_SELF_CONSTRUCTION = 'self_construction';
    public const BUCKET_SECURITY_GOVERNANCE = 'security_governance';

    /** @var list<string> 8 canonical buckets. */
    public const BUCKETS = [
        self::BUCKET_QUICK_WINS,
        self::BUCKET_CORE_RUNTIME,
        self::BUCKET_ENTERPRISE_RELIABILITY,
        self::BUCKET_PROVIDER_INTELLIGENCE,
        self::BUCKET_OPERATOR_EXPERIENCE,
        self::BUCKET_RIVALS_EVALUATION,
        self::BUCKET_SELF_CONSTRUCTION,
        self::BUCKET_SECURITY_GOVERNANCE,
    ];

    /** @var array<string,int> Recommended balance (sum=100). */
    public const TARGET_WEIGHTS = [
        self::BUCKET_QUICK_WINS => 10,
        self::BUCKET_CORE_RUNTIME => 22,
        self::BUCKET_ENTERPRISE_RELIABILITY => 16,
        self::BUCKET_PROVIDER_INTELLIGENCE => 12,
        self::BUCKET_OPERATOR_EXPERIENCE => 10,
        self::BUCKET_RIVALS_EVALUATION => 10,
        self::BUCKET_SELF_CONSTRUCTION => 10,
        self::BUCKET_SECURITY_GOVERNANCE => 10,
    ];

    /**
     * Compute the canonical portfolio snapshot from a proposal list.
     *
     * @param  list<array<string,mixed>>  $proposals  Each must have `bucket` (and optional fields).
     * @return array<string,mixed>
     */
    public function snapshot(array $proposals = []): array
    {
        $byBucket = array_fill_keys(self::BUCKETS, [
            'count' => 0,
            'ready_count' => 0,
            'blocked_count' => 0,
            'proposal_ids' => [],
        ]);

        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            $bucket = $this->normalizeBucket($proposal['bucket'] ?? null);
            if ($bucket === null) {
                continue;
            }
            $byBucket[$bucket]['count']++;
            $status = (string) ($proposal['status'] ?? 'unknown');
            if ($status === AtlasSelfImprovementProposalPacketService::STATUS_READY) {
                $byBucket[$bucket]['ready_count']++;
            } elseif ($status === AtlasSelfImprovementProposalPacketService::STATUS_BLOCKED) {
                $byBucket[$bucket]['blocked_count']++;
            }
            $proposalId = $this->stringOrNull($proposal['proposal_id'] ?? null);
            if ($proposalId !== null) {
                $byBucket[$bucket]['proposal_ids'][] = $proposalId;
            }
        }

        $total = array_sum(array_column($byBucket, 'count'));
        $entries = [];
        foreach (self::BUCKETS as $bucket) {
            $count = $byBucket[$bucket]['count'];
            $share = $total > 0 ? round(($count / $total) * 100.0, 2) : 0.0;
            $target = self::TARGET_WEIGHTS[$bucket];
            $deviation = round($share - $target, 2);
            $entries[] = [
                'bucket' => $bucket,
                'count' => $count,
                'ready_count' => $byBucket[$bucket]['ready_count'],
                'blocked_count' => $byBucket[$bucket]['blocked_count'],
                'share_percent' => $share,
                'target_percent' => $target,
                'deviation_percent' => $deviation,
                'underweight' => $deviation < -3.0,
                'overweight' => $deviation > 5.0,
                'proposal_ids' => $byBucket[$bucket]['proposal_ids'],
            ];
        }

        $balanceHealth = $this->resolveBalanceHealth($entries, $total);
        $nextBucket = $this->resolveNextBucket($entries);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'portfolio_id' => 'port_'.(string) Str::ulid(),
            'generated_at' => Carbon::now()->toIso8601String(),
            'total_proposals' => $total,
            'buckets' => $entries,
            'balance_health' => $balanceHealth,
            'recommended_next_bucket' => $nextBucket,
            'next_action' => $nextBucket
                ? 'spec_next_proposal_in_bucket:'.$nextBucket
                : 'rebalance_or_close_overweight_buckets',
            'invariants' => [
                'eight_canonical_buckets' => count(self::BUCKETS) === 8,
                'never_bypasses_power_gate' => true,
                'never_calls_external_provider' => true,
                'never_promotes_directly' => true,
            ],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => true,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     */
    private function resolveBalanceHealth(array $entries, int $total): string
    {
        if ($total === 0) {
            return 'empty_portfolio';
        }
        $maxAbsDeviation = 0.0;
        foreach ($entries as $entry) {
            $maxAbsDeviation = max($maxAbsDeviation, abs((float) $entry['deviation_percent']));
        }
        if ($maxAbsDeviation <= 5.0) {
            return 'balanced';
        }
        if ($maxAbsDeviation <= 15.0) {
            return 'mild_imbalance';
        }

        return 'severe_imbalance';
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     */
    private function resolveNextBucket(array $entries): ?string
    {
        $candidate = null;
        $worstDeviation = 0.0;
        foreach ($entries as $entry) {
            if (! ($entry['underweight'] ?? false)) {
                continue;
            }
            $deviation = (float) $entry['deviation_percent'];
            if ($deviation < $worstDeviation) {
                $worstDeviation = $deviation;
                $candidate = (string) $entry['bucket'];
            }
        }

        return $candidate;
    }

    private function normalizeBucket(mixed $value): ?string
    {
        $str = $this->stringOrNull($value);
        if ($str === null) {
            return null;
        }

        return in_array($str, self::BUCKETS, true) ? $str : null;
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
