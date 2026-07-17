<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * MULTN17-06 — vision thesis lifecycle: TTL, death criterion archival, active-set for reorder.
 */
final class EvidenceVisionThesisLifecycle
{
    public const SCHEMA_VERSION = 'atlas.originator.evidence_vision_thesis_lifecycle.v1';

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
        foreach (AiValueNormalizer::arrayOrEmpty($composed['theses'] ?? null) as $thesis) {
            if (! is_array($thesis)) {
                continue;
            }
            $thesisId = AiValueNormalizer::trimmedString($thesis['thesis_id'] ?? '');
            if ($thesisId === '' || isset(self::$active[$thesisId])) {
                continue;
            }
            if (count(self::$active) >= EvidenceVisionThesisComposer::MAX_THESES) {
                break;
            }
            self::$active[$thesisId] = array_merge($thesis, ['status' => 'active', 'archive_receipt' => null]);
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
        $nowTs = strtotime($now ?? gmdate('c')) ?: time();
        $archived = [];

        foreach (self::$active as $thesisId => $thesis) {
            $reason = self::deathReason($thesis, $seriesWindows, $outcomes, $calibration, $leads, $nowTs);
            if ($reason !== null) {
                $archived[] = self::archive(AiValueNormalizer::trimmedString($thesisId), $reason);
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
            static fn (array $thesis): bool => ($thesis['status'] ?? '') === 'active',
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
                'schema_version' => self::SCHEMA_VERSION,
                'thesis_id' => $thesisId,
                'status' => 'refused',
                'reason' => 'thesis_not_active',
            ];
        }

        $receipt = [
            'schema_version' => self::SCHEMA_VERSION,
            'thesis_id' => $thesisId,
            'archived_at_basis' => $reason,
            'claim' => AiValueNormalizer::trimmedString($thesis['claim'] ?? ''),
            'death_criterion' => AiValueNormalizer::arrayOrEmpty($thesis['death_criterion'] ?? null),
            'receipt_hash' => hash('sha256', json_encode([$thesisId, $reason, $thesis['claim'] ?? ''], JSON_UNESCAPED_SLASHES)),
        ];

        $thesis['status'] = 'archived';
        $thesis['archive_receipt'] = $receipt;
        unset(self::$active[$thesisId]);
        self::$archived[] = $thesis;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'thesis_id' => $thesisId,
            'status' => 'archived',
            'archive_receipt' => $receipt,
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
        $expiresAt = strtotime(AiValueNormalizer::trimmedString($thesis['expires_at'] ?? ''));
        if ($expiresAt !== false && $nowTs >= $expiresAt) {
            return 'ttl_expired';
        }

        $criterion = AiValueNormalizer::arrayOrEmpty($thesis['death_criterion'] ?? null);
        $kind = AiValueNormalizer::trimmedString($criterion['kind'] ?? '');

        return match ($kind) {
            'series_recovery' => self::seriesRecoveryMet($thesis, $seriesWindows, $criterion) ? 'series_recovery' : null,
            'calibration_resolved' => self::calibrationResolved($calibration, $criterion) ? 'calibration_resolved' : null,
            'lead_cluster_cleared' => self::leadClusterCleared($thesis, $leads, $criterion) ? 'lead_cluster_cleared' : null,
            'outcome_proven' => self::outcomeProven($thesis, $outcomes) ? 'outcome_proven' : null,
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
        $threshold = AiValueNormalizer::finiteFloatOrNull($criterion['threshold'] ?? null) ?? 0.0;
        $need = max(2, (int) ($criterion['consecutive_windows'] ?? 2));
        $refs = (array) ($thesis['evidence'] ?? []);
        $series = '';
        $stage = 'default';
        foreach ($refs as $ref) {
            if (! is_array($ref) || ($ref['source'] ?? '') !== 'series') {
                continue;
            }
            if (preg_match('/series:([^:]+):stage=([^:]+):window=/', AiValueNormalizer::trimmedString($ref['ref'] ?? ''), $matches) === 1) {
                $series = AiValueNormalizer::trimmedString($matches[1]);
                $stage = AiValueNormalizer::trimmedString($matches[2]);
                break;
            }
        }
        if ($series === '') {
            return false;
        }

        $matching = array_values(array_filter($seriesWindows, static function (array $window) use ($series, $stage): bool {
            return AiValueNormalizer::trimmedString($window['series'] ?? '') === $series
                && (AiValueNormalizer::trimmedString($window['stage'] ?? 'default') ?: 'default') === $stage;
        }));
        usort($matching, static fn (array $a, array $b): int => ((int) ($a['window'] ?? 0)) <=> ((int) ($b['window'] ?? 0)));
        $tail = array_slice($matching, -$need);
        if (count($tail) < $need) {
            return false;
        }

        foreach ($tail as $window) {
            if ((AiValueNormalizer::finiteFloatOrNull($window['yield'] ?? null) ?? 0.0) < $threshold) {
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
        $bands = AiValueNormalizer::arrayOrEmpty($calibration['bands'] ?? null);
        $high = AiValueNormalizer::arrayOrEmpty($bands['high'] ?? null);
        $highN = (int) ($high['n_realized'] ?? 0);
        if ($highN <= 0) {
            return false;
        }
        $highRate = ((int) ($high['realized_true'] ?? 0)) / $highN;

        return $highRate >= (AiValueNormalizer::finiteFloatOrNull($criterion['threshold'] ?? null) ?? 0.0);
    }

    /**
     * @param  array<string,mixed>  $thesis
     * @param  list<array<string,mixed>>  $leads
     * @param  array<string,mixed>  $criterion
     */
    private static function leadClusterCleared(array $thesis, array $leads, array $criterion): bool
    {
        $target = '';
        foreach (AiValueNormalizer::arrayOrEmpty($thesis['alignment_keys'] ?? null) as $key) {
            $key = AiValueNormalizer::trimmedString($key);
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
            if (ltrim(AiValueNormalizer::trimmedString($lead['target_path'] ?? ''), '/') === $target) {
                $remaining++;
            }
        }

        return $remaining < 2;
    }

    /**
     * @param  array<string,mixed>  $thesis
     * @param  list<array<string,mixed>>  $outcomes
     */
    private static function outcomeProven(array $thesis, array $outcomes): bool
    {
        $path = '';
        foreach (AiValueNormalizer::arrayOrEmpty($thesis['alignment_keys'] ?? null) as $key) {
            $key = AiValueNormalizer::trimmedString($key);
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
            if (AiValueNormalizer::trimmedString($row['path'] ?? '') === $path && ($row['proven_real'] ?? null) === true) {
                return true;
            }
        }

        return false;
    }
}
