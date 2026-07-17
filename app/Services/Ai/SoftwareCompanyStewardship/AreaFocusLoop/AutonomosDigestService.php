<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\NightShift\AreaFocusLoopReadModelService;
use App\Services\Ai\NightShift\AtlasNightShiftAreaFocusContractRegistry;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

final class AutonomosDigestService
{
    public const SCHEMA_VERSION = 'atlas.autonomos.digest.v1';

    private const DEFAULT_FOCUS = 'dev_forge';

    private const DEFAULT_HOURS = 24;

    private const DEFAULT_LIMIT = 20;

    private const MAX_HOURS = 24 * 31;

    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly Reliable24hLoopRunnerService $loopRunner,
        private readonly AtlasNightShiftAreaFocusContractRegistry $areaRegistry,
        private readonly AreaFocusLoopReadModelService $areaFocusReadModel,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function digest(array $input = []): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $hours = $this->boundedInt($input['hours'] ?? null, self::DEFAULT_HOURS, 1, self::MAX_HOURS);
        $limit = $this->boundedInt($input['limit'] ?? null, self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
        $focus = $this->publicCode($input['focus'] ?? null) ?: self::DEFAULT_FOCUS;
        $areas = $this->areas($input['area'] ?? null);
        $since = $now->modify("-{$hours} hours");

        $delivered = [];
        $risks = [];
        $pendingDecisions = [];
        $sourceStatuses = [];

        foreach ($areas as $area) {
            $records = $this->recordsInWindow($this->loopRunner->readLedgerRecords($area, $focus), $since, $now);
            foreach ($records as $record) {
                if ($this->recordIsDelivered($record)) {
                    $delivered[] = $this->deliveredItem($area, $focus, $record);
                }
                if ($this->recordIsRisk($record)) {
                    $risks[] = $this->cycleRiskItem($area, $focus, $record);
                }
            }

            try {
                $report = $this->areaFocusReadModel->project([
                    'area_id' => $area,
                    'limit' => $limit,
                ]);
                $sourceStatuses[$area] = [
                    'area_focus_read_model' => (string) ($report['status'] ?? 'unknown'),
                ];
                foreach ($this->findings($report) as $finding) {
                    if ($this->findingIsRisk($finding)) {
                        $risks[] = $this->findingRiskItem($area, $focus, $finding);
                    }
                    if ($this->findingNeedsDecision($finding)) {
                        $pendingDecisions[] = $this->pendingDecisionItem($area, $focus, $finding);
                    }
                }
            } catch (Throwable $e) {
                $sourceStatuses[$area] = [
                    'area_focus_read_model' => 'unavailable',
                    'reason' => $this->publicCode($e->getMessage()) ?: 'source_unavailable',
                ];
            }
        }

        $delivered = $this->sortByRecordedAtDesc($delivered);
        $risks = $this->sortRisks($risks);
        $pendingDecisions = $this->sortPendingDecisions($pendingDecisions);

        return $this->finalize([
            'schema_version' => self::SCHEMA_VERSION,
            'read_only' => true,
            'provider_safe' => true,
            // No active Autonomos digest scheduler is registered in Laravel scheduling.
            // A configured legacy morning-digest time is not enough to claim a next run.
            'next_digest_at' => null,
            'schedule' => [
                'available' => false,
                'source' => null,
                'reason' => 'no_active_autonomos_digest_schedule_found',
            ],
            'last' => [
                'window' => [
                    'kind' => 'rolling',
                    'hours' => $hours,
                    'started_at' => $since->format(DateTimeInterface::ATOM),
                    'ended_at' => $now->format(DateTimeInterface::ATOM),
                    'timezone' => 'UTC',
                    'areas' => $areas,
                    'focus' => $focus,
                ],
                'counts' => [
                    'delivered' => count($delivered),
                    'risks' => count($risks),
                    'pending_decisions' => count($pendingDecisions),
                ],
                'delivered' => array_slice($delivered, 0, $limit),
                'risks' => array_slice($risks, 0, $limit),
                'pending_decisions' => array_slice($pendingDecisions, 0, $limit),
                'source_statuses' => $sourceStatuses,
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function areas(mixed $requested): array
    {
        $registered = $this->areaRegistry->registeredAreas();
        $area = $this->publicArea($requested);
        if ($area !== '' && in_array($area, $registered, true)) {
            return [$area];
        }

        return array_values($registered);
    }

    /**
     * @param  list<array<string,mixed>>  $records
     * @return list<array<string,mixed>>
     */
    private function recordsInWindow(array $records, DateTimeImmutable $since, DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            $records,
            fn (array $record): bool => ($recordedAt = $this->date($record['recorded_at'] ?? null)) !== null
                && $recordedAt >= $since
                && $recordedAt <= $now,
        ));
    }

    /** @param array<string,mixed> $record */
    private function recordIsDelivered(array $record): bool
    {
        return (string) ($record['outcome'] ?? '') === 'merged'
            && ($record['merge_performed'] ?? false) === true
            && $this->publicMergeHash($record['merge_hash'] ?? null) !== '';
    }

    /** @param array<string,mixed> $record */
    private function recordIsRisk(array $record): bool
    {
        return (string) ($record['outcome'] ?? '') === 'blocked'
            || (bool) ($record['quarantined'] ?? false)
            || $this->publicCodes($record['blockers'] ?? null) !== [];
    }

    /**
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    private function deliveredItem(string $area, string $focus, array $record): array
    {
        return [
            'source' => 'cycle_ledger',
            'area_id' => $area,
            'focus' => $focus,
            'cycle_index' => max(0, (int) ($record['cycle_index'] ?? 0)),
            'cycle_id' => $this->publicCode($record['cycle_id'] ?? null),
            'outcome' => $this->publicCode($record['outcome'] ?? null),
            'cycle_final_status' => $this->publicCode($record['cycle_final_status'] ?? null),
            'merge_performed' => true,
            'merge_hash' => $this->publicMergeHash($record['merge_hash'] ?? null),
            'recorded_at' => $this->publicTimestamp($record['recorded_at'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    private function cycleRiskItem(string $area, string $focus, array $record): array
    {
        $blockers = $this->publicCodes($record['blockers'] ?? null);

        return [
            'source' => 'cycle_ledger',
            'area_id' => $area,
            'focus' => $focus,
            'cycle_index' => max(0, (int) ($record['cycle_index'] ?? 0)),
            'cycle_id' => $this->publicCode($record['cycle_id'] ?? null),
            'severity' => (bool) ($record['quarantined'] ?? false) ? 'critical' : 'high',
            'reason' => $blockers[0] ?? $this->publicCode($record['cycle_final_status'] ?? null) ?: 'blocked_cycle',
            'blockers' => $blockers,
            'quarantined' => (bool) ($record['quarantined'] ?? false),
            'recorded_at' => $this->publicTimestamp($record['recorded_at'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return list<array<string,mixed>>
     */
    private function findings(array $report): array
    {
        return array_values(array_filter((array) ($report['findings'] ?? []), 'is_array'));
    }

    /** @param array<string,mixed> $finding */
    private function findingIsRisk(array $finding): bool
    {
        return in_array($this->severity($finding), ['high', 'critical'], true);
    }

    /** @param array<string,mixed> $finding */
    private function findingNeedsDecision(array $finding): bool
    {
        return (bool) ($finding['operator_decision_required'] ?? false)
            || in_array((string) ($finding['route'] ?? ''), ['inbox_only', 'operator_review'], true);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function findingRiskItem(string $area, string $focus, array $finding): array
    {
        return [
            'source' => 'area_focus_read_model',
            'area_id' => $area,
            'focus' => $focus,
            'finding_id' => $this->publicId($finding['finding_id'] ?? null),
            'title' => $this->publicText($finding['title'] ?? null, 160),
            'severity' => $this->severity($finding),
            'route' => $this->publicCode($finding['route'] ?? null),
            'route_reason' => $this->publicCode($finding['route_reason'] ?? null),
            'priority_score' => (int) ($finding['priority_score'] ?? 0),
            'evidence_refs' => $this->publicRefs($finding['evidence_refs'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function pendingDecisionItem(string $area, string $focus, array $finding): array
    {
        return [
            'source' => 'area_focus_read_model',
            'area_id' => $area,
            'focus' => $focus,
            'finding_id' => $this->publicId($finding['finding_id'] ?? null),
            'title' => $this->publicText($finding['title'] ?? null, 160),
            'severity' => $this->severity($finding),
            'route' => $this->publicCode($finding['route'] ?? null),
            'route_reason' => $this->publicCode($finding['route_reason'] ?? null),
            'priority_score' => (int) ($finding['priority_score'] ?? 0),
            'operator_decision_required' => true,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private function sortByRecordedAtDesc(array $items): array
    {
        usort($items, static fn (array $a, array $b): int => strcmp((string) ($b['recorded_at'] ?? ''), (string) ($a['recorded_at'] ?? '')));

        return $items;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private function sortRisks(array $items): array
    {
        usort($items, function (array $a, array $b): int {
            $recorded = strcmp((string) ($b['recorded_at'] ?? ''), (string) ($a['recorded_at'] ?? ''));
            if ($recorded !== 0) {
                return $recorded;
            }
            $severity = $this->severityRank((string) ($b['severity'] ?? '')) <=> $this->severityRank((string) ($a['severity'] ?? ''));
            if ($severity !== 0) {
                return $severity;
            }
            $priority = (int) ($b['priority_score'] ?? 0) <=> (int) ($a['priority_score'] ?? 0);
            if ($priority !== 0) {
                return $priority;
            }

            return strcmp((string) ($a['source'] ?? ''), (string) ($b['source'] ?? ''));
        });

        return $items;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private function sortPendingDecisions(array $items): array
    {
        usort($items, function (array $a, array $b): int {
            $priority = (int) ($b['priority_score'] ?? 0) <=> (int) ($a['priority_score'] ?? 0);
            if ($priority !== 0) {
                return $priority;
            }

            return $this->severityRank((string) ($b['severity'] ?? '')) <=> $this->severityRank((string) ($a['severity'] ?? ''));
        });

        return $items;
    }

    /** @param array<string,mixed> $finding */
    private function severity(array $finding): string
    {
        $severity = strtolower($this->publicCode($finding['severity'] ?? null));

        return in_array($severity, ['low', 'medium', 'high', 'critical'], true) ? $severity : 'unknown';
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }

    private function boundedInt(mixed $value, int $default, int $min, int $max): int
    {
        $int = is_numeric($value) ? (int) $value : $default;

        return max($min, min($max, $int));
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private function publicTimestamp(mixed $value): string
    {
        return $this->date($value)?->format(DateTimeInterface::ATOM) ?? '';
    }

    private function publicArea(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^[a-z0-9][a-z0-9_-]{0,80}$/i', $value) === 1 ? $value : '';
    }

    private function publicCode(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^[a-z0-9][a-z0-9_:-]{0,119}$/i', $value) === 1 ? $value : '';
    }

    private function publicId(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^[a-z0-9][a-z0-9_.:-]{0,160}$/i', $value) === 1 ? $value : '';
    }

    private function publicMergeHash(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^(?:sha256:)?[a-f0-9]{7,128}$/i', $value) === 1 ? $value : '';
    }

    /**
     * @return list<string>
     */
    private function publicCodes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): string => $this->publicCode($item),
            $value,
        )));
    }

    /**
     * @return list<string>
     */
    private function publicRefs(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_slice(array_filter(array_map(
            fn (mixed $item): string => $this->publicText($item, 180),
            $value,
        )), 0, 5));
    }

    private function publicText(mixed $value, int $limit): string
    {
        $text = is_string($value) ? trim($value) : '';
        $text = preg_replace('/[\x00-\x1F\x7F]/', ' ', $text) ?? '';
        $text = preg_replace('/\s+/', ' ', $text) ?? '';

        return mb_substr($text, 0, $limit);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $hashPayload = $payload;
        unset($hashPayload['surface_hash'], $hashPayload['generated_at']);

        $payload['surface_hash'] = 'sha256:'.hash('sha256', json_encode($hashPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        return $payload;
    }
}
