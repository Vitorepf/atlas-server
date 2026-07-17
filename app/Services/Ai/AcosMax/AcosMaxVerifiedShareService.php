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

    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        return [
            'kind' => self::KIND_MEASURE_FREEZE,
            'measure_id' => self::MEASURE_ID,
            'formula' => 'verified_share = enforce-mode verification receipts ÷ OUTC-01 outcome receipts, grouped by executor',
            'formula_version' => self::FORMULA_VERSION,
            'thresholds' => [
                'verified_share_min' => self::DEFAULT_VERIFIED_SHARE_MIN,
                'window_days_min' => self::DEFAULT_WINDOW_DAYS_MIN,
                'denominator_min_executions' => self::DEFAULT_DENOMINATOR_MIN_EXECUTIONS,
            ],
            'denominator_min' => self::DEFAULT_DENOMINATOR_MIN_EXECUTIONS,
            'ttl_days' => self::DEFAULT_TTL_DAYS,
            'author_engine_id' => 'cursor-acos-max-elev12',
            'judge_engine_id' => 'codex-elev12-judge',
            'series_registry' => [
                'series' => self::MEASURE_ID,
                'path' => 'atlas:acos:verified-share --json',
                'watchdog_plugin' => 'wdg-01.acos_verified_share',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function report(?int $days = null): array
    {
        $freeze = $this->freeze();
        if ($freeze === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'measure_id' => self::MEASURE_ID,
                'formula_version' => self::FORMULA_VERSION,
                'status' => self::STATUS_MISSING_FREEZE,
                'reason' => self::REASON_MEASURE_FREEZE_NOT_RECORDED,
                'freeze_required' => self::freezePayload(),
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
        $denominatorMin = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($freeze['denominator_min'] ?? null) ?? data_get($freeze, 'thresholds.denominator_min_executions', self::DEFAULT_DENOMINATOR_MIN_EXECUTIONS)));
        $shareMin = AiValueNormalizer::finiteFloatOrNull(data_get($freeze, 'thresholds.verified_share_min', self::DEFAULT_VERIFIED_SHARE_MIN)) ?? self::DEFAULT_VERIFIED_SHARE_MIN;
        $authorEngineId = AiValueNormalizer::trimmedStringOrNull($freeze['author_engine_id'] ?? null) ?? '';
        $judgeEngineId = AiValueNormalizer::trimmedStringOrNull($freeze['judge_engine_id'] ?? null) ?? '';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'status' => $this->status($aggregate, $denominatorMin, $shareMin),
            'window_days' => $windowDays,
            'freeze' => [
                'measure_id' => AiValueNormalizer::trimmedStringOrNull($freeze['measure_id'] ?? null) ?? self::MEASURE_ID,
                'content_hash' => AiValueNormalizer::trimmedStringOrNull($freeze['content_hash'] ?? null) ?? '',
                'denominator_min' => $denominatorMin,
                'thresholds' => AiValueNormalizer::arrayOrEmpty($freeze['thresholds'] ?? null),
                'author_engine_id' => $authorEngineId,
                'judge_engine_id' => $judgeEngineId,
                'judge_author_distinct' => $authorEngineId !== '' && $authorEngineId !== $judgeEngineId,
                'series_registry' => AiValueNormalizer::arrayOrEmpty($freeze['series_registry'] ?? null),
            ],
            'aggregate' => $aggregate,
            'executors' => $executors,
            'sources' => [
                'outcome_denominator' => 'storage/atlas/atlas_decide/live_outcomes.jsonl',
                'verification_numerator' => 'atlas_ledger_events engineering.execution.coverage.recorded mode=enforce',
            ],
        ];
    }

    /** @param array{verified_count:int,total_count:int,verified_share:?float} $aggregate */
    private function status(array $aggregate, int $denominatorMin, float $shareMin): string
    {
        if ($aggregate['total_count'] < $denominatorMin) {
            return self::STATUS_INSUFFICIENT_SIGNAL;
        }

        return (AiValueNormalizer::finiteFloatOrNull($aggregate['verified_share'] ?? null) ?? 0.0) >= $shareMin ? self::STATUS_OK : self::STATUS_BELOW_THRESHOLD;
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
            'verified_count' => $verified,
            'total_count' => $total,
            'verified_share' => $total > 0 ? round($verified / $total, 4) : null,
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
                    && ($row['kind'] ?? null) === 'measure_freeze'
                    && ($row['measure_id'] ?? null) === self::MEASURE_ID
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
        $author = AiValueNormalizer::trimmedStringOrNull($row['author_engine_id'] ?? null) ?? '';
        $judge = AiValueNormalizer::trimmedStringOrNull($row['judge_engine_id'] ?? null) ?? '';

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
                $recordedAt = $this->parseDate($row['recorded_at'] ?? null);
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
            ->filter(static fn (array $payload): bool => ($payload['event_name'] ?? null) === 'engineering.execution.coverage.recorded'
                && ($payload['mode'] ?? null) === 'enforce')
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $row */
    private function executorFromOutcome(array $row): ?string
    {
        $actor = AiValueNormalizer::lowerTrimmedString($row['actor'] ?? '');
        if (str_starts_with($actor, 'engineering_outcome_spine:')) {
            return $this->normalizeExecutor(substr($actor, strlen('engineering_outcome_spine:')));
        }

        return $this->normalizeExecutor(AiValueNormalizer::trimmedStringOrNull($row['task_category'] ?? $row['role'] ?? null) ?? '');
    }

    /** @param array<string,mixed> $row */
    private function executorFromCoverage(array $row): ?string
    {
        $surface = AiValueNormalizer::trimmedStringOrNull($row['surface'] ?? null) ?? '';
        $owner = AiValueNormalizer::trimmedStringOrNull(EngineeringExecutionSurfaceRegistry::surface($surface)['owner'] ?? null) ?? '';

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
