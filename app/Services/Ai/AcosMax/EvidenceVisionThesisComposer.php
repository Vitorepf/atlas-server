<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * MULTN17-06 — persistent vision theses derived from evidence (originate against the thesis).
 *
 * Derives ≤3 falsifiable theses from aggregated evidence (N17-03 leads + series windows +
 * N17-04 calibration curve). Claims cite series/outcomes/file:line only — never operator or
 * free-form doc prose. Active theses reorder candidates in produce() as WEIGHT, never veto.
 */
final class EvidenceVisionThesisComposer
{
    public const SCHEMA_VERSION = 'atlas.originator.evidence_vision_thesis.v1';

    public const MAX_THESES = 3;

    public const MIN_REGRESSION_WINDOWS = 4;

    public const DEFAULT_TTL_DAYS = 30;

    public const DEFAULT_AUTHOR_ENGINE_ID = 'cursor-acos-max-multn1706';

    /** @var list<string> */
    public const ALLOWED_EVIDENCE_SOURCES = ['series', 'ledger', 'outcome'];

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function compose(array $context = []): array
    {
        if (($context['enabled'] ?? false) !== true) {
            return self::emptyResult('flag_disabled');
        }

        $bornAt = AiValueNormalizer::trimmedString($context['born_at'] ?? gmdate('c')) ?: gmdate('c');
        $ttlDays = max(1, (int) ($context['ttl_days'] ?? self::DEFAULT_TTL_DAYS));
        $forbidden = self::forbiddenStrings($context);
        $theses = [];

        foreach (self::seriesRegressionTheses(AiValueNormalizer::arrayOrEmpty($context['series_windows'] ?? null), $bornAt, $ttlDays) as $thesis) {
            if (count($theses) >= self::MAX_THESES) {
                break;
            }
            if (self::thesisPassesOperatorFence($thesis, $forbidden)) {
                $theses[] = $thesis;
            }
        }

        foreach (self::calibrationDriftTheses(AiValueNormalizer::arrayOrEmpty($context['calibration'] ?? null), $bornAt, $ttlDays) as $thesis) {
            if (count($theses) >= self::MAX_THESES) {
                break;
            }
            if (self::thesisPassesOperatorFence($thesis, $forbidden)) {
                $theses[] = $thesis;
            }
        }

        foreach (self::leadClusterTheses(AiValueNormalizer::arrayOrEmpty($context['leads'] ?? null), $bornAt, $ttlDays) as $thesis) {
            if (count($theses) >= self::MAX_THESES) {
                break;
            }
            if (self::thesisPassesOperatorFence($thesis, $forbidden)) {
                $theses[] = $thesis;
            }
        }

        foreach (self::outcomeStallTheses(AiValueNormalizer::arrayOrEmpty($context['outcomes'] ?? null), $bornAt, $ttlDays) as $thesis) {
            if (count($theses) >= self::MAX_THESES) {
                break;
            }
            if (self::thesisPassesOperatorFence($thesis, $forbidden)) {
                $theses[] = $thesis;
            }
        }

        if ($theses === []) {
            return self::emptyResult('insufficient_signal');
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'composed' => true,
            'thesis_count' => count($theses),
            'theses' => $theses,
            'status' => 'ok',
            'source' => [
                'max_theses' => self::MAX_THESES,
                'human_authored_claims' => false,
                'influences_pick' => true,
                'influences_pick_mode' => 'weight_only_never_veto',
                'provider_calls_made' => false,
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
            $thesis['claim'] ?? '',
            $thesis['death_criterion']['described_at_birth'] ?? '',
            $thesis['evidence'] ?? [],
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
        foreach (AiValueNormalizer::arrayOrEmpty($thesis['evidence'] ?? null) as $row) {
            if (! is_array($row)) {
                return false;
            }
            $source = AiValueNormalizer::trimmedString($row['source'] ?? '');
            if (! in_array($source, self::ALLOWED_EVIDENCE_SOURCES, true)) {
                return false;
            }
            if (AiValueNormalizer::trimmedString($row['ref'] ?? '') === '') {
                return false;
            }
        }

        return ($thesis['claim'] ?? '') !== '' && is_array($thesis['death_criterion'] ?? null);
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
            $series = AiValueNormalizer::trimmedString($window['series'] ?? '');
            $stage = AiValueNormalizer::trimmedString($window['stage'] ?? 'default');
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
            usort($group, static fn (array $a, array $b): int => ((int) ($a['window'] ?? 0)) <=> ((int) ($b['window'] ?? 0)));
            $tail = array_slice($group, -self::MIN_REGRESSION_WINDOWS);
            $yields = array_map(
                static fn (array $row): float => AiValueNormalizer::finiteFloatOrNull($row['yield'] ?? null) ?? 0.0,
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
            $thesisId = hash('sha256', 'series-regression|'.$series.'|'.$stage.'|'.$first.'|'.$last);
            $recoveryFloor = $yields[count($yields) - 2];

            $theses[] = [
                'thesis_id' => $thesisId,
                'status' => 'active',
                'claim' => 'series:'.$series.' stage '.$stage.' yield regressed across windows '
                    .((int) ($tail[0]['window'] ?? 0)).'-'.((int) ($tail[count($tail) - 1]['window'] ?? 0))
                    .' ('.round($first, 3).'→'.round($last, 3).')',
                'evidence' => array_map(
                    static fn (array $row): array => [
                        'source' => 'series',
                        'ref' => 'series:'.AiValueNormalizer::trimmedString($row['series'] ?? '').':stage='.AiValueNormalizer::trimmedString($row['stage'] ?? '').':window='.(int) ($row['window'] ?? 0),
                        'field' => 'yield',
                        'value' => AiValueNormalizer::finiteFloatOrNull($row['yield'] ?? null) ?? 0.0,
                    ],
                    $tail,
                ),
                'death_criterion' => [
                    'kind' => 'series_recovery',
                    'threshold' => $recoveryFloor,
                    'consecutive_windows' => 2,
                    'described_at_birth' => 'archive when series:'.$series.' stage '.$stage.' yield >= '.$recoveryFloor.' for 2 consecutive windows',
                ],
                'alignment_keys' => [$series, $stage, 'pattern-design', 'frontier-harvest'],
                'born_at' => $bornAt,
                'ttl_days' => $ttlDays,
                'expires_at' => self::expiresAt($bornAt, $ttlDays),
                'author_engine_id' => self::DEFAULT_AUTHOR_ENGINE_ID,
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
        $bands = AiValueNormalizer::arrayOrEmpty($calibration['bands'] ?? null);
        $high = AiValueNormalizer::arrayOrEmpty($bands['high'] ?? null);
        $sweet = AiValueNormalizer::arrayOrEmpty($bands['sweet'] ?? null);
        $highN = (int) ($high['n_realized'] ?? 0);
        $sweetN = (int) ($sweet['n_realized'] ?? 0);
        if ($highN < 3 || $sweetN < 3) {
            return [];
        }

        $highRate = ((int) ($high['realized_true'] ?? 0)) / max(1, $highN);
        $sweetRate = ((int) ($sweet['realized_true'] ?? 0)) / max(1, $sweetN);
        if ($highRate >= $sweetRate - 0.15) {
            return [];
        }

        $thesisId = hash('sha256', 'calibration-drift|'.$highRate.'|'.$sweetRate);

        return [[
            'thesis_id' => $thesisId,
            'status' => 'active',
            'claim' => 'outcome:predicted_impact_calibration high_band realized_rate '.round($highRate, 3)
                .' trails sweet_band '.round($sweetRate, 3),
            'evidence' => [
                [
                    'source' => 'outcome',
                    'ref' => 'outcome:predicted_impact_calibration:band=high:n_realized='.$highN,
                    'field' => 'realized_rate',
                    'value' => $highRate,
                ],
                [
                    'source' => 'outcome',
                    'ref' => 'outcome:predicted_impact_calibration:band=sweet:n_realized='.$sweetN,
                    'field' => 'realized_rate',
                    'value' => $sweetRate,
                ],
            ],
            'death_criterion' => [
                'kind' => 'calibration_resolved',
                'threshold' => $sweetRate - 0.15,
                'described_at_birth' => 'archive when high_band realized_rate >= sweet_band - 0.15',
            ],
            'alignment_keys' => ['predicted-impact', 'pattern-design', 'comprehension-deepening'],
            'born_at' => $bornAt,
            'ttl_days' => $ttlDays,
            'expires_at' => self::expiresAt($bornAt, $ttlDays),
            'author_engine_id' => self::DEFAULT_AUTHOR_ENGINE_ID,
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
            $target = ltrim(AiValueNormalizer::trimmedString($lead['target_path'] ?? ''), '/');
            $evidence = AiValueNormalizer::arrayOrEmpty($lead['evidence'] ?? null);
            $file = AiValueNormalizer::trimmedString($evidence['file'] ?? '');
            $line = (int) ($evidence['line'] ?? 0);
            if ($target === '' || $file === '' || $line <= 0) {
                continue;
            }
            $byTarget[$target][] = ['file' => $file, 'line' => $line, 'source' => AiValueNormalizer::trimmedString($evidence['source'] ?? 'ledger') ?: 'ledger'];
        }

        $theses = [];
        foreach ($byTarget as $target => $rows) {
            if (count($rows) < 2) {
                continue;
            }
            $thesisId = hash('sha256', 'lead-cluster|'.$target.'|'.count($rows));
            $refs = array_map(
                static fn (array $row): array => [
                    'source' => 'ledger',
                    'ref' => 'ledger:'.$row['file'].':'.$row['line'],
                    'field' => 'open_evidence',
                    'value' => $row['source'],
                ],
                $rows,
            );

            $theses[] = [
                'thesis_id' => $thesisId,
                'status' => 'active',
                'claim' => 'ledger cluster at '.$target.' with '.count($rows).' independent evidence rows',
                'evidence' => $refs,
                'death_criterion' => [
                    'kind' => 'lead_cluster_cleared',
                    'remaining_rows_max' => 0,
                    'described_at_birth' => 'archive when open evidence rows for '.$target.' drop below 2',
                ],
                'alignment_keys' => [$target, dirname($target)],
                'born_at' => $bornAt,
                'ttl_days' => $ttlDays,
                'expires_at' => self::expiresAt($bornAt, $ttlDays),
                'author_engine_id' => self::DEFAULT_AUTHOR_ENGINE_ID,
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
            $path = AiValueNormalizer::trimmedString($row['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $byPath[$path][] = $row;
        }

        $theses = [];
        foreach ($byPath as $path => $rows) {
            $failures = array_values(array_filter($rows, static fn (array $row): bool => ($row['proven_real'] ?? null) === false));
            if (count($failures) < 3) {
                continue;
            }
            $thesisId = hash('sha256', 'outcome-stall|'.$path.'|'.count($failures));
            $theses[] = [
                'thesis_id' => $thesisId,
                'status' => 'active',
                'claim' => 'outcome:path='.$path.' has '.count($failures).' consecutive non-proven_real results',
                'evidence' => array_map(
                    static fn (array $row, int $index): array => [
                        'source' => 'outcome',
                        'ref' => 'outcome:'.(AiValueNormalizer::trimmedString($row['outcome_id'] ?? '') ?: ('stall-'.$index)),
                        'field' => 'proven_real',
                        'value' => false,
                    ],
                    array_slice($failures, 0, 3),
                    array_keys(array_slice($failures, 0, 3)),
                ),
                'death_criterion' => [
                    'kind' => 'outcome_proven',
                    'described_at_birth' => 'archive when path '.$path.' records proven_real=true',
                ],
                'alignment_keys' => [$path],
                'born_at' => $bornAt,
                'ttl_days' => $ttlDays,
                'expires_at' => self::expiresAt($bornAt, $ttlDays),
                'author_engine_id' => self::DEFAULT_AUTHOR_ENGINE_ID,
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
        $rel = AiValueNormalizer::lowerTrimmedString(ltrim(AiValueNormalizer::trimmedString($pair[1] ?? ''), '/'));
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
            if (! is_array($thesis) || ($thesis['status'] ?? '') !== 'active') {
                continue;
            }
            foreach (AiValueNormalizer::arrayOrEmpty($thesis['alignment_keys'] ?? null) as $key) {
                $key = AiValueNormalizer::trimmedString($key);
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
        $raw = $context['forbidden_strings'] ?? $context['operator_forbidden_strings'] ?? [];

        return array_values(array_filter(array_map(
            static fn ($value): string => AiValueNormalizer::trimmedString($value),
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
            'schema_version' => self::SCHEMA_VERSION,
            'composed' => false,
            'thesis_count' => 0,
            'theses' => [],
            'status' => $reason,
            'source' => [
                'max_theses' => self::MAX_THESES,
                'human_authored_claims' => false,
                'influences_pick' => false,
                'influences_pick_mode' => 'weight_only_never_veto',
                'provider_calls_made' => false,
            ],
        ];
    }
}
