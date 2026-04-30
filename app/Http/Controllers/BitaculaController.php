<?php

namespace App\Http\Controllers;

use App\Http\Requests\NormalizeBitaculaRequest;
use App\Http\Resources\BehaviorLogResource;
use App\Http\Resources\BehaviorResource;
use App\Models\Behavior;
use App\Models\BehaviorLog;
use App\Models\Checkin;
use App\Models\HealthSnapshot;
use App\Services\Bitacula\CanonicalBehaviorCatalog;
use App\Support\BehaviorLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BitaculaController extends Controller
{
    public function factors(CanonicalBehaviorCatalog $catalog): JsonResponse
    {
        return response()->json([
            'normalizer' => CanonicalBehaviorCatalog::NORMALIZER_VERSION,
            'factors' => $catalog->all(),
        ]);
    }

    public function normalize(NormalizeBitaculaRequest $request, CanonicalBehaviorCatalog $catalog): JsonResponse
    {
        $data = $request->validated();

        return response()->json($catalog->normalize(
            text: $data['text'],
            limit: (int) ($data['limit'] ?? 5),
        ));
    }

    public function briefing(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:12'],
            'include_logged' => ['nullable', 'boolean'],
        ]);

        $date = isset($data['date'])
            ? Carbon::parse($data['date'])->toDateString()
            : now()->subDay()->toDateString();
        $limit = (int) ($data['limit'] ?? 12);
        $includeLogged = (bool) ($data['include_logged'] ?? true);

        $behaviors = Behavior::query()
            ->whereNull('archived_at')
            ->where('show_in_morning_briefing', true)
            ->whereIn('lifecycle_status', BehaviorLifecycle::promptable())
            ->where(function ($query): void {
                $query->whereNull('paused_until')->orWhere('paused_until', '<=', now());
            })
            ->orderByDesc('priority_score')
            ->orderByDesc('activated_at')
            ->limit(100)
            ->get();

        $logs = BehaviorLog::query()
            ->whereIn('behavior_client_id', $behaviors->pluck('client_id'))
            ->whereDate('log_date', $date)
            ->whereNull('reverted_at')
            ->get()
            ->keyBy('behavior_client_id');

        $items = $behaviors
            ->map(function (Behavior $behavior) use ($logs, $date): array {
                $log = $logs->get($behavior->client_id);
                $score = $this->briefingScore($behavior, $log !== null);

                return [
                    'behavior' => (new BehaviorResource($behavior))->resolve(),
                    'log' => $log ? (new BehaviorLogResource($log))->resolve() : null,
                    'date' => $date,
                    'score' => $score,
                    'reason' => $this->briefingReason($behavior, $log !== null),
                ];
            })
            ->when(! $includeLogged, fn ($items) => $items->filter(fn (array $item): bool => $item['log'] === null))
            ->sortByDesc('score')
            ->values()
            ->take($limit)
            ->values();

        return response()->json([
            'date' => $date,
            'items' => $items,
            'normalizer' => CanonicalBehaviorCatalog::NORMALIZER_VERSION,
        ]);
    }

    public function analysis(Request $request): JsonResponse
    {
        $data = $request->validate([
            'behavior_client_id' => ['required', 'uuid'],
            'window_days' => ['nullable', 'integer', 'min:7', 'max:180'],
        ]);

        $windowDays = (int) ($data['window_days'] ?? 30);
        $dateTo = now()->toDateString();
        $dateFrom = now()->subDays($windowDays)->toDateString();
        $behavior = Behavior::query()
            ->where('client_id', $data['behavior_client_id'])
            ->firstOrFail();
        $logs = BehaviorLog::query()
            ->where('behavior_client_id', $behavior->client_id)
            ->whereBetween('log_date', [$dateFrom, $dateTo])
            ->whereNull('reverted_at')
            ->whereIn('value', ['yes', 'no'])
            ->orderBy('log_date')
            ->get();
        $lagDays = $this->lagDaysFor($behavior);
        $outcomes = $this->outcomesFor($behavior);
        $neededDates = $logs
            ->map(fn (BehaviorLog $log): string => Carbon::parse($log->log_date)->addDays($lagDays)->toDateString())
            ->unique()
            ->values();
        $snapshots = HealthSnapshot::query()
            ->whereIn('snapshot_date', $neededDates)
            ->get()
            ->keyBy(fn (HealthSnapshot $snapshot): string => $snapshot->snapshot_date->toDateString());
        $checkins = Checkin::query()
            ->whereDate('recorded_at', '>=', $neededDates->min() ?? $dateFrom)
            ->whereDate('recorded_at', '<=', $neededDates->max() ?? $dateTo)
            ->orderBy('recorded_at')
            ->get()
            ->groupBy(fn (Checkin $checkin): string => $checkin->recorded_at->toDateString());

        $analysis = [];
        foreach ($outcomes as $outcome) {
            $yes = [];
            $no = [];

            foreach ($logs as $log) {
                $outcomeDate = Carbon::parse($log->log_date)->addDays($lagDays)->toDateString();
                $value = $this->outcomeValue($outcome, $outcomeDate, $snapshots, $checkins);
                if ($value === null) {
                    continue;
                }

                if ($log->value === 'yes') {
                    $yes[] = $value;
                } else {
                    $no[] = $value;
                }
            }

            $analysis[] = $this->analysisRow($outcome, $yes, $no);
        }

        return response()->json([
            'behavior' => (new BehaviorResource($behavior))->resolve(),
            'window_days' => $windowDays,
            'lag_days' => $lagDays,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'computed_at' => now()->toJSON(),
            'analysis' => $analysis,
            'confounders' => $this->confoundersFor($behavior, $logs),
            'disclaimer' => 'Exploratory only. This ranks hypotheses; it does not prove causality.',
        ]);
    }

    private function briefingScore(Behavior $behavior, bool $hasLogForDate): int
    {
        $score = (int) $behavior->priority_score;
        $score += $hasLogForDate ? 0 : 10000;
        $score += $this->hasOutcome($behavior, ['sleep', 'hrv', 'energy', 'mood', 'focus']) ? 250 : 0;
        $score += max(0, 7 - ((int) $behavior->total_yes_count + (int) $behavior->total_no_count)) * 30;
        $score -= ((int) $behavior->streak_no >= 10) ? 100 : 0;

        return $score;
    }

    private function briefingReason(Behavior $behavior, bool $hasLogForDate): string
    {
        if (! $hasLogForDate) {
            return $this->hasOutcome($behavior, ['sleep', 'hrv'])
                ? 'pending_sleep_context'
                : 'pending_context';
        }

        return 'already_logged';
    }

    private function hasOutcome(Behavior $behavior, array $outcomes): bool
    {
        $targetOutcomes = $behavior->target_outcomes ?? [];

        return count(array_intersect($targetOutcomes, $outcomes)) > 0;
    }

    private function outcomesFor(Behavior $behavior): array
    {
        $outcomes = $behavior->target_outcomes ?? [];
        $supported = ['sleep', 'hrv', 'resting_heart_rate', 'energy', 'mood', 'focus', 'anxiety'];
        $outcomes = array_values(array_intersect($outcomes, $supported));

        return $outcomes ?: ['sleep', 'hrv', 'energy', 'mood'];
    }

    private function lagDaysFor(Behavior $behavior): int
    {
        $lag = (string) $behavior->expected_lag;

        return str_contains($lag, 'next_morning') || str_contains($lag, 'next_day') ? 1 : 0;
    }

    private function outcomeValue(string $outcome, string $date, $snapshots, $checkins): ?float
    {
        $snapshot = $snapshots->get($date);
        $checkin = $checkins->get($date)?->last();

        return match ($outcome) {
            'sleep' => $this->number($snapshot?->sleep_score ?? $snapshot?->sleep_duration_hours),
            'hrv' => $this->number($snapshot?->hrv_ms),
            'resting_heart_rate' => $this->number($snapshot?->resting_heart_rate_bpm),
            'energy' => $this->number($snapshot?->energy_level ?? $checkin?->energy_level),
            'mood' => $this->number($snapshot?->mood_level ?? $checkin?->mood_level),
            'focus', 'anxiety' => $this->number($snapshot?->current_score ?? $snapshot?->readiness_score),
            default => null,
        };
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function analysisRow(string $outcome, array $yes, array $no): array
    {
        $yesCount = count($yes);
        $noCount = count($no);
        $avgYes = $yesCount > 0 ? array_sum($yes) / $yesCount : null;
        $avgNo = $noCount > 0 ? array_sum($no) / $noCount : null;
        $difference = $avgYes !== null && $avgNo !== null ? $avgYes - $avgNo : null;
        $sampleSufficient = $yesCount >= 5 && $noCount >= 5;
        $confidence = $yesCount >= 10 && $noCount >= 10 ? 'high' : ($sampleSufficient ? 'medium' : 'low');

        return [
            'outcome' => $outcome,
            'yes_count' => $yesCount,
            'no_count' => $noCount,
            'avg_yes' => $avgYes,
            'avg_no' => $avgNo,
            'difference' => $difference,
            'effect_direction' => $this->effectDirection($difference),
            'support' => $this->supportLevel($yesCount, $noCount, $difference),
            'sample_sufficient' => $sampleSufficient,
            'confidence_level' => $confidence,
            'interpretation' => $difference === null
                ? 'insufficient_data'
                : ($sampleSufficient ? 'hypothesis_candidate' : 'weak_signal'),
        ];
    }

    private function effectDirection(?float $difference): ?string
    {
        if ($difference === null || abs($difference) < 0.01) {
            return $difference === null ? null : 'neutral';
        }

        return $difference > 0 ? 'higher_when_yes' : 'lower_when_yes';
    }

    private function supportLevel(int $yesCount, int $noCount, ?float $difference): string
    {
        if ($difference === null || $yesCount < 3 || $noCount < 3) {
            return 'insufficient';
        }

        if ($yesCount >= 10 && $noCount >= 10) {
            return 'strong_exploratory';
        }

        return $yesCount >= 5 && $noCount >= 5 ? 'moderate_exploratory' : 'weak';
    }

    private function confoundersFor(Behavior $behavior, $logs): array
    {
        $yesDates = $logs
            ->where('value', 'yes')
            ->pluck('log_date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->values();

        if ($yesDates->isEmpty()) {
            return [];
        }

        return BehaviorLog::query()
            ->join('behaviors', 'behavior_logs.behavior_client_id', '=', 'behaviors.client_id')
            ->whereIn('behavior_logs.log_date', $yesDates)
            ->where('behavior_logs.value', 'yes')
            ->where('behavior_logs.behavior_client_id', '!=', $behavior->client_id)
            ->whereNull('behavior_logs.reverted_at')
            ->selectRaw('behaviors.name as name, behaviors.parent_factor as parent_factor, behaviors.relational_privacy as relational_privacy, behaviors.sensitivity_level as sensitivity_level, count(*) as count')
            ->groupBy('behaviors.name', 'behaviors.parent_factor', 'behaviors.relational_privacy', 'behaviors.sensitivity_level')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [
                'name' => $row->relational_privacy || $row->sensitivity_level === 'relational'
                    ? 'Fator relacional privado'
                    : $row->name,
                'parent_factor' => $row->relational_privacy || $row->sensitivity_level === 'relational'
                    ? 'relational_private'
                    : $row->parent_factor,
                'count' => (int) $row->count,
            ])
            ->all();
    }
}
