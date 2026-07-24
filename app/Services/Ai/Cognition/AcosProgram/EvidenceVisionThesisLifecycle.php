<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\AcosProgram;

use App\Services\Ai\Support\AiValueNormalizer;
use App\Support\UtcIsoTimestamp;

/**
 * MULTN17-06 — vision thesis lifecycle: TTL, death criterion archival, active-set for reorder.
 */
final class EvidenceVisionThesisLifecycle
{
    public const SCHEMA_VERSION = 'atlas.originator.evidence_vision_thesis_lifecycle.v1';

    public const DEFAULT_CONSECUTIVE_WINDOWS = 2;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_REFUSED = 'refused';

    public const REASON_THESIS_NOT_ACTIVE = 'thesis_not_active';

    public const DEATH_REASON_TTL_EXPIRED = 'ttl_expired';
    public const FIELD_STATUS = 'status';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_THESIS_ID = 'thesis_id';
    public const FIELD_ARCHIVE_RECEIPT = 'archive_receipt';
    public const FIELD_REASON = 'reason';
    public const FIELD_CLAIM = 'claim';
    public const FIELD_DEATH_CRITERION = 'death_criterion';
    public const FIELD_RECEIPT_HASH = 'receipt_hash';
    public const FIELD_ALIGNMENT_KEYS = 'alignment_keys';
    public const FIELD_ARCHIVED_AT_BASIS = 'archived_at_basis';
    public const FIELD_BANDS = 'bands';
    public const FIELD_CALIBRATION_RESOLVED = 'calibration_resolved';
    public const FIELD_CONSECUTIVE_WINDOWS = 'consecutive_windows';
    public const FIELD_THRESHOLD = 'threshold';
    public const FIELD_WINDOW = 'window';
    public const FIELD_THESES = 'theses';
    public const FIELD_EXPIRES_AT = 'expires_at';
    public const FIELD_SERIES_RECOVERY = 'series_recovery';
    public const FIELD_OUTCOME_PROVEN = 'outcome_proven';
    public const FIELD_YIELD = 'yield';
    public const FIELD_TARGET_PATH = 'target_path';
    public const FIELD_EVIDENCE = 'evidence';
    public const FIELD_HIGH = 'high';
    public const FIELD_KIND = 'kind';
    public const FIELD_LEAD_CLUSTER_CLEARED = 'lead_cluster_cleared';
    public const FIELD_N_REALIZED = 'n_realized';
    public const FIELD_PATH = 'path';
    public const FIELD_PROVEN_REAL = 'proven_real';
    public const FIELD_REALIZED_TRUE = 'realized_true';
    public const FIELD_REF = 'ref';
    public const FIELD_SERIES = 'series';
    public const FIELD_SOURCE = 'source';
    public const FIELD_STAGE = 'stage';
    public const FIELD_DEFAULT = 'default';
    public const FIELD_SHA256 = 'sha256';
    public const INT_2 = 2;

    /** @var array<string,array<string,mixed>> */
    private static array $active = [];

    /** @var list<array<string,mixed>> */
    private static array $archived = [];

    public static function reset(): void
    {
        self::$active = [];
        self::$archived = [];
    }

    /**
     * @param  array<string,mixed>  $composed  output from {@see EvidenceVisionThesisComposer::compose}
     */
    public static function ingestComposed(array $composed): void
    {
        foreach (AiValueNormalizer::arrayOrEmpty($composed[self::FIELD_THESES] ?? null) as $thesis) {
            if (! is_array($thesis)) {
                continue;
            }
            $thesisId = AiValueNormalizer::trimmedStringOrNull($thesis[self::FIELD_THESIS_ID] ?? null) ?? '';
            if ($thesisId === '' || isset(self::$active[$thesisId])) {
                continue;
            }
            if (count(self::$active) >= EvidenceVisionThesisComposer::MAX_THESES) {
                break;
            }
            self::$active[$thesisId] = array_merge($thesis, [self::FIELD_STATUS => self::STATUS_ACTIVE, self::FIELD_ARCHIVE_RECEIPT => null]);
        }
    }

