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

    public const CHECK_ID_UNKNOWN = 'unknown';
    public const FIELD_MESSAGE = 'message';
    public const FIELD_EXCEPTION_CLASS = 'exception_class';
    public const FIELD_CODE = 'code';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_RUN_ID = 'run_id';
    public const FIELD_CHECKED_AT = 'checked_at';
    public const FIELD_STATUS = 'status';
    public const FIELD_COUNTS = 'counts';
    public const FIELD_CORRELATION_ID = 'correlation_id';
    public const FIELD_ENVELOPE_ID = 'envelope_id';
    public const FIELD_OPERATOR_ID = 'operator_id';
    public const FIELD_TENANT_ID = 'tenant_id';
    public const FIELD_LEDGER_EVENT_ID = 'ledger_event_id';
    public const FIELD_CHECK_ID = 'check_id';
    public const FIELD_CHECKS = 'checks';
    public const FIELD_ALERTS = 'alerts';
    public const FIELD_ID = 'id';
    public const FIELD_TOTAL = 'total';
    public const FIELD_EMITTER_STAGE = 'emitter_stage';
    public const FIELD_EMITTER_VERSION = 'emitter_version';
    public const FIELD_SCOPE_ID = 'scope_id';
    public const FIELD_SCOPE_TYPE = 'scope_type';

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
        $runId = AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_RUN_ID] ?? null) ?? (string) Str::ulid();
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
                        self::FIELD_EXCEPTION_CLASS => class_basename($e),
                        self::FIELD_MESSAGE => $e->getMessage(),
                    ],
                    alert: [
                        self::FIELD_CODE => 'watchdog_check_exception',
                        self::FIELD_MESSAGE => 'Watchdog check threw; other checks continued.',
                    ],
                )->toArray();
            }

            $checks[] = [self::FIELD_ID => $id] + $row;
        }

        $payload = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_RUN_ID => $runId,
            self::FIELD_CHECKED_AT => $checkedAt,
            self::FIELD_STATUS => $this->aggregateStatus($checks),
            AtlasWatchdogCheckResult::STATUS_ALERT => $this->hasAlert($checks),
            self::FIELD_COUNTS => $this->counts($checks),
            self::FIELD_CHECKS => $checks,
            self::FIELD_ALERTS => $this->alerts($checks),
        ];

        $event = $this->recordLedger($payload, $context);
        $payload[self::FIELD_LEDGER_EVENT_ID] = $event?->event_id;

        return $payload;
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     */
    private function aggregateStatus(array $checks): string
    {
        $statuses = array_map(
            static fn (array $check): string => AiValueNormalizer::trimmedStringOrNull($check[self::FIELD_STATUS] ?? null) ?? '',
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
            self::FIELD_TOTAL => count($checks),
            AtlasWatchdogCheckResult::STATUS_OK => 0,
            AtlasWatchdogCheckResult::STATUS_WARNING => 0,
            AtlasWatchdogCheckResult::STATUS_ALERT => 0,
            AtlasWatchdogCheckResult::STATUS_SKIPPED => 0,
            AtlasWatchdogCheckResult::STATUS_ERROR => 0,
        ];

        foreach ($checks as $check) {
            $status = AiValueNormalizer::trimmedStringOrNull($check[self::FIELD_STATUS] ?? null) ?? '';
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
            if (! is_array($check[AtlasWatchdogCheckResult::FIELD_ALERT] ?? null)) {
                continue;
            }

            $alerts[] = [self::FIELD_CHECK_ID => AiValueNormalizer::trimmedStringOrNull($check[self::FIELD_ID] ?? null) ?? self::CHECK_ID_UNKNOWN] + $check[AtlasWatchdogCheckResult::FIELD_ALERT];
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
                self::FIELD_TENANT_ID => $context[self::FIELD_TENANT_ID] ?? 'default',
                self::FIELD_OPERATOR_ID => $context[self::FIELD_OPERATOR_ID] ?? 'system',
                self::FIELD_ENVELOPE_ID => $context[self::FIELD_ENVELOPE_ID] ?? 'acos:watchdog:run:'.$payload[self::FIELD_RUN_ID],
                self::FIELD_CORRELATION_ID => $context[self::FIELD_CORRELATION_ID] ?? 'acos:watchdog:run:'.$payload[self::FIELD_RUN_ID],
                self::FIELD_SCOPE_TYPE => 'acos_watchdog',
                self::FIELD_SCOPE_ID => 'unified',
                self::FIELD_EMITTER_STAGE => 'atlas.acos.watchdog',
                self::FIELD_EMITTER_VERSION => self::SCHEMA_VERSION,
            ]);
        } catch (Throwable) {
            return null;
        }
    }
}
