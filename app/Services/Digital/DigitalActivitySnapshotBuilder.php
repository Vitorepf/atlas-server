<?php

namespace App\Services\Digital;

use App\Models\DigitalActivitySnapshot;
use App\Models\DigitalSession;
use App\Support\Metadata;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class DigitalActivitySnapshotBuilder
{
    public function rebuild(CarbonImmutable $date, string $timezone = 'America/Sao_Paulo', string $source = 'atlas_server'): DigitalActivitySnapshot
    {
        $start = $date->setTimezone($timezone)->startOfDay();
        $end = $start->endOfDay();

        $sessions = DigitalSession::query()
            ->whereNull('deleted_at')
            ->where('started_at', '<=', $end->utc())
            ->where('ended_at', '>=', $start->utc())
            ->orderBy('started_at')
            ->get()
            ->each(function (DigitalSession $session) use ($start, $end): void {
                $session->setAttribute('_snapshot_window', ['start' => $start, 'end' => $end]);
            });

        $categoryBreakdown = $this->sumByCategory($sessions);
        $sourceBreakdown = $this->sumBySource($sessions);
        $focusModeBreakdown = $this->sumByFocusMode($sessions);
        $deepWorkSessions = $sessions
            ->filter(fn (DigitalSession $session): bool => (int) $session->category_class_at_time === 1 && $this->secondsWithinWindow($session, $start, $end) >= 25 * 60);

        $payload = [
            'client_id' => $this->uuidFromHash("digital-snapshot:$source:$timezone:".$start->toDateString()),
            'source' => $source,
            'snapshot_date' => $start->toDateString(),
            'snapshot_timezone' => $timezone,
            'computed_at' => now(),
            'signal_count' => $sessions->count(),
            'total_screen_time_min' => $this->roundMinutes($this->sumSessionSeconds($sessions, $start, $end)),
            'deep_work_sessions_count' => $deepWorkSessions->count(),
            'deep_work_total_min' => $this->roundMinutes($this->sumSessionSeconds($deepWorkSessions, $start, $end)),
            'curated_input_min' => ($categoryBreakdown['1'] ?? 0) + ($categoryBreakdown['3'] ?? 0),
            'algorithmic_input_min' => $categoryBreakdown['4'] ?? 0,
            'intentional_entertainment_min' => $categoryBreakdown['5'] ?? 0,
            'default_entertainment_min' => $categoryBreakdown['6'] ?? 0,
            'communication_primary_min' => $categoryBreakdown['7'] ?? 0,
            'communication_shallow_min' => $categoryBreakdown['8'] ?? 0,
            'market_min' => $categoryBreakdown['9'] ?? 0,
            'focus_mode_active_min' => Metadata::forStorage($focusModeBreakdown),
            'category_breakdown' => Metadata::forStorage($categoryBreakdown),
            'source_breakdown' => Metadata::forStorage($sourceBreakdown),
            'raw_rize_data' => Metadata::forStorage([
                'session_ids' => $sessions->where('source', 'rize')->pluck('id')->values()->all(),
                'source_event_ids' => $sessions->where('source', 'rize')->pluck('source_event_id')->filter()->values()->all(),
            ]),
            'metadata' => Metadata::forStorage([
                'builder' => 'digital-activity-snapshot-v1',
                'recomputed_at' => now()->toJSON(),
            ]),
        ];

        return DigitalActivitySnapshot::withTrashed()->updateOrCreate(
            ['client_id' => $payload['client_id']],
            $payload
        );
    }

    /**
     * @param  Collection<int, DigitalSession>  $sessions
     */
    private function sumByCategory(Collection $sessions): array
    {
        return $sessions
            ->filter(fn (DigitalSession $session): bool => $session->category_class_at_time !== null)
            ->groupBy(fn (DigitalSession $session): string => (string) $session->category_class_at_time)
            ->map(fn (Collection $items): int => $this->roundMinutes($this->sumSessionSecondsForCurrentWindow($items)))
            ->sortKeys()
            ->all();
    }

    /**
     * @param  Collection<int, DigitalSession>  $sessions
     */
    private function sumBySource(Collection $sessions): array
    {
        return $sessions
            ->groupBy(fn (DigitalSession $session): string => $session->source_name ?: $session->source_identifier)
            ->map(fn (Collection $items): array => [
                'minutes' => $this->roundMinutes($this->sumSessionSecondsForCurrentWindow($items)),
                'source_identifier' => $items->first()?->source_identifier,
                'category_class' => $items->first()?->category_class_at_time,
                'intentionality' => $items->first()?->intentionality,
            ])
            ->sortKeys()
            ->all();
    }

    /**
     * @param  Collection<int, DigitalSession>  $sessions
     */
    private function sumByFocusMode(Collection $sessions): array
    {
        return $sessions
            ->filter(fn (DigitalSession $session): bool => is_string($session->focus_mode_active) && $session->focus_mode_active !== '')
            ->groupBy('focus_mode_active')
            ->map(fn (Collection $items): int => $this->roundMinutes($this->sumSessionSecondsForCurrentWindow($items)))
            ->sortKeys()
            ->all();
    }

    /**
     * @param  Collection<int, DigitalSession>  $sessions
     */
    private function sumSessionSeconds(Collection $sessions, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) $sessions->sum(fn (DigitalSession $session): int => $this->secondsWithinWindow($session, $start, $end));
    }

    /**
     * @param  Collection<int, DigitalSession>  $sessions
     */
    private function sumSessionSecondsForCurrentWindow(Collection $sessions): int
    {
        $window = $sessions->first()?->getAttribute('_snapshot_window');

        if (! is_array($window)) {
            return (int) $sessions->sum('duration_seconds');
        }

        return $this->sumSessionSeconds($sessions, $window['start'], $window['end']);
    }

    private function secondsWithinWindow(DigitalSession $session, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $startedAt = CarbonImmutable::parse($session->started_at);
        $endedAt = CarbonImmutable::parse($session->ended_at);
        $sessionStart = $startedAt->lessThan($start) ? $start : $startedAt;
        $sessionEnd = $endedAt->greaterThan($end) ? $end : $endedAt;

        return max(0, $sessionEnd->getTimestamp() - $sessionStart->getTimestamp());
    }

    private function roundMinutes(int|float $seconds): int
    {
        return (int) round(((float) $seconds) / 60);
    }

    private function uuidFromHash(string $value): string
    {
        $hash = md5($value);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );
    }
}