    /**
     * @param  list<array<string,mixed>>  $seriesWindows
     * @param  list<array<string,mixed>>  $outcomes
     * @param  array<string,mixed>  $calibration
     * @param  list<array<string,mixed>>  $leads
     * @return list<array<string,mixed>>
     */
    public static function evaluateAndArchive(
        array $seriesWindows = [],
        array $outcomes = [],
        array $calibration = [],
        array $leads = [],
        ?string $now = null,
    ): array {
        $nowTs = strtotime($now ?? UtcIsoTimestamp::now()) ?: time();
        $archived = [];

        foreach (self::$active as $thesisId => $thesis) {
            $reason = self::deathReason($thesis, $seriesWindows, $outcomes, $calibration, $leads, $nowTs);
            if ($reason !== null) {
                $archived[] = self::archive(AiValueNormalizer::trimmedStringOrNull($thesisId) ?? '', $reason);
            }
        }

        return $archived;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function activeTheses(): array
    {
        return array_values(array_filter(
            self::$active,
            static fn (array $thesis): bool => ($thesis[self::FIELD_STATUS] ?? '') === self::STATUS_ACTIVE,
        ));
    }

    /**
     * @return array<string,mixed>
     */
    public static function archive(string $thesisId, string $reason): array
    {
        $thesis = self::$active[$thesisId] ?? null;
        if (! is_array($thesis)) {
            return [
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_THESIS_ID => $thesisId,
                self::FIELD_STATUS => self::STATUS_REFUSED,
                self::FIELD_REASON => self::REASON_THESIS_NOT_ACTIVE,
            ];
        }

        $receipt = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_THESIS_ID => $thesisId,
            self::FIELD_ARCHIVED_AT_BASIS => $reason,
            self::FIELD_CLAIM => AiValueNormalizer::trimmedStringOrNull($thesis[self::FIELD_CLAIM] ?? null) ?? '',
            self::FIELD_DEATH_CRITERION => AiValueNormalizer::arrayOrEmpty($thesis[self::FIELD_DEATH_CRITERION] ?? null),
            self::FIELD_RECEIPT_HASH => hash(self::FIELD_SHA256, json_encode([$thesisId, $reason, $thesis[self::FIELD_CLAIM] ?? ''], JSON_UNESCAPED_SLASHES)),
        ];

        $thesis[self::FIELD_STATUS] = self::STATUS_ARCHIVED;
        $thesis[self::FIELD_ARCHIVE_RECEIPT] = $receipt;
        unset(self::$active[$thesisId]);
        self::$archived[] = $thesis;

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_THESIS_ID => $thesisId,
            self::FIELD_STATUS => self::STATUS_ARCHIVED,
            self::FIELD_ARCHIVE_RECEIPT => $receipt,
        ];
    }

    /**
     * @param  array<string,mixed>  $thesis
     * @param  list<array<string,mixed>>  $seriesWindows
     * @param  list<array<string,mixed>>  $outcomes
     * @param  array<string,mixed>  $calibration
     * @param  list<array<string,mixed>>  $leads
     */
    private static function deathReason(
        array $thesis,
        array $seriesWindows,
        array $outcomes,
        array $calibration,
        array $leads,
        int $nowTs,
    ): ?string {
        $expiresAt = strtotime(AiValueNormalizer::trimmedStringOrNull($thesis[self::FIELD_EXPIRES_AT] ?? null) ?? '');
        if ($expiresAt !== false && $nowTs >= $expiresAt) {
            return self::DEATH_REASON_TTL_EXPIRED;
        }

        $criterion = AiValueNormalizer::arrayOrEmpty($thesis[self::FIELD_DEATH_CRITERION] ?? null);
        $kind = AiValueNormalizer::trimmedStringOrNull($criterion[self::FIELD_KIND] ?? null) ?? '';

        return match ($kind) {
            self::FIELD_SERIES_RECOVERY => self::seriesRecoveryMet($thesis, $seriesWindows, $criterion) ? self::FIELD_SERIES_RECOVERY : null,
            self::FIELD_CALIBRATION_RESOLVED => self::calibrationResolved($calibration, $criterion) ? self::FIELD_CALIBRATION_RESOLVED : null,
            self::FIELD_LEAD_CLUSTER_CLEARED => self::leadClusterCleared($thesis, $leads, $criterion) ? self::FIELD_LEAD_CLUSTER_CLEARED : null,
            self::FIELD_OUTCOME_PROVEN => self::outcomeProven($thesis, $outcomes) ? self::FIELD_OUTCOME_PROVEN : null,
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>  $thesis
     * @param  list<array<string,mixed>>  $seriesWindows
     * @param  array<string,mixed>  $criterion
     */
    private static function seriesRecoveryMet(array $thesis, array $seriesWindows, array $criterion): bool
    {
        $threshold = AiValueNormalizer::finiteFloatOrNull($criterion[self::FIELD_THRESHOLD] ?? null) ?? 0.0;
        $need = max(self::DEFAULT_CONSECUTIVE_WINDOWS, (int) (AiValueNormalizer::finiteFloatOrNull($criterion[self::FIELD_CONSECUTIVE_WINDOWS] ?? null) ?? self::DEFAULT_CONSECUTIVE_WINDOWS));
        $refs = AiValueNormalizer::arrayOrEmpty($thesis[self::FIELD_EVIDENCE] ?? null);
        $series = '';
        $stage = self::FIELD_DEFAULT;
        foreach ($refs as $ref) {
            if (! is_array($ref) || ($ref[self::FIELD_SOURCE] ?? '') !== self::FIELD_SERIES) {
                continue;
            }
            if (preg_match('/series:([^:]+):stage=([^:]+):window=/', AiValueNormalizer::trimmedStringOrNull($ref[self::FIELD_REF] ?? null) ?? '', $matches) === 1) {
                $series = AiValueNormalizer::trimmedStringOrNull($matches[1]) ?? '';
                $stage = AiValueNormalizer::trimmedStringOrNull($matches[2]) ?? '';
                break;
            }
        }
        if ($series === '') {
            return false;
        }

        $matching = array_values(array_filter($seriesWindows, static function (array $window) use ($series, $stage): bool {
            return (AiValueNormalizer::trimmedStringOrNull($window[self::FIELD_SERIES] ?? null) ?? '') === $series
                && (AiValueNormalizer::trimmedStringOrNull($window[self::FIELD_STAGE] ?? null) ?? self::FIELD_DEFAULT) === $stage;
        }));
        usort($matching, static fn (array $a, array $b): int => ((int) (AiValueNormalizer::finiteFloatOrNull($a[self::FIELD_WINDOW] ?? null) ?? 0)) <=> ((int) (AiValueNormalizer::finiteFloatOrNull($b[self::FIELD_WINDOW] ?? null) ?? 0)));
        $tail = array_slice($matching, -$need);
        if (count($tail) < $need) {
            return false;
        }

        foreach ($tail as $window) {
            if ((AiValueNormalizer::finiteFloatOrNull($window[self::FIELD_YIELD] ?? null) ?? 0.0) < $threshold) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $calibration
     * @param  array<string,mixed>  $criterion
     */
    private static function calibrationResolved(array $calibration, array $criterion): bool
    {
        $bands = AiValueNormalizer::arrayOrEmpty($calibration[self::FIELD_BANDS] ?? null);
        $high = AiValueNormalizer::arrayOrEmpty($bands[self::FIELD_HIGH] ?? null);
        $highN = (int) (AiValueNormalizer::finiteFloatOrNull($high[self::FIELD_N_REALIZED] ?? null) ?? 0);
        if ($highN <= 0) {
            return false;
        }
        $highRate = ((int) (AiValueNormalizer::finiteFloatOrNull($high[self::FIELD_REALIZED_TRUE] ?? null) ?? 0)) / $highN;

        return $highRate >= (AiValueNormalizer::finiteFloatOrNull($criterion[self::FIELD_THRESHOLD] ?? null) ?? 0.0);
    }

    /**
     * @param  array<string,mixed>  $thesis
     * @param  list<array<string,mixed>>  $leads
     * @param  array<string,mixed>  $criterion
     */
    private static function leadClusterCleared(array $thesis, array $leads, array $criterion): bool
    {
        $target = '';
        foreach (AiValueNormalizer::arrayOrEmpty($thesis[self::FIELD_ALIGNMENT_KEYS] ?? null) as $key) {
            $key = AiValueNormalizer::trimmedStringOrNull($key) ?? '';
            if (str_contains($key, '/')) {
                $target = ltrim($key, '/');
                break;
            }
        }
        if ($target === '') {
            return false;
        }

        $remaining = 0;
        foreach ($leads as $lead) {
            if (! is_array($lead)) {
                continue;
            }
            if (ltrim(AiValueNormalizer::trimmedStringOrNull($lead[self::FIELD_TARGET_PATH] ?? null) ?? '', '/') === $target) {
                $remaining++;
            }
        }

        return $remaining < self::INT_2;
    }

    /**
     * @param  array<string,mixed>  $thesis
     * @param  list<array<string,mixed>>  $outcomes
     */
    private static function outcomeProven(array $thesis, array $outcomes): bool
    {
        $path = '';
        foreach (AiValueNormalizer::arrayOrEmpty($thesis[self::FIELD_ALIGNMENT_KEYS] ?? null) as $key) {
            $key = AiValueNormalizer::trimmedStringOrNull($key) ?? '';
            if (! str_contains($key, '/')) {
                $path = $key;
                break;
            }
        }
        if ($path === '') {
            return false;
        }

        foreach ($outcomes as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_PATH] ?? null) ?? '') === $path && ($row[self::FIELD_PROVEN_REAL] ?? null) === true) {
                return true;
            }
        }

        return false;
    }
}
