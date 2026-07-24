<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\AcosProgram;

use App\Services\Ai\Support\AiValueNormalizer;
use App\Support\UtcIsoTimestamp;

/**
 * MULTN17-06 — persistent vision theses derived from evidence (originate against the thesis).
 *
 * Derives ≤3 falsifiable theses from aggregated evidence (N17-03 leads + series windows +
 * N17-04 calibration curve). Claims cite series/outcomes/file:line only — never operator or
 * free-form doc prose. Active theses reorder candidates in produce() as WEIGHT, never veto.
 */
final class EvidenceVisionThesisComposer
{
    public const FIELD_OPERATOR_FORBIDDEN_STRINGS = 'operator_forbidden_strings';
    public const FIELD_OUTCOME_ID = 'outcome_id';
    public const SCHEMA_VERSION = 'atlas.originator.evidence_vision_thesis.v1';

    public const MAX_THESES = 3;

    public const MIN_REGRESSION_WINDOWS = 4;

    public const DEFAULT_TTL_DAYS = 30;

    public const DEFAULT_AUTHOR_ENGINE_ID = 'cursor-acos-max-multn1706';


    public const FIELD_SOURCE = 'source';

    public const FIELD_STATUS = 'status';

    public const FIELD_EVIDENCE = 'evidence';

    public const FIELD_CLAIM = 'claim';

    public const FIELD_DEATH_CRITERION = 'death_criterion';

    public const FIELD_BORN_AT = 'born_at';

    public const FIELD_TTL_DAYS = 'ttl_days';

    public const FIELD_DESCRIBED_AT_BIRTH = 'described_at_birth';

    public const FIELD_WINDOW = 'window';

    public const FIELD_FIELD = 'field';

    public const FIELD_VALUE = 'value';

    public const FIELD_ALIGNMENT_KEYS = 'alignment_keys';

    /** @var list<string> */
    public const ALLOWED_EVIDENCE_SOURCES = ['series', 'ledger', 'outcome'];

    public const STATUS_OK = 'ok';

    public const STATUS_ACTIVE = 'active';

    public const KIND_SERIES_RECOVERY = 'series_recovery';

    public const KIND_CALIBRATION_RESOLVED = 'calibration_resolved';

    public const KIND_LEAD_CLUSTER_CLEARED = 'lead_cluster_cleared';

    public const KIND_OUTCOME_PROVEN = 'outcome_proven';

    public const FIELD_ENABLED = 'enabled';

    public const STATUS_FLAG_DISABLED = 'flag_disabled';

    public const STATUS_INSUFFICIENT_SIGNAL = 'insufficient_signal';

