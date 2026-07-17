<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\AiValueNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

final readonly class AtlasWatchdogRunner
{
    public const SCHEMA_VERSION = 'atlas.acos.watchdog_run.v1';

    public const AGGREGATE_STATUS_ALERT = 'alert';

    public const AGGREGATE_STATUS_WARNING = 'warning';

    public const AGGREGATE_STATUS_HEALTHY = 'healthy';

    public function __construct(
        private AtlasWatchdogCheckRegistry $registry,
        private AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function run(array $context = []): array
    {
        $runId = AiValueNormalizer::trimmedStringOrNull($context['run_id'] ?? null) ?? (string) Str::ulid();
        $checkedAt = CarbonImmutable::now('UTC')->toISOString();
        $checks = [];

        foreach ($this->registry->all() as $check) {
            $id = trim($check->id());
            try {
                $result = $check->run();
                $row = $result->toArray();
            } catch (Throwable $e) {
                $row = AtlasWatchdogCheckResult::error(
                    evidence: [
                        'exception_class' => class_basename($e),
                        'message' => $e->getMessage(),
                    ],
                    alert: [
                        'code' => 'watchdog_check_exception',
                        'message' => 'Watchdog check threw; other checks continued.',
                    ],
                )->toArray();
            }

            $checks[] = ['id' => $id] + $row;
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'checked_at' => $checkedAt,
            'status' => $this->aggregateStatus($checks),
            'alert' => $this->hasAlert($checks),
            'counts' => $this->counts($checks),
            'checks' => $checks,
            'alerts' => $this->alerts($checks),
        ];

        $event = $this->recordLedger($payload, $context);
        $payload['ledger_event_id'] = $event?->event_id;

        return $payload;
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     */
    private function aggregateStatus(array $checks): string
    {
        $statuses = array_map(
            static fn (array $check): string => AiValueNormalizer::trimmedStringOrNull($check['status'] ?? null) ?? '',
            $checks,
        );

        if (in_array(AtlasWatchdogCheckResult::STATUS_ALERT, $statuses, true)
            || in_array(AtlasWatchdogCheckResult::STATUS_ERROR, $statuses, true)) {
            return self::AGGREGATE_STATUS_ALERT;
        }

        if (in_array(AtlasWatchdogCheckResult::STATUS_WARNING, $statuses, true)) {
            return self::AGGREGATE_STATUS_WARNING;
        }

        return self::AGGREGATE_STATUS_HEALTHY;
    }

    /** @param list<array<string,mixed>> $checks */
    private function hasAlert(array $checks): bool
    {
        return $this->alerts($checks) !== [];
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return array<string,int>
     */
    private function counts(array $checks): array
    {
        $counts = [
            'total' => count($checks),
            'ok' => 0,
            'warning' => 0,
            'alert' => 0,
            'skipped' => 0,
            'error' => 0,
        ];

        foreach ($checks as $check) {
            $status = AiValueNormalizer::trimmedStringOrNull($check['status'] ?? null) ?? '';
            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }

        return $counts;
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return list<array<string,mixed>>
     */
    private function alerts(array $checks): array
    {
        $alerts = [];
        foreach ($checks as $check) {
            if (! is_array($check['alert'] ?? null)) {
                continue;
            }

            $alerts[] = ['check_id' => AiValueNormalizer::trimmedStringOrNull($check['id'] ?? null) ?? 'unknown'] + $check['alert'];
        }

        return $alerts;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     */
    private function recordLedger(array $payload, array $context): ?AtlasLedgerEvent
    {
        try {
            return $this->ledger->record(LedgerEventType::WatchdogRunRecorded, $payload, [
                'tenant_id' => $context['tenant_id'] ?? 'default',
                'operator_id' => $context['operator_id'] ?? 'system',
                'envelope_id' => $context['envelope_id'] ?? 'acos:watchdog:run:'.$payload['run_id'],
                'correlation_id' => $context['correlation_id'] ?? 'acos:watchdog:run:'.$payload['run_id'],
                'scope_type' => 'acos_watchdog',
                'scope_id' => 'unified',
                'emitter_stage' => 'atlas.acos.watchdog',
                'emitter_version' => self::SCHEMA_VERSION,
            ]);
        } catch (Throwable) {
            return null;
        }
    }
}
