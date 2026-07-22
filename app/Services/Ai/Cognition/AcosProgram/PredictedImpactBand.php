<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\AcosProgram;

use App\Services\Ai\Support\AiValueNormalizer;

final class PredictedImpactBand
{
    public const SCHEMA_VERSION = 'atlas.originator.predicted_impact_band.v1';

    /** @var array<string,int> */
    public const RUNG_WEIGHT = [self::FIELD_TASK => 0, self::FIELD_SLICE => 1, self::FIELD_OBRA => 2, self::FIELD_SALTO => 3];

    /** @var list<string> */
    public const BANDS = ['low', 'sweet', 'high'];

    public const DEFAULT_RANK_FALLBACK = 99;

    public const RANK_TOP_CUTOFF = 3;

    public const YIELD_SWEET_FLOOR = 0.5;

    public const HIGH_SCORE_FLOOR = 4;

    public const SWEET_SCORE_FLOOR = 2;
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_SOURCE = 'source';
    public const FIELD_TASK = 'task';
    public const FIELD_SLICE = 'slice';
    public const FIELD_OBRA = 'obra';
    public const FIELD_SALTO = 'salto';
    public const FIELD_BAND = 'band';
    public const FIELD_COMPONENTS = 'components';
    public const FIELD_RUNG = 'rung';
    public const FIELD_RANK = 'rank';
    public const FIELD_PATH_YIELD = 'path_yield';
    public const FIELD_N_REALIZED = 'n_realized';
    public const FIELD_REALIZED_TRUE = 'realized_true';
    public const FIELD_UNRESOLVED = 'unresolved';
    public const FIELD_BANDS = 'bands';
    public const FIELD_CALLER_DECLARED_BAND_IGNORED = 'caller_declared_band_ignored';
    public const FIELD_INFLUENCES_PICK = 'influences_pick';
    public const FIELD_REALIZED = 'realized';
    public const FIELD_SINGLE_SCALAR_SCORE_EMITTED = 'single_scalar_score_emitted';
    public const FIELD_UNRESOLVED_COUNTS_AS_SUCCESS = 'unresolved_counts_as_success';
    public const FIELD_STATUS = 'status';
    public const FIELD_REPORT_ONLY = 'report_only';
    public const FIELD_LOW = 'low';
    public const FIELD_HIGH = 'high';
    public const FIELD_SWEET = 'sweet';

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public static function classify(array $candidate): array
    {
        $rung = AiValueNormalizer::lowerTrimmedString($candidate[self::FIELD_RUNG] ?? self::FIELD_TASK);
        $rank = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($candidate[self::FIELD_RANK] ?? null) ?? self::DEFAULT_RANK_FALLBACK));
        $yield = AiValueNormalizer::clampUnit(AiValueNormalizer::finiteFloatOrNull($candidate[self::FIELD_PATH_YIELD] ?? null) ?? 0.0);
        $score = (self::RUNG_WEIGHT[$rung] ?? 0) + ($rank <= self::RANK_TOP_CUTOFF ? 1 : 0) + ($yield >= self::YIELD_SWEET_FLOOR ? 1 : 0);
        $band = match (true) {
            $score >= self::HIGH_SCORE_FLOOR => self::FIELD_HIGH,
            $score >= self::SWEET_SCORE_FLOOR => self::FIELD_SWEET,
            default => self::FIELD_LOW,
        };

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_BAND => $band,
            self::FIELD_COMPONENTS => [self::FIELD_RUNG => $rung, self::FIELD_RANK => $rank, self::FIELD_PATH_YIELD => $yield],
            self::FIELD_SOURCE => [
                self::FIELD_CALLER_DECLARED_BAND_IGNORED => true,
                self::FIELD_INFLUENCES_PICK => false,
                self::FIELD_SINGLE_SCALAR_SCORE_EMITTED => false,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    public static function calibration(array $rows): array
    {
        $bands = [];
        foreach (self::BANDS as $band) {
            $bands[$band] = [self::FIELD_N_REALIZED => 0, self::FIELD_REALIZED_TRUE => 0, self::FIELD_UNRESOLVED => 0];
        }

        foreach ($rows as $row) {
            $band = AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_BAND] ?? null) ?? self::FIELD_LOW;
            if (! isset($bands[$band])) {
                continue;
            }
            if (AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_STATUS] ?? null) === self::FIELD_UNRESOLVED) {
                $bands[$band][self::FIELD_UNRESOLVED]++;
                continue;
            }
            $bands[$band][self::FIELD_N_REALIZED]++;
            if (($row[self::FIELD_REALIZED] ?? false) === true) {
                $bands[$band][self::FIELD_REALIZED_TRUE]++;
            }
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_BANDS => $bands,
            self::FIELD_SOURCE => [
                self::FIELD_UNRESOLVED_COUNTS_AS_SUCCESS => false,
                self::FIELD_REPORT_ONLY => true,
            ],
        ];
    }
}
