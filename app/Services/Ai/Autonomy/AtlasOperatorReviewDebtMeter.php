<?php

declare(strict_types=1);

namespace App\Services\Ai\Autonomy;

use App\Models\AiLearningProposal;
use App\Models\AiMemoryDelta;
use App\Models\OperatorLearningCandidate;
use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Throwable;

final class AtlasOperatorReviewDebtMeter
{
    public const MEASURE_ID = 'acos.operator_review_debt.v1';

    public const FORMULA_VERSION = 'operator_review_debt.v1';

    public const MAX_QUEUE_AGE_DAYS = 7;

    public const TTL_DAYS = 7;

    public const SLOWED_AUTO_APPLY_LIMIT = 10;

    public const DIGEST_SESSION_PROXY_DENOMINATOR = 1;

    public function __construct(
        private readonly AtlasConductorRoutingMemory $routing = new AtlasConductorRoutingMemory,
        private readonly ?CarbonImmutable $now = null,
    ) {}

    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        return [
            'kind' => 'measure_freeze',
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'formula' => 'review_debt = auto_applied_items_seen - explicit_operator_review_decisions; idade_max_da_fila=max(age_days of unreviewed auto-applied items); inspection_time proxy=reviewable_items_per_digest_session',
            'thresholds' => [
                'idade_max_da_fila_cap_days' => self::MAX_QUEUE_AGE_DAYS,
                'denominator_min_auto_applied_items' => 1,
                'digest_session_proxy_denominator' => self::DIGEST_SESSION_PROXY_DENOMINATOR,
                'slow_auto_apply_limit' => self::SLOWED_AUTO_APPLY_LIMIT,
            ],
            'denominator_min' => 1,
            'ttl_days' => self::TTL_DAYS,
            'author_engine_id' => 'cursor-acos-max-elev25',
            'judge_engine_id' => 'codex-elev25-independent-judge',
            'series' => [
                'id' => self::MEASURE_ID,
                'reader_command' => 'atlas:ai:weekly-memory-digest --days=7 --json',
                'json_path' => 'operator_review_debt',
                'registry_status' => 'registered_elev_20s',
            ],
            'cadence_response' => [
                'trigger' => 'idade_max_da_fila > cap_days',
                'action' => 'lower next auto-apply cycle limit to slow_auto_apply_limit',
                'persistence' => 'ephemeral_safety_cap',
                'never_becomes_approval_queue' => true,
            ],
            'dual_read_required' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function report(int $days = 7, ?int $configuredLimit = null): array
    {
        $days = max(1, min(365, $days));
        $now = $this->clock();
        $windowSince = $now->subDays($days);
        $queueSince = $now->subDays(max($days, self::MAX_QUEUE_AGE_DAYS + 7));
        $autoAppliedItems = $this->autoAppliedItems($queueSince);
        $autoApplied = count($autoAppliedItems);
        $reviewed = $this->explicitOperatorReviews($windowSince);
        $unreviewed = max(0, $autoApplied - $reviewed);
        $ageMaxDays = $unreviewed > 0 ? $this->maxAgeDays($autoAppliedItems, $now) : 0;
        $status = $unreviewed > 0 && $ageMaxDays > self::MAX_QUEUE_AGE_DAYS ? 'alert' : 'ok';
        $configuredLimit = $configuredLimit ?? max(1, (int) config('atlas.ai.autonomous_learning.limit', 50));
        $effectiveLimit = $status === 'alert'
            ? min($configuredLimit, self::SLOWED_AUTO_APPLY_LIMIT)
            : $configuredLimit;
        $reviewableItems = $autoApplied + $reviewed;

        return [
            'schema_version' => 'atlas.acos.operator_review_debt.v1',
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'generated_at' => $now->toIso8601String(),
            'window_days' => $days,
            'status' => $status,
            'metrics' => [
                'itens_auto_aplicados_nao_revisados' => [
                    'value' => $unreviewed,
                    'denominator' => 'auto_applied_items_seen_in_queue_window',
                    'denominator_value' => $autoApplied,
                ],
                'idade_max_da_fila' => [
                    'value_days' => $ageMaxDays,
                    'cap_days' => self::MAX_QUEUE_AGE_DAYS,
                    'denominator' => 'unreviewed_auto_applied_items',
                    'denominator_value' => $unreviewed,
                ],
                'itens_revisados_na_janela' => [
                    'value' => $reviewed,
                    'denominator' => 'reviewable_items_seen_in_window',
                    'denominator_value' => $reviewableItems,
                ],
                'tempo_medio_inspecao' => [
                    'value' => $reviewableItems / self::DIGEST_SESSION_PROXY_DENOMINATOR,
                    'unit' => 'items_per_digest_session',
                    'proxy' => true,
                    'denominator_sessions' => self::DIGEST_SESSION_PROXY_DENOMINATOR,
                ],
            ],
            'cadence' => [
                'configured_auto_apply_limit' => $configuredLimit,
                'next_cycle_effective_limit' => $effectiveLimit,
                'auto_slowed' => $effectiveLimit < $configuredLimit,
                'persistence' => 'ephemeral_safety_cap',
                'reason' => $status === 'alert' ? 'idade_max_da_fila_exceeds_frozen_cap' : 'within_frozen_cap',
                'never_becomes_approval_queue' => true,
            ],
            'freeze' => [
                'ttl_days' => self::TTL_DAYS,
                'cap_days' => self::MAX_QUEUE_AGE_DAYS,
                'judge_author_distinct' => self::freezePayload()['judge_engine_id'] !== self::freezePayload()['author_engine_id'],
            ],
        ];
    }

    public function effectiveAutoApplyLimit(int $configuredLimit, int $days = 7): int
    {
        $configuredLimit = max(1, $configuredLimit);

        return (int) ($this->report($days, $configuredLimit)['cadence']['next_cycle_effective_limit'] ?? $configuredLimit);
    }

    /** @return list<array{source:string,recorded_at:CarbonImmutable}> */
    private function autoAppliedItems(CarbonImmutable $since): array
    {
        return array_merge(
            $this->preferredRouteItems($since),
            $this->learningProposalItems($since),
            $this->memoryDeltaItems($since),
        );
    }

    /** @return list<array{source:string,recorded_at:CarbonImmutable}> */
    private function preferredRouteItems(CarbonImmutable $since): array
    {
        $path = $this->routing->preferredPath();
        if (! File::exists($path)) {
            return [];
        }

        $items = [];
        foreach (preg_split('/\r?\n/', (string) File::get($path)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (! is_array($row) || ($row['action'] ?? '') !== 'set') {
                continue;
            }
            $recordedAt = $this->parseTime($row['recorded_at'] ?? $row['at'] ?? null);
            if ($recordedAt === null || $recordedAt->lessThan($since)) {
                continue;
            }
            $items[] = ['source' => 'preferred_route', 'recorded_at' => $recordedAt];
        }

        return $items;
    }

    /** @return list<array{source:string,recorded_at:CarbonImmutable}> */
    private function learningProposalItems(CarbonImmutable $since): array
    {
        if (! DatabaseTableAvailability::has('ai_learning_proposals')) {
            return [];
        }

        try {
            return AiLearningProposal::query()
                ->where('status', 'applied')
                ->where('decided_by', AtlasAutonomousLearningApplier::AUTO_APPLIED_BY)
                ->where('created_at', '>=', $since)
                ->get()
                ->map(fn (AiLearningProposal $proposal): array => [
                    'source' => 'learning_proposals',
                    'recorded_at' => CarbonImmutable::instance($proposal->decided_at ?? $proposal->created_at ?? $this->clock())->utc(),
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array{source:string,recorded_at:CarbonImmutable}> */
    private function memoryDeltaItems(CarbonImmutable $since): array
    {
        if (! DatabaseTableAvailability::has('ai_memory_deltas')) {
            return [];
        }

        try {
            return AiMemoryDelta::query()
                ->where('status', 'promoted')
                ->where('promoted_at', '>=', $since)
                ->get()
                ->filter(fn (AiMemoryDelta $delta): bool => is_string($delta->promoted_memory_entry_id) && $delta->promoted_memory_entry_id !== '')
                ->map(fn (AiMemoryDelta $delta): array => [
                    'source' => 'memory_deltas',
                    'recorded_at' => CarbonImmutable::instance($delta->promoted_at ?? $delta->updated_at ?? $this->clock())->utc(),
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function explicitOperatorReviews(CarbonImmutable $since): int
    {
        return $this->explicitLearningProposalReviews($since)
            + $this->explicitOperatorLearningReviews($since);
    }

    private function explicitLearningProposalReviews(CarbonImmutable $since): int
    {
        if (! DatabaseTableAvailability::has('ai_learning_proposals')) {
            return 0;
        }

        try {
            return AiLearningProposal::query()
                ->whereNotNull('decided_by')
                ->where('decided_by', '!=', AtlasAutonomousLearningApplier::AUTO_APPLIED_BY)
                ->where('decided_at', '>=', $since)
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function explicitOperatorLearningReviews(CarbonImmutable $since): int
    {
        if (! DatabaseTableAvailability::has('operator_learning_candidates')) {
            return 0;
        }

        try {
            return OperatorLearningCandidate::query()
                ->whereNotNull('decided_by')
                ->where('decided_by', '!=', 'atlas-operator-intelligence-auto')
                ->where('decided_at', '>=', $since)
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /** @param list<array{source:string,recorded_at:CarbonImmutable}> $items */
    private function maxAgeDays(array $items, CarbonImmutable $now): int
    {
        $max = 0;
        foreach ($items as $item) {
            $age = (int) floor(max(0, $item['recorded_at']->diffInSeconds($now)) / 86400);
            $max = max($max, $age);
        }

        return $max;
    }

    private function parseTime(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function clock(): CarbonImmutable
    {
        return ($this->now ?? CarbonImmutable::now('UTC'))->utc();
    }
}
