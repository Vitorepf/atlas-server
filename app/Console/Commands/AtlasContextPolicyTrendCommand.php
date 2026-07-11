<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiRagFeedbackEvent;
use App\Services\Ai\Context\AtlasContextFeedbackSignalPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class AtlasContextPolicyTrendCommand extends Command
{
    protected $signature = 'atlas:context:policy-trend
        {--flow-id= : Restrict to one ARFL flow id}
        {--window=week : Window size: day or week}
        {--windows=4 : Number of windows to report}
        {--min-total= : Minimum total events required for a valid window}
        {--json : Emit JSON}';

    protected $description = 'Read-only ARFL policy-to-ROI trend over measured feedback windows.';

    public function handle(AtlasContextFeedbackSignalPolicy $signalPolicy): int
    {
        $window = in_array((string) $this->option('window'), ['day', 'week'], true)
            ? (string) $this->option('window')
            : 'week';
        $windowCount = max(1, min(52, (int) $this->option('windows')));
        $minTotal = $this->option('min-total') === null
            ? AtlasContextFeedbackSignalPolicy::TOTAL_EVENT_FLOOR
            : max(1, (int) $this->option('min-total'));
        $flowId = is_scalar($this->option('flow-id')) && trim((string) $this->option('flow-id')) !== ''
            ? trim((string) $this->option('flow-id'))
            : null;

        $windows = [];
        $previousValid = null;
        foreach ($this->windowRanges($window, $windowCount) as $range) {
            $events = $this->eventsForRange($range['start'], $range['end'], $flowId);
            $row = $this->windowReport($events, $range, $minTotal, $signalPolicy, $previousValid);
            if (($row['status'] ?? null) === 'valid') {
                $previousValid = $row;
            }
            $windows[] = $row;
        }

        $payload = [
            'schema_version' => 'atlas.context.policy_trend.v1',
            'status' => collect($windows)->contains(fn (array $row): bool => ($row['status'] ?? null) === 'valid') ? 'ready' : 'insufficient_signal',
            'flow_id' => $flowId,
            'window' => $window,
            'window_count' => $windowCount,
            'minimum_total_event_count' => $minTotal,
            'windows' => $windows,
            'policy' => [
                'read_only' => true,
                'measured_only' => true,
                'excluded_attribution_qualities' => ['low', 'transcript_inferred'],
                'raw_text_exposed' => false,
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas context policy trend', (string) $payload['status']);
        $this->components->twoColumnDetail('Windows', (string) count($windows));

        return self::SUCCESS;
    }

    /**
     * @return list<array{start:Carbon,end:Carbon,label:string}>
     */
    private function windowRanges(string $window, int $count): array
    {
        $currentStart = $window === 'day'
            ? now()->startOfDay()
            : now()->startOfWeek();
        $ranges = [];

        for ($offset = $count - 1; $offset >= 0; $offset--) {
            $start = $window === 'day'
                ? $currentStart->copy()->subDays($offset)
                : $currentStart->copy()->subWeeks($offset);
            $end = $window === 'day'
                ? $start->copy()->endOfDay()
                : $start->copy()->endOfWeek();
            $ranges[] = [
                'start' => $start,
                'end' => $end,
                'label' => $window === 'day' ? $start->toDateString() : $start->format('o-\WW'),
            ];
        }

        return $ranges;
    }

    /**
     * @return Collection<int,AiRagFeedbackEvent>
     */
    private function eventsForRange(Carbon $start, Carbon $end, ?string $flowId): Collection
    {
        $query = AiRagFeedbackEvent::query()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->oldest('created_at');

        if ($flowId !== null) {
            $query->where('flow_id', $flowId);
        }

        /** @var Collection<int,AiRagFeedbackEvent> $events */
        $events = $query->get();

        return $events;
    }

    /**
     * @param  Collection<int,AiRagFeedbackEvent>  $events
     * @param  array{start:Carbon,end:Carbon,label:string}  $range
     * @param  array<string,mixed>|null  $previousValid
     * @return array<string,mixed>
     */
    private function windowReport(Collection $events, array $range, int $minTotal, AtlasContextFeedbackSignalPolicy $signalPolicy, ?array $previousValid): array
    {
        $total = $events->count();
        $eligible = $events
            ->filter(fn (AiRagFeedbackEvent $event): bool => $signalPolicy->isMeasuredAggregateEligible($event))
            ->values();

        $base = [
            'label' => $range['label'],
            'start' => $range['start']->toJSON(),
            'end' => $range['end']->toJSON(),
            'total_event_count' => $total,
            'measured_count' => $eligible->count(),
        ];

        if ($total === 0) {
            return $base + [
                'status' => 'empty',
                'roi_trend' => ['utility_delta' => null],
            ];
        }

        if ($total < $minTotal) {
            return $base + [
                'status' => 'invalid',
                'invalid_reason' => 'below_total_event_floor',
                'roi_trend' => ['utility_delta' => null],
            ];
        }

        if ($eligible->isEmpty()) {
            return $base + [
                'status' => 'empty',
                'empty_reason' => 'no_measured_events',
                'roi_trend' => ['utility_delta' => null],
            ];
        }

        $utilities = $eligible->map(fn (AiRagFeedbackEvent $event): float => (float) data_get($event->payload, 'payload.context_roi.post_execution_utility', $event->post_execution_utility));
        $usedRatios = $eligible->map(function (AiRagFeedbackEvent $event): float {
            $ratio = data_get($event->payload, 'payload.context_ref_attribution.use_ratio', data_get($event->payload, 'payload.context_roi.use_ratio'));
            if (is_numeric($ratio)) {
                return (float) $ratio;
            }

            return (int) $event->included_sources > 0 ? (int) $event->used_sources / max(1, (int) $event->included_sources) : 0.0;
        });
        $noise = $eligible->sum(fn (AiRagFeedbackEvent $event): int => (int) data_get($event->payload, 'payload.context_ref_attribution.noise_count', $event->noise_sources));
        $delivered = $eligible->sum(fn (AiRagFeedbackEvent $event): int => max(0, (int) data_get($event->payload, 'payload.context_ref_attribution.delivered_count', $event->included_sources)));
        $formulaVersion = (string) data_get($eligible->last()?->payload, 'payload.context_roi.formula_version', data_get($eligible->last()?->payload, 'payload.formula_version', 'unknown'));
        $avgUtility = round((float) $utilities->avg(), 2);

        $previousSameFormula = $previousValid !== null
            && ($previousValid['formula_version'] ?? null) === $formulaVersion;

        return $base + [
            'status' => 'valid',
            'avg_utility' => $avgUtility,
            'avg_used_ratio' => round((float) $usedRatios->avg(), 4),
            'noise_ratio' => $delivered > 0 ? round($noise / $delivered, 4) : 0.0,
            'unresolved_missed_count' => $this->unresolvedMissedCount($eligible),
            'multipliers' => $this->multipliers($eligible->last()),
            'formula_version' => $formulaVersion,
            'roi_trend' => [
                'utility_delta' => $previousSameFormula
                    ? round($avgUtility - (float) ($previousValid['avg_utility'] ?? 0.0), 2)
                    : null,
            ],
        ];
    }

    /**
     * @param  Collection<int,AiRagFeedbackEvent>  $events
     */
    private function unresolvedMissedCount(Collection $events): int
    {
        $count = 0;
        foreach ($events as $event) {
            $missed = array_values(array_filter(array_map(
                static fn (mixed $source): string => is_scalar($source) ? trim((string) $source) : '',
                (array) $event->missed_required_sources,
            )));
            $resolved = array_values((array) data_get($event->payload, 'payload.missed_resolution.resolved_source_types', []));
            foreach ($missed as $sourceType) {
                if (! in_array($sourceType, $resolved, true)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @return array<string,float>
     */
    private function multipliers(?AiRagFeedbackEvent $event): array
    {
        if (! $event instanceof AiRagFeedbackEvent) {
            return [];
        }

        $multiplier = data_get($event->payload, 'payload.applied_policy_snapshot.initial_context_budget_multiplier');
        if (is_numeric($multiplier)) {
            return ['initial_context_budget_multiplier' => (float) $multiplier];
        }

        return [];
    }
}
