<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Console\Commands\AtlasAcosFreezeCommand;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionSurfaceRegistry;
use App\Services\Ai\EngineeringKernel\KernelEvidenceAuthority;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Throwable;

final class AcosMaxVerifiedShareService
{
    public const FIELD_FREEZE = 'freeze';
    public const FIELD_FREEZE_REQUIRED = 'freeze_required';
    public const SCHEMA_VERSION = 'atlas.acos_max.verified_share.v1';

    public const MEASURE_ID = 'acos.verified_share.v1';

    public const FORMULA_VERSION = 'verified_share.v1';

    /** @var list<string> */
    public const EXECUTORS = ['dev', 'forge', 'autonomos'];

    public const DEFAULT_VERIFIED_SHARE_MIN = 0.80;

    public const DEFAULT_WINDOW_DAYS_MIN = 14;

    public const DEFAULT_DENOMINATOR_MIN_EXECUTIONS = 50;

    public const DEFAULT_TTL_DAYS = 30;

    public const KIND_MEASURE_FREEZE = 'measure_freeze';

    public const STATUS_MISSING_FREEZE = 'missing_freeze';

    public const STATUS_INSUFFICIENT_SIGNAL = 'insufficient_signal';

    public const STATUS_OK = 'ok';

    public const STATUS_BELOW_THRESHOLD = 'below_threshold';

