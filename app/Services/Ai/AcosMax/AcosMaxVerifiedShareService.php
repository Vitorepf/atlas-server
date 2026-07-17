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
    private const EXECUTORS = ['dev', 'forge', 'autonomos'];

    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        return [
            'kind' => 'measure_freeze',
            'measure_id' => self::MEASURE_ID,
            'formula' => 'verified_share = enforce-mode verification receipts ÷ OUTC-01 outcome receipts, grouped by executor',
            'formula_version' => self::FORMULA_VERSION,
            'thresholds' => [
                'verified_share_min' => 0.80,
                'window_days_min' => 14,
                'denominator_min_executions' => 50,
            ],
            'denominator_min' => 50,
            'ttl_days' => 30,
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
                'status' => 'missing_freeze',
                'reason' => 'measure_freeze_not_recorded',
                'freeze_required' => self::freezePayload(),
            ];
        }

        $windowDays = max(1, (int) ($days ?? data_get($freeze, 'thresholds.window_days_min', 14)));
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
        $denominatorMin = max(1, (int) ($freeze['denominator_min'] ?? data_get($freeze, 'thresholds.denominator_min_executions', 50)));
        $shareMin = (float) data_get($freeze, 'thresholds.verified_share_min', 0.80);
        $authorEngineId = AiValueNormalizer::trimmedString($freeze['author_engine_id'] ?? '');
        $judgeEngineId = AiValueNormalizer::trimmedString($freeze['judge_engine_id'] ?? '');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'status' => $this->status($aggregate, $denominatorMin, $shareMin),
            'window_days' => $windowDays,
            'freeze' => [
                'measure_id' => AiValueNormalizer::trimmedString($freeze['measure_id'] ?? self::MEASURE_ID) ?: self::MEASURE_ID,
                'content_hash' => AiValueNormalizer::trimmedString($freeze['content_hash'] ?? ''),
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
            return 'insufficient_signal';
        }

        return (AiValueNormalizer::finiteFloatOrNull($aggregate['verified_share'] ?? null) ?? 0.0) >= $shareMin ? 'ok' : 'below_threshold';
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
                    $row = json_decode(AiValueNormalizer::trimmedString($line), true, flags: JSON_THROW_ON_ERROR);
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
        $author = AiValueNormalizer::trimmedString($row['author_engine_id'] ?? '');
        $judge = AiValueNormalizer::trimmedString($row['judge_engine_id'] ?? '');

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
                    $row = json_decode(AiValueNormalizer::trimmedString($line), true, flags: JSON_THROW_ON_ERROR);
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

        return $this->normalizeExecutor(AiValueNormalizer::trimmedString($row['task_category'] ?? $row['role'] ?? ''));
    }

    /** @param array<string,mixed> $row */
    private function executorFromCoverage(array $row): ?string
    {
        $surface = AiValueNormalizer::trimmedString($row['surface'] ?? '');
        $owner = AiValueNormalizer::trimmedString(EngineeringExecutionSurfaceRegistry::surface($surface)['owner'] ?? '');

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