    public const FIELD_PROVEN_REAL = 'proven_real';
    public const FIELD_REF = 'ref';
    public const FIELD_THESIS_ID = 'thesis_id';
    public const FIELD_KIND = 'kind';
    public const FIELD_AUTHOR_ENGINE_ID = 'author_engine_id';
    public const FIELD_EXPIRES_AT = 'expires_at';
    public const FIELD_FILE = 'file';
    public const FIELD_LINE = 'line';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_COMPOSED = 'composed';
    public const FIELD_THESIS_COUNT = 'thesis_count';
    public const FIELD_THESES = 'theses';
    public const FIELD_MAX_THESES = 'max_theses';
    public const FIELD_HUMAN_AUTHORED_CLAIMS = 'human_authored_claims';
    public const FIELD_INFLUENCES_PICK = 'influences_pick';
    public const FIELD_INFLUENCES_PICK_MODE = 'influences_pick_mode';
    public const FIELD_PROVIDER_CALLS_MADE = 'provider_calls_made';
    public const FIELD_THRESHOLD = 'threshold';
    public const FIELD_SERIES = 'series';
    public const FIELD_STAGE = 'stage';
    public const FIELD_YIELD = 'yield';
    public const FIELD_N_REALIZED = 'n_realized';
    public const FIELD_REALIZED_TRUE = 'realized_true';
    public const FIELD_CONSECUTIVE_WINDOWS = 'consecutive_windows';
    public const FIELD_BANDS = 'bands';
    public const FIELD_CALIBRATION = 'calibration';
    public const FIELD_FORBIDDEN_STRINGS = 'forbidden_strings';
    public const FIELD_HIGH = 'high';
    public const FIELD_SERIES_WINDOWS = 'series_windows';
    public const FIELD_LEADS = 'leads';
    public const FIELD_REMAINING_ROWS_MAX = 'remaining_rows_max';
    public const FIELD_OUTCOMES = 'outcomes';
    public const FIELD_PATH = 'path';
    public const FIELD_SWEET = 'sweet';
    public const FIELD_TARGET_PATH = 'target_path';
    public const FIELD_OUTCOME = 'outcome';
    public const FIELD_LEDGER = 'ledger';
    public const FIELD_REALIZED_RATE = 'realized_rate';
    public const FIELD_WEIGHT_ONLY_NEVER_VETO = 'weight_only_never_veto';
    public const FIELD_DEFAULT = 'default';
    public const FIELD_OPEN_EVIDENCE = 'open_evidence';
    public const FIELD_SHA256 = 'sha256';
    public const FIELD_PATTERN_DESIGN = 'pattern-design';
    public const FIELD_COMPREHENSION_DEEPENING = 'comprehension-deepening';
    public const FIELD_FRONTIER_HARVEST = 'frontier-harvest';
    public const FIELD_PREDICTED_IMPACT = 'predicted-impact';
    public const FIELD_ARCHIVE_WHEN_HIGH_BAND_REALIZED_RATE____SWEET_BAND___0_15 = 'archive when high_band realized_rate >= sweet_band - 0.15';
    public const FIELD_ARCHIVE_WHEN_OPEN_EVIDENCE_ROWS_FOR_ = 'archive when open evidence rows for ';
    public const FIELD_ARCHIVE_WHEN_PATH_ = 'archive when path ';
    public const FIELD_LEDGER_CLUSTER_AT_ = 'ledger cluster at ';
    public const FIELD_OUTCOME_PREDICTED_IMPACT_CALIBRATION_HIGH_BAND_REALIZED_RATE_ = 'outcome:predicted_impact_calibration high_band realized_rate ';
    public const INT_3 = 3;
    public const INT_2 = 2;

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function compose(array $context = []): array
    {
        if (($context[self::FIELD_ENABLED] ?? false) !== true) {
            return self::emptyResult(self::STATUS_FLAG_DISABLED);
        }

        $bornAt = AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_BORN_AT] ?? null) ?? UtcIsoTimestamp::now();
        $ttlDays = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($context[self::FIELD_TTL_DAYS] ?? null) ?? self::DEFAULT_TTL_DAYS));
        $forbidden = self::forbiddenStrings($context);
        $theses = [];

        foreach (self::seriesRegressionTheses(AiValueNormalizer::arrayOrEmpty($context[self::FIELD_SERIES_WINDOWS] ?? null), $bornAt, $ttlDays) as $thesis) {
            if (count($theses) >= self::MAX_THESES) {
                break;
            }
            if (self::thesisPassesOperatorFence($thesis, $forbidden)) {
                $theses[] = $thesis;
            }
        }

        foreach (self::calibrationDriftTheses(AiValueNormalizer::arrayOrEmpty($context[self::FIELD_CALIBRATION] ?? null), $bornAt, $ttlDays) as $thesis) {
            if (count($theses) >= self::MAX_THESES) {
                break;
            }
            if (self::thesisPassesOperatorFence($thesis, $forbidden)) {
                $theses[] = $thesis;
            }
        }

        foreach (self::leadClusterTheses(AiValueNormalizer::arrayOrEmpty($context[self::FIELD_LEADS] ?? null), $bornAt, $ttlDays) as $thesis) {
            if (count($theses) >= self::MAX_THESES) {
                break;
            }
            if (self::thesisPassesOperatorFence($thesis, $forbidden)) {
                $theses[] = $thesis;
            }
        }

        foreach (self::outcomeStallTheses(AiValueNormalizer::arrayOrEmpty($context[self::FIELD_OUTCOMES] ?? null), $bornAt, $ttlDays) as $thesis) {
            if (count($theses) >= self::MAX_THESES) {
                break;
            }
            if (self::thesisPassesOperatorFence($thesis, $forbidden)) {
                $theses[] = $thesis;
            }
        }

        if ($theses === []) {
            return self::emptyResult(self::STATUS_INSUFFICIENT_SIGNAL);
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_COMPOSED => true,
            self::FIELD_THESIS_COUNT => count($theses),
            self::FIELD_THESES => $theses,
            self::FIELD_STATUS => self::STATUS_OK,
            self::FIELD_SOURCE => [
                self::FIELD_MAX_THESES => self::MAX_THESES,
                self::FIELD_HUMAN_AUTHORED_CLAIMS => false,
                self::FIELD_INFLUENCES_PICK => true,
                self::FIELD_INFLUENCES_PICK_MODE => self::FIELD_WEIGHT_ONLY_NEVER_VETO,
                self::FIELD_PROVIDER_CALLS_MADE => false,
            ],
        ];
    }

    /**
     * Pétreo gate: every enumerated field must use allowed sources and must not contain operator text.
     *
     * @param  array<string,mixed>  $thesis
     * @param  list<string>  $forbidden
     */
    public static function thesisPassesOperatorFence(array $thesis, array $forbidden = []): bool
    {
        if (! self::thesisFieldSourcesValid($thesis)) {
            return false;
        }

        $haystack = AiValueNormalizer::lowerTrimmedString(json_encode([
            $thesis[self::FIELD_CLAIM] ?? '',
            $thesis[self::FIELD_DEATH_CRITERION][self::FIELD_DESCRIBED_AT_BIRTH] ?? '',
            $thesis[self::FIELD_EVIDENCE] ?? [],
        ], JSON_UNESCAPED_SLASHES) ?: '');

        foreach ($forbidden as $needle) {
            $needle = AiValueNormalizer::lowerTrimmedString($needle);
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $thesis
     */
    public static function thesisFieldSourcesValid(array $thesis): bool
    {
        foreach (AiValueNormalizer::arrayOrEmpty($thesis[self::FIELD_EVIDENCE] ?? null) as $row) {
            if (! is_array($row)) {
                return false;
            }
            $source = AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_SOURCE] ?? null) ?? '';
            if (! in_array($source, self::ALLOWED_EVIDENCE_SOURCES, true)) {
                return false;
            }
            if ((AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_REF] ?? null) ?? '') === '') {
                return false;
            }
        }

        return ($thesis[self::FIELD_CLAIM] ?? '') !== '' && is_array($thesis[self::FIELD_DEATH_CRITERION] ?? null);
    }

    /**
     * Pure reorder: thesis-aligned candidates rise; DEMOTE never exclude (MAXN-06 delta pattern).
     *
     * @param  list<array{0:string,1:string,2?:string|null}>  $valid
     * @param  list<array<string,mixed>>  $activeTheses
     * @return list<array{0:string,1:string,2?:string|null}>
     */
    public static function thesisAwareReorder(array $valid, array $activeTheses, bool $enabled): array
    {
        if ($valid === [] || ! $enabled || $activeTheses === []) {
            return $valid;
        }

        $keys = self::alignmentKeys($activeTheses);
        if ($keys === []) {
            return $valid;
        }

        $aligned = [];
        $neutral = [];
        foreach ($valid as $pair) {
            if (self::candidateAligns($pair, $keys)) {
                $aligned[] = $pair;
            } else {
                $neutral[] = $pair;
            }
        }

        return array_merge($aligned, $neutral);
    }

    /**
     * @param  list<array<string,mixed>>  $windows
     * @return list<array<string,mixed>>
     */
    private static function seriesRegressionTheses(array $windows, string $bornAt, int $ttlDays): array
    {
        $groups = [];
        foreach ($windows as $window) {
            if (! is_array($window)) {
                continue;
            }
            $series = AiValueNormalizer::trimmedStringOrNull($window[self::FIELD_SERIES] ?? null) ?? '';
            $stage = AiValueNormalizer::trimmedStringOrNull($window[self::FIELD_STAGE] ?? null) ?? self::FIELD_DEFAULT;
            if ($series === '') {
                continue;
            }
            $groups[$series.'|'.$stage][] = $window;
        }

        $theses = [];
        foreach ($groups as $key => $group) {
            if (count($group) < self::MIN_REGRESSION_WINDOWS) {
                continue;
            }
            usort($group, static fn (array $a, array $b): int => ((int) (AiValueNormalizer::finiteFloatOrNull($a[self::FIELD_WINDOW] ?? null) ?? 0)) <=> ((int) (AiValueNormalizer::finiteFloatOrNull($b[self::FIELD_WINDOW] ?? null) ?? 0)));
            $tail = array_slice($group, -self::MIN_REGRESSION_WINDOWS);
            $yields = array_map(
                static fn (array $row): float => AiValueNormalizer::finiteFloatOrNull($row[self::FIELD_YIELD] ?? null) ?? 0.0,
                $tail,
            );
            $regressing = true;
            for ($i = 1; $i < count($yields); $i++) {
                if ($yields[$i] >= $yields[$i - 1]) {
                    $regressing = false;
                    break;
                }
            }
            if (! $regressing) {
                continue;
            }

            [$series, $stage] = explode('|', $key, 2);
            $first = $yields[0];
            $last = $yields[count($yields) - 1];
            $thesisId = hash(self::FIELD_SHA256, 'series-regression|'.$series.'|'.$stage.'|'.$first.'|'.$last);
            $recoveryFloor = $yields[count($yields) - 2];

            $theses[] = [
                self::FIELD_THESIS_ID => $thesisId,
                self::FIELD_STATUS => self::STATUS_ACTIVE,
                self::FIELD_CLAIM => 'series:'.$series.' stage '.$stage.' yield regressed across windows '
                    .((int) (AiValueNormalizer::finiteFloatOrNull($tail[0][self::FIELD_WINDOW] ?? null) ?? 0)).'-'.((int) (AiValueNormalizer::finiteFloatOrNull($tail[count($tail) - 1][self::FIELD_WINDOW] ?? null) ?? 0))
                    .' ('.round($first, 3).'→'.round($last, 3).')',
                self::FIELD_EVIDENCE => array_map(
                    static fn (array $row): array => [
                        self::FIELD_SOURCE => self::FIELD_SERIES,
                        self::FIELD_REF => 'series:'.(AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_SERIES] ?? null) ?? '').':stage='.(AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_STAGE] ?? null) ?? '').':window='.(int) (AiValueNormalizer::finiteFloatOrNull($row[self::FIELD_WINDOW] ?? null) ?? 0),
                        self::FIELD_FIELD => self::FIELD_YIELD,
                        self::FIELD_VALUE => AiValueNormalizer::finiteFloatOrNull($row[self::FIELD_YIELD] ?? null) ?? 0.0,
                    ],
                    $tail,
                ),
                self::FIELD_DEATH_CRITERION => [
                    self::FIELD_KIND => self::KIND_SERIES_RECOVERY,
                    self::FIELD_THRESHOLD => $recoveryFloor,
                    self::FIELD_CONSECUTIVE_WINDOWS => self::INT_2,
                    self::FIELD_DESCRIBED_AT_BIRTH => 'archive when series:'.$series.' stage '.$stage.' yield >= '.$recoveryFloor.' for 2 consecutive windows',
                ],
                self::FIELD_ALIGNMENT_KEYS => [$series, $stage, self::FIELD_PATTERN_DESIGN, self::FIELD_FRONTIER_HARVEST],
                self::FIELD_BORN_AT => $bornAt,
                self::FIELD_TTL_DAYS => $ttlDays,
                self::FIELD_EXPIRES_AT => self::expiresAt($bornAt, $ttlDays),
                self::FIELD_AUTHOR_ENGINE_ID => self::DEFAULT_AUTHOR_ENGINE_ID,
            ];
        }

        return $theses;
    }

    /**
     * @param  array<string,mixed>  $calibration
     * @return list<array<string,mixed>>
     */
    private static function calibrationDriftTheses(array $calibration, string $bornAt, int $ttlDays): array
    {
        $bands = AiValueNormalizer::arrayOrEmpty($calibration[self::FIELD_BANDS] ?? null);
        $high = AiValueNormalizer::arrayOrEmpty($bands[self::FIELD_HIGH] ?? null);
        $sweet = AiValueNormalizer::arrayOrEmpty($bands[self::FIELD_SWEET] ?? null);
        $highN = (int) (AiValueNormalizer::finiteFloatOrNull($high[self::FIELD_N_REALIZED] ?? null) ?? 0);
        $sweetN = (int) (AiValueNormalizer::finiteFloatOrNull($sweet[self::FIELD_N_REALIZED] ?? null) ?? 0);
        if ($highN < self::INT_3 || $sweetN < self::INT_3) {
            return [];
        }

        $highRate = ((int) (AiValueNormalizer::finiteFloatOrNull($high[self::FIELD_REALIZED_TRUE] ?? null) ?? 0)) / max(1, $highN);
        $sweetRate = ((int) (AiValueNormalizer::finiteFloatOrNull($sweet[self::FIELD_REALIZED_TRUE] ?? null) ?? 0)) / max(1, $sweetN);
        if ($highRate >= $sweetRate - 0.15) {
            return [];
        }

        $thesisId = hash(self::FIELD_SHA256, 'calibration-drift|'.$highRate.'|'.$sweetRate);

        return [[
            self::FIELD_THESIS_ID => $thesisId,
            self::FIELD_STATUS => self::STATUS_ACTIVE,
            self::FIELD_CLAIM => self::FIELD_OUTCOME_PREDICTED_IMPACT_CALIBRATION_HIGH_BAND_REALIZED_RATE_.round($highRate, 3)
                .' trails sweet_band '.round($sweetRate, 3),
            self::FIELD_EVIDENCE => [
                [
                    self::FIELD_SOURCE => self::FIELD_OUTCOME,
                    self::FIELD_REF => 'outcome:predicted_impact_calibration:band=high:n_realized='.$highN,
                    self::FIELD_FIELD => self::FIELD_REALIZED_RATE,
                    self::FIELD_VALUE => $highRate,
                ],
                [
                    self::FIELD_SOURCE => self::FIELD_OUTCOME,
                    self::FIELD_REF => 'outcome:predicted_impact_calibration:band=sweet:n_realized='.$sweetN,
                    self::FIELD_FIELD => self::FIELD_REALIZED_RATE,
                    self::FIELD_VALUE => $sweetRate,
                ],
            ],
            self::FIELD_DEATH_CRITERION => [
                self::FIELD_KIND => self::KIND_CALIBRATION_RESOLVED,
                self::FIELD_THRESHOLD => $sweetRate - 0.15,
                self::FIELD_DESCRIBED_AT_BIRTH => self::FIELD_ARCHIVE_WHEN_HIGH_BAND_REALIZED_RATE____SWEET_BAND___0_15,
            ],
            self::FIELD_ALIGNMENT_KEYS => [self::FIELD_PREDICTED_IMPACT, self::FIELD_PATTERN_DESIGN, self::FIELD_COMPREHENSION_DEEPENING],
            self::FIELD_BORN_AT => $bornAt,
            self::FIELD_TTL_DAYS => $ttlDays,
            self::FIELD_EXPIRES_AT => self::expiresAt($bornAt, $ttlDays),
            self::FIELD_AUTHOR_ENGINE_ID => self::DEFAULT_AUTHOR_ENGINE_ID,
        ]];
    }

    /**
     * @param  list<array<string,mixed>>  $leads
     * @return list<array<string,mixed>>
     */
    private static function leadClusterTheses(array $leads, string $bornAt, int $ttlDays): array
    {
        $byTarget = [];
        foreach ($leads as $lead) {
            if (! is_array($lead)) {
                continue;
            }
            $target = ltrim(AiValueNormalizer::trimmedStringOrNull($lead[self::FIELD_TARGET_PATH] ?? null) ?? '', '/');
            $evidence = AiValueNormalizer::arrayOrEmpty($lead[self::FIELD_EVIDENCE] ?? null);
            $file = AiValueNormalizer::trimmedStringOrNull($evidence[self::FIELD_FILE] ?? null) ?? '';
            $line = (int) (AiValueNormalizer::finiteFloatOrNull($evidence[self::FIELD_LINE] ?? null) ?? 0);
            if ($target === '' || $file === '' || $line <= 0) {
                continue;
            }
            $byTarget[$target][] = [self::FIELD_FILE => $file, self::FIELD_LINE => $line, self::FIELD_SOURCE => AiValueNormalizer::trimmedStringOrNull($evidence[self::FIELD_SOURCE] ?? null) ?? self::FIELD_LEDGER];
        }

        $theses = [];
        foreach ($byTarget as $target => $rows) {
            if (count($rows) < self::INT_2) {
                continue;
            }
            $thesisId = hash(self::FIELD_SHA256, 'lead-cluster|'.$target.'|'.count($rows));
            $refs = array_map(
                static fn (array $row): array => [
                    self::FIELD_SOURCE => self::FIELD_LEDGER,
                    self::FIELD_REF => 'ledger:'.$row[self::FIELD_FILE].':'.$row[self::FIELD_LINE],
                    self::FIELD_FIELD => self::FIELD_OPEN_EVIDENCE,
                    self::FIELD_VALUE => $row[self::FIELD_SOURCE],
                ],
                $rows,
            );

            $theses[] = [
                self::FIELD_THESIS_ID => $thesisId,
                self::FIELD_STATUS => self::STATUS_ACTIVE,
                self::FIELD_CLAIM => self::FIELD_LEDGER_CLUSTER_AT_.$target.' with '.count($rows).' independent evidence rows',
                self::FIELD_EVIDENCE => $refs,
                self::FIELD_DEATH_CRITERION => [
                    self::FIELD_KIND => self::KIND_LEAD_CLUSTER_CLEARED,
                    self::FIELD_REMAINING_ROWS_MAX => 0,
                    self::FIELD_DESCRIBED_AT_BIRTH => self::FIELD_ARCHIVE_WHEN_OPEN_EVIDENCE_ROWS_FOR_.$target.' drop below 2',
                ],
                self::FIELD_ALIGNMENT_KEYS => [$target, dirname($target)],
                self::FIELD_BORN_AT => $bornAt,
                self::FIELD_TTL_DAYS => $ttlDays,
                self::FIELD_EXPIRES_AT => self::expiresAt($bornAt, $ttlDays),
                self::FIELD_AUTHOR_ENGINE_ID => self::DEFAULT_AUTHOR_ENGINE_ID,
            ];
        }

        return $theses;
    }

    /**
     * @param  list<array<string,mixed>>  $outcomes
     * @return list<array<string,mixed>>
     */
    private static function outcomeStallTheses(array $outcomes, string $bornAt, int $ttlDays): array
    {
        $byPath = [];
        foreach ($outcomes as $row) {
            if (! is_array($row)) {
                continue;
            }
            $path = AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_PATH] ?? null) ?? '';
            if ($path === '') {
                continue;
            }
            $byPath[$path][] = $row;
        }

        $theses = [];
        foreach ($byPath as $path => $rows) {
            $failures = array_values(array_filter($rows, static fn (array $row): bool => ($row[self::FIELD_PROVEN_REAL] ?? null) === false));
            if (count($failures) < self::INT_3) {
                continue;
            }
            $thesisId = hash(self::FIELD_SHA256, 'outcome-stall|'.$path.'|'.count($failures));
            $theses[] = [
                self::FIELD_THESIS_ID => $thesisId,
                self::FIELD_STATUS => self::STATUS_ACTIVE,
                self::FIELD_CLAIM => 'outcome:path='.$path.' has '.count($failures).' consecutive non-proven_real results',
                self::FIELD_EVIDENCE => array_map(
                    static fn (array $row, int $index): array => [
                        self::FIELD_SOURCE => self::FIELD_OUTCOME,
                        self::FIELD_REF => 'outcome:'.((AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_OUTCOME_ID] ?? null) ?? '') ?: ('stall-'.$index)),
                        self::FIELD_FIELD => self::FIELD_PROVEN_REAL,
                        self::FIELD_VALUE => false,
                    ],
                    array_slice($failures, 0, 3),
                    array_keys(array_slice($failures, 0, 3)),
                ),
                self::FIELD_DEATH_CRITERION => [
                    self::FIELD_KIND => self::KIND_OUTCOME_PROVEN,
                    self::FIELD_DESCRIBED_AT_BIRTH => self::FIELD_ARCHIVE_WHEN_PATH_.$path.' records proven_real=true',
                ],
                self::FIELD_ALIGNMENT_KEYS => [$path],
                self::FIELD_BORN_AT => $bornAt,
                self::FIELD_TTL_DAYS => $ttlDays,
                self::FIELD_EXPIRES_AT => self::expiresAt($bornAt, $ttlDays),
                self::FIELD_AUTHOR_ENGINE_ID => self::DEFAULT_AUTHOR_ENGINE_ID,
            ];
        }

        return $theses;
    }

    /**
     * @param  list<array{0:string,1:string,2?:string|null}>  $pair
     * @param  list<string>  $keys
     */
    private static function candidateAligns(array $pair, array $keys): bool
    {
        $rel = AiValueNormalizer::lowerTrimmedString(ltrim(AiValueNormalizer::trimmedStringOrNull($pair[1] ?? null) ?? '', '/'));
        $yieldPath = AiValueNormalizer::lowerTrimmedString($pair[2] ?? '');
        $objective = AiValueNormalizer::lowerTrimmedString($pair[0] ?? '');

        foreach ($keys as $key) {
            $key = AiValueNormalizer::lowerTrimmedString($key);
            if ($key === '') {
                continue;
            }
            if (
                ($rel !== '' && (str_contains($rel, $key) || str_contains($key, $rel)))
                || ($yieldPath !== '' && str_contains($yieldPath, $key))
                || ($objective !== '' && str_contains($objective, $key))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $activeTheses
     * @return list<string>
     */
    private static function alignmentKeys(array $activeTheses): array
    {
        $keys = [];
        foreach ($activeTheses as $thesis) {
            if (! is_array($thesis) || ($thesis[self::FIELD_STATUS] ?? '') !== self::STATUS_ACTIVE) {
                continue;
            }
            foreach (AiValueNormalizer::arrayOrEmpty($thesis[self::FIELD_ALIGNMENT_KEYS] ?? null) as $key) {
                $key = AiValueNormalizer::trimmedStringOrNull($key) ?? '';
                if ($key !== '') {
                    $keys[] = $key;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<string>
     */
    private static function forbiddenStrings(array $context): array
    {
        $raw = $context[self::FIELD_FORBIDDEN_STRINGS] ?? $context[self::FIELD_OPERATOR_FORBIDDEN_STRINGS] ?? [];

        return array_values(array_filter(array_map(
            static fn ($value): string => AiValueNormalizer::trimmedStringOrNull($value) ?? '',
            AiValueNormalizer::arrayOrEmpty($raw),
        )));
    }

    private static function expiresAt(string $bornAt, int $ttlDays): string
    {
        $ts = strtotime($bornAt);

        return gmdate('c', ($ts !== false ? $ts : time()) + ($ttlDays * 86400));
    }

    /**
     * @return array<string,mixed>
     */
    private static function emptyResult(string $reason): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_COMPOSED => false,
            self::FIELD_THESIS_COUNT => 0,
            self::FIELD_THESES => [],
            self::FIELD_STATUS => $reason,
            self::FIELD_SOURCE => [
                self::FIELD_MAX_THESES => self::MAX_THESES,
                self::FIELD_HUMAN_AUTHORED_CLAIMS => false,
                self::FIELD_INFLUENCES_PICK => false,
                self::FIELD_INFLUENCES_PICK_MODE => self::FIELD_WEIGHT_ONLY_NEVER_VETO,
                self::FIELD_PROVIDER_CALLS_MADE => false,
            ],
        ];
    }
}