    public const REASON_MEASURE_FREEZE_NOT_RECORDED = 'measure_freeze_not_recorded';

    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_STATUS = 'status';
    public const FIELD_REASON = 'reason';
    public const FIELD_MEASURE_ID = 'measure_id';
    public const FIELD_FORMULA_VERSION = 'formula_version';
    public const FIELD_KIND = 'kind';
    public const FIELD_THRESHOLDS = 'thresholds';
    public const FIELD_DENOMINATOR_MIN = 'denominator_min';
    public const FIELD_AUTHOR_ENGINE_ID = 'author_engine_id';
    public const FIELD_JUDGE_ENGINE_ID = 'judge_engine_id';
    public const FIELD_SERIES_REGISTRY = 'series_registry';
    public const FIELD_FORMULA = 'formula';
    public const FIELD_VERIFIED_SHARE_MIN = 'verified_share_min';
    public const FIELD_WINDOW_DAYS_MIN = 'window_days_min';
    public const FIELD_DENOMINATOR_MIN_EXECUTIONS = 'denominator_min_executions';
    public const FIELD_TTL_DAYS = 'ttl_days';
    public const FIELD_SERIES = 'series';
    public const FIELD_PATH = 'path';
    public const FIELD_WATCHDOG_PLUGIN = 'watchdog_plugin';
    public const FIELD_ACTOR = 'actor';
    public const FIELD_AGGREGATE = 'aggregate';
    public const FIELD_CONTENT_HASH = 'content_hash';
    public const FIELD_EVENT_NAME = 'event_name';
    public const FIELD_EXECUTORS = 'executors';
    public const FIELD_TOTAL_COUNT = 'total_count';
    public const FIELD_VERIFIED_SHARE = 'verified_share';
    public const FIELD_WINDOW_DAYS = 'window_days';
    public const FIELD_VERIFIED_COUNT = 'verified_count';
    public const FIELD_JUDGE_AUTHOR_DISTINCT = 'judge_author_distinct';
    public const FIELD_MODE = 'mode';
    public const FIELD_OUTCOME_DENOMINATOR = 'outcome_denominator';
    public const FIELD_OWNER = 'owner';
    public const FIELD_RECORDED_AT = 'recorded_at';
    public const FIELD_ROLE = 'role';
    public const FIELD_SOURCES = 'sources';
    public const FIELD_SURFACE = 'surface';
    public const FIELD_TASK_CATEGORY = 'task_category';
    public const FIELD_VERIFICATION_NUMERATOR = 'verification_numerator';
    public const FIELD_ENFORCE = 'enforce';


    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        return [
            self::FIELD_KIND => self::KIND_MEASURE_FREEZE,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_FORMULA => 'verified_share = enforce-mode verification receipts ÷ OUTC-01 outcome receipts, grouped by executor',
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_THRESHOLDS => [
                self::FIELD_VERIFIED_SHARE_MIN => self::DEFAULT_VERIFIED_SHARE_MIN,
                self::FIELD_WINDOW_DAYS_MIN => self::DEFAULT_WINDOW_DAYS_MIN,
                self::FIELD_DENOMINATOR_MIN_EXECUTIONS => self::DEFAULT_DENOMINATOR_MIN_EXECUTIONS,
            ],
            self::FIELD_DENOMINATOR_MIN => self::DEFAULT_DENOMINATOR_MIN_EXECUTIONS,
            self::FIELD_TTL_DAYS => self::DEFAULT_TTL_DAYS,
            self::FIELD_AUTHOR_ENGINE_ID => 'cursor-acos-max-elev12',
            self::FIELD_JUDGE_ENGINE_ID => 'codex-elev12-judge',
            self::FIELD_SERIES_REGISTRY => [
                self::FIELD_SERIES => self::MEASURE_ID,
                self::FIELD_PATH => 'atlas:acos:verified-share --json',
                self::FIELD_WATCHDOG_PLUGIN => 'wdg-01.acos_verified_share',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function report(?int $days = null): array
    {
        $freeze = $this->freeze();
        if ($freeze === null) {
            return [
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_MEASURE_ID => self::MEASURE_ID,
                self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
                self::FIELD_STATUS => self::STATUS_MISSING_FREEZE,
                self::FIELD_REASON => self::REASON_MEASURE_FREEZE_NOT_RECORDED,
                self::FIELD_FREEZE_REQUIRED => self::freezePayload(),
            ];
        }

        $windowDays = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($days) ?? data_get($freeze, 'thresholds.window_days_min', self::DEFAULT_WINDOW_DAYS_MIN)));
        $since = CarbonImmutable::now('UTC')->subDays($windowDays);
        $totals = $this->emptyCounts();
        $verified = $this->emptyCounts();

        foreach ($this->liveOutcomeRows($since) as $row) {
            $executor = $this->executorFromOutcome($row);
            if ($executor !== null) {
                $totals[$executor]++;
            }
        }

        foreach ($this->coverageRows($since) as $row) {
            $executor = $this->executorFromCoverage($row);
            if ($executor !== null) {
                $verified[$executor]++;
            }
        }

        $executors = [];
        foreach (self::EXECUTORS as $executor) {
            $executors[$executor] = $this->countPayload($verified[$executor], $totals[$executor]);
        }

        $aggregate = $this->countPayload(array_sum($verified), array_sum($totals));
        $denominatorMin = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($freeze[self::FIELD_DENOMINATOR_MIN] ?? null) ?? data_get($freeze, 'thresholds.denominator_min_executions', self::DEFAULT_DENOMINATOR_MIN_EXECUTIONS)));
        $shareMin = AiValueNormalizer::finiteFloatOrNull(data_get($freeze, 'thresholds.verified_share_min', self::DEFAULT_VERIFIED_SHARE_MIN)) ?? self::DEFAULT_VERIFIED_SHARE_MIN;
        $authorEngineId = AiValueNormalizer::trimmedStringOrNull($freeze[self::FIELD_AUTHOR_ENGINE_ID] ?? null) ?? '';
        $judgeEngineId = AiValueNormalizer::trimmedStringOrNull($freeze[self::FIELD_JUDGE_ENGINE_ID] ?? null) ?? '';

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_STATUS => $this->status($aggregate, $denominatorMin, $shareMin),
            self::FIELD_WINDOW_DAYS => $windowDays,
            self::FIELD_FREEZE => [
                self::FIELD_MEASURE_ID => AiValueNormalizer::trimmedStringOrNull($freeze[self::FIELD_MEASURE_ID] ?? null) ?? self::MEASURE_ID,
                self::FIELD_CONTENT_HASH => AiValueNormalizer::trimmedStringOrNull($freeze[self::FIELD_CONTENT_HASH] ?? null) ?? '',
                self::FIELD_DENOMINATOR_MIN => $denominatorMin,
                self::FIELD_THRESHOLDS => AiValueNormalizer::arrayOrEmpty($freeze[self::FIELD_THRESHOLDS] ?? null),
                self::FIELD_AUTHOR_ENGINE_ID => $authorEngineId,
                self::FIELD_JUDGE_ENGINE_ID => $judgeEngineId,
                self::FIELD_JUDGE_AUTHOR_DISTINCT => $authorEngineId !== '' && $authorEngineId !== $judgeEngineId,
                self::FIELD_SERIES_REGISTRY => AiValueNormalizer::arrayOrEmpty($freeze[self::FIELD_SERIES_REGISTRY] ?? null),
            ],
            self::FIELD_AGGREGATE => $aggregate,
            self::FIELD_EXECUTORS => $executors,
            self::FIELD_SOURCES => [
                self::FIELD_OUTCOME_DENOMINATOR => 'storage/atlas/atlas_decide/live_outcomes.jsonl',
                self::FIELD_VERIFICATION_NUMERATOR => 'atlas_ledger_events engineering.execution.coverage.recorded mode=enforce',
            ],
        ];
    }

    /** @param array{verified_count:int,total_count:int,verified_share:?float} $aggregate */
    private function status(array $aggregate, int $denominatorMin, float $shareMin): string
    {
        if ($aggregate[self::FIELD_TOTAL_COUNT] < $denominatorMin) {
            return self::STATUS_INSUFFICIENT_SIGNAL;
        }

        return (AiValueNormalizer::finiteFloatOrNull($aggregate[self::FIELD_VERIFIED_SHARE] ?? null) ?? 0.0) >= $shareMin ? self::STATUS_OK : self::STATUS_BELOW_THRESHOLD;
    }

    /** @return array<string,int> */
    private function emptyCounts(): array
    {
        return array_fill_keys(self::EXECUTORS, 0);
    }

    /** @return array{verified_count:int,total_count:int,verified_share:?float} */
    private function countPayload(int $verified, int $total): array
    {
        return [
            self::FIELD_VERIFIED_COUNT => $verified,
            self::FIELD_TOTAL_COUNT => $total,
            self::FIELD_VERIFIED_SHARE => $total > 0 ? round($verified / $total, 4) : null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function freeze(): ?array
    {
        $path = storage_path(AtlasAcosFreezeCommand::JSONL_RELATIVE_PATH);
        if (! is_file($path)) {
            return null;
        }

        $latest = null;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                try {
                    $row = json_decode(AiValueNormalizer::trimmedStringOrNull($line) ?? '', true, flags: JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    continue;
                }
                if (is_array($row)
                    && ($row[self::FIELD_KIND] ?? null) === self::KIND_MEASURE_FREEZE
                    && ($row[self::FIELD_MEASURE_ID] ?? null) === self::MEASURE_ID
                    && $this->judgeAuthorDistinct($row)) {
                    $latest = $row;
                }
            }
        } finally {
            fclose($handle);
        }

        return $latest;
    }

    /** @param array<string,mixed> $row */
    private function judgeAuthorDistinct(array $row): bool
    {
        $author = AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_AUTHOR_ENGINE_ID] ?? null) ?? '';
        $judge = AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_JUDGE_ENGINE_ID] ?? null) ?? '';

        return $author !== '' && $judge !== '' && $author !== $judge;
    }

    /** @return list<array<string,mixed>> */
    private function liveOutcomeRows(CarbonImmutable $since): array
    {
        $path = storage_path('atlas/atlas_decide/live_outcomes.jsonl');
        if (! is_file($path)) {
            return [];
        }

        $rows = [];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            while (($line = fgets($handle)) !== false) {
                try {
                    $row = json_decode(AiValueNormalizer::trimmedStringOrNull($line) ?? '', true, flags: JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    continue;
                }
                if (! is_array($row)) {
                    continue;
                }
                $recordedAt = $this->parseDate($row[self::FIELD_RECORDED_AT] ?? null);
                if ($recordedAt !== null && $recordedAt->greaterThanOrEqualTo($since)) {
                    $rows[] = $row;
                }
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function coverageRows(CarbonImmutable $since): array
    {
        if (! DatabaseTableAvailability::has('atlas_ledger_events')) {
            return [];
        }

        return AtlasLedgerEvent::query()
            ->where('emitter_stage', KernelEvidenceAuthority::EMITTER_STAGE)
            ->where('occurred_at', '>=', $since)
            ->orderBy('occurred_at')
            ->get()
            ->map(static fn (AtlasLedgerEvent $event): array => (array) $event->payload)
            ->filter(static fn (array $payload): bool => ($payload[self::FIELD_EVENT_NAME] ?? null) === 'engineering.execution.coverage.recorded'
                && ($payload[self::FIELD_MODE] ?? null) === self::FIELD_ENFORCE)
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $row */
    private function executorFromOutcome(array $row): ?string
    {
        $actor = AiValueNormalizer::lowerTrimmedString($row[self::FIELD_ACTOR] ?? '');
        if (str_starts_with($actor, 'engineering_outcome_spine:')) {
            return $this->normalizeExecutor(substr($actor, strlen('engineering_outcome_spine:')));
        }

        return $this->normalizeExecutor(AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_TASK_CATEGORY] ?? $row[self::FIELD_ROLE] ?? null) ?? '');
    }

    /** @param array<string,mixed> $row */
    private function executorFromCoverage(array $row): ?string
    {
        $surface = AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_SURFACE] ?? null) ?? '';
        $owner = AiValueNormalizer::trimmedStringOrNull(EngineeringExecutionSurfaceRegistry::surface($surface)[self::FIELD_OWNER] ?? null) ?? '';

        return $this->normalizeExecutor($owner);
    }

    private function normalizeExecutor(string $value): ?string
    {
        $value = AiValueNormalizer::lowerTrimmedString($value);

        return match (true) {
            in_array($value, ['dev', 'atlas_dev', 'atlas-dev'], true) => 'dev',
            in_array($value, ['forge', 'atlas_forge', 'atlas-forge'], true) => 'forge',
            in_array($value, ['autonomos', 'autonomous', 'atlas_autonomos', 'atlas-autonomos'], true) => 'autonomos',
            default => null,
        };
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (AiValueNormalizer::trimmedStringOrNull($value) === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
