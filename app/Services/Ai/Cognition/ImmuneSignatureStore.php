<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Support\AiValueNormalizer;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * MAXI-05 — append-only immune signature store (privacy: hash/centroid only).
 */
final class ImmuneSignatureStore
{
    public const TABLE = 'immune_signature_store';

    public const SCHEMA_VERSION = 'atlas.cognition.immune_signature_store.v1';

    public const MEASURE_ID = 'atlas.immune.signature_store.v1';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_DECAYED = 'decayed';

    public const ORIGIN_VERDICT = 'immune_verdict';

    public const ORIGIN_MEMORY_REVERT = 'memory_revert';

    public const DECAY_DAYS_CONFIG_KEY = 'atlas.aaeos.immune_signature.decay_days';

    public const DEFAULT_DECAY_DAYS = 90;

    public const MODE_CONFIG_KEY = 'atlas.aaeos.immune_signature.mode';

    public const DEFAULT_MODE = 'observe';

    private readonly ImmuneSignatureDeriver $deriver;

    public function __construct(?ImmuneSignatureDeriver $deriver = null)
    {
        $this->deriver = $deriver ?? new ImmuneSignatureDeriver;
    }

    /**
     * @param  list<string>  $matchedSignals
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>|null
     */
    public function recordFromIncident(
        string $originRef,
        string $originKind,
        string $contentHash,
        string $hostileClass,
        array $matchedSignals,
        array $metadata = [],
    ): ?array {
        if (! DatabaseTableAvailability::has(self::TABLE)) {
            return null;
        }

        $originRef = trim($originRef);
        $originKind = trim($originKind);
        if ($originRef === '' || $originKind === '') {
            return null;
        }

        $derived = $this->deriver->derive($contentHash, $hostileClass, $matchedSignals);
        $existing = $this->findBySignature($derived['signature']);
        if ($existing !== null) {
            return $existing;
        }

        $now = CarbonImmutable::now('UTC');
        $row = [
            'id' => (string) Str::uuid(),
            'schema_version' => self::SCHEMA_VERSION,
            'signature' => $derived['signature'],
            'content_hash' => $derived['family']['content_hash'],
            'signature_family' => $derived['family'],
            'origin_ref' => $originRef,
            'origin_kind' => $originKind,
            'hostile_class' => $derived['family']['hostile_class'],
            'hit_count' => 0,
            'first_seen' => $now->toIso8601String(),
            'last_hit_at' => null,
            'status' => self::STATUS_ACTIVE,
            'reverse_handle' => 'atlas:immune:signature-revoke --ref='.$derived['signature'],
            'metadata' => $metadata,
        ];

        try {
            DB::table(self::TABLE)->insert($this->databaseRow($row, $now));
        } catch (Throwable) {
            return null;
        }

        return $row;
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>|null
     */
    public function consult(string $text, array $metadata = []): ?array
    {
        if ($this->mode() === 'off' || ! DatabaseTableAvailability::has(self::TABLE)) {
            return null;
        }

        $this->decayStale();

        $contentHash = $this->deriver->contentHashFromText($text);
        $cell = $this->findActiveByContentHash($contentHash);
        if ($cell === null) {
            return null;
        }

        $this->recordHit(AiValueNormalizer::trimmedScalarStringOrNull($cell['id'] ?? null) ?? '');

        return [
            'ref' => AiValueNormalizer::trimmedScalarStringOrNull($cell['id'] ?? null) ?? '',
            'signature' => AiValueNormalizer::trimmedScalarStringOrNull($cell['signature'] ?? null) ?? '',
            'hostile_class' => AiValueNormalizer::trimmedScalarStringOrNull($cell['hostile_class'] ?? null) ?? '',
            'origin_ref' => AiValueNormalizer::trimmedScalarStringOrNull($cell['origin_ref'] ?? null) ?? '',
            'hit_count_after' => ((int) (AiValueNormalizer::finiteFloatOrNull($cell['hit_count'] ?? null) ?? 0)) + 1,
            'mode' => $this->mode(),
        ];
    }

  /**
     * @return array<string,mixed>|null
     */
    public function revoke(string $signatureOrId): ?array
    {
        if (! DatabaseTableAvailability::has(self::TABLE)) {
            return null;
        }

        $row = $this->findBySignature($signatureOrId) ?? $this->findById($signatureOrId);
        if ($row === null || ($row['status'] ?? '') !== self::STATUS_ACTIVE) {
            return null;
        }

        try {
            DB::table(self::TABLE)
                ->where('id', $row['id'])
                ->update([
                    'status' => self::STATUS_REVOKED,
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            return null;
        }

        $row['status'] = self::STATUS_REVOKED;

        return $row;
    }

    public function decayStale(?int $days = null): int
    {
        if (! DatabaseTableAvailability::has(self::TABLE)) {
            return 0;
        }

        $days = max(1, $days ?? (int) (AiValueNormalizer::finiteFloatOrNull(config(self::DECAY_DAYS_CONFIG_KEY, self::DEFAULT_DECAY_DAYS)) ?? self::DEFAULT_DECAY_DAYS));
        $cutoff = CarbonImmutable::now('UTC')->subDays($days);

        try {
            return DB::table(self::TABLE)
                ->where('status', self::STATUS_ACTIVE)
                ->where('hit_count', 0)
                ->where('first_seen', '<', $cutoff)
                ->update([
                    'status' => self::STATUS_DECAYED,
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        if (! DatabaseTableAvailability::has(self::TABLE)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'measure_id' => self::MEASURE_ID,
                'status' => 'unavailable',
                'active_cells' => 0,
                'cells_with_hit_count_gte_2' => 0,
            ];
        }

        try {
            $active = (int) DB::table(self::TABLE)->where('status', self::STATUS_ACTIVE)->count();
            $soaked = (int) DB::table(self::TABLE)
                ->where('status', self::STATUS_ACTIVE)
                ->where('hit_count', '>=', 2)
                ->count();
        } catch (Throwable) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'measure_id' => self::MEASURE_ID,
                'status' => 'unavailable',
                'active_cells' => 0,
                'cells_with_hit_count_gte_2' => 0,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'generated_at' => now()->toIso8601String(),
            'mode' => $this->mode(),
            'status' => $soaked >= 3 ? 'ok' : 'pending_window',
            'pending_reason' => $soaked >= 3 ? null : 'immune_signature_real_hits_soak',
            'active_cells' => $active,
            'cells_with_hit_count_gte_2' => $soaked,
            'acceptance_floor' => [
                'cells_with_hit_count_gte_2' => 3,
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function rows(): array
    {
        if (! DatabaseTableAvailability::has(self::TABLE)) {
            return [];
        }

        try {
            return DB::table(self::TABLE)
                ->orderBy('first_seen')
                ->get()
                ->map(fn (object $row): array => $this->rowFromDatabase($row))
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function mode(): string
    {
        $mode = AiValueNormalizer::lowerTrimmedString(config(self::MODE_CONFIG_KEY, self::DEFAULT_MODE));

        return in_array($mode, ['off', 'observe', 'enforce'], true) ? $mode : 'observe';
    }

    public function enforceEnabled(): bool
    {
        return $this->mode() === 'enforce';
    }

    private function recordHit(string $id): void
    {
        try {
            DB::table(self::TABLE)
                ->where('id', $id)
                ->where('status', self::STATUS_ACTIVE)
                ->update([
                    'hit_count' => DB::raw('hit_count + 1'),
                    'last_hit_at' => now(),
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            // fail-open
        }
    }

    /** @return array<string,mixed>|null */
    private function findActiveByContentHash(string $contentHash): ?array
    {
        try {
            $row = DB::table(self::TABLE)
                ->where('status', self::STATUS_ACTIVE)
                ->where('content_hash', $this->deriver->normalizeHash($contentHash))
                ->orderByDesc('first_seen')
                ->first();
        } catch (Throwable) {
            return null;
        }

        return $row === null ? null : $this->rowFromDatabase($row);
    }

    /** @return array<string,mixed>|null */
    private function findBySignature(string $signature): ?array
    {
        try {
            $row = DB::table(self::TABLE)->where('signature', AiValueNormalizer::lowerTrimmedString($signature))->first();
        } catch (Throwable) {
            return null;
        }

        return $row === null ? null : $this->rowFromDatabase($row);
    }

    /** @return array<string,mixed>|null */
    private function findById(string $id): ?array
    {
        try {
            $row = DB::table(self::TABLE)->where('id', $id)->first();
        } catch (Throwable) {
            return null;
        }

        return $row === null ? null : $this->rowFromDatabase($row);
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function databaseRow(array $row, CarbonImmutable $now): array
    {
        return [
            'id' => $row['id'],
            'schema_version' => $row['schema_version'],
            'signature' => $row['signature'],
            'content_hash' => $row['content_hash'],
            'signature_family' => json_encode($row['signature_family'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'origin_ref' => $row['origin_ref'],
            'origin_kind' => $row['origin_kind'],
            'hostile_class' => $row['hostile_class'],
            'hit_count' => $row['hit_count'],
            'first_seen' => $this->parseDate($row['first_seen'] ?? null) ?? $now,
            'last_hit_at' => $this->parseDate($row['last_hit_at'] ?? null),
            'status' => $row['status'],
            'reverse_handle' => $row['reverse_handle'],
            'metadata' => json_encode($row['metadata'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @return array<string,mixed> */
    private function rowFromDatabase(object $row): array
    {
        return [
            'id' => AiValueNormalizer::trimmedScalarStringOrNull($row->id ?? null) ?? '',
            'schema_version' => AiValueNormalizer::trimmedScalarStringOrNull($row->schema_version ?? null) ?? '',
            'signature' => AiValueNormalizer::trimmedScalarStringOrNull($row->signature ?? null) ?? '',
            'content_hash' => AiValueNormalizer::trimmedScalarStringOrNull($row->content_hash ?? null) ?? '',
            'signature_family' => $this->jsonArray($row->signature_family ?? []),
            'origin_ref' => AiValueNormalizer::trimmedScalarStringOrNull($row->origin_ref ?? null) ?? '',
            'origin_kind' => AiValueNormalizer::trimmedScalarStringOrNull($row->origin_kind ?? null) ?? '',
            'hostile_class' => AiValueNormalizer::trimmedScalarStringOrNull($row->hostile_class ?? null) ?? '',
            'hit_count' => (int) $row->hit_count,
            'first_seen' => AiValueNormalizer::trimmedString($row->first_seen ?? ''),
            'last_hit_at' => AiValueNormalizer::trimmedStringOrNull($row->last_hit_at ?? null),
            'status' => AiValueNormalizer::trimmedScalarStringOrNull($row->status ?? null) ?? '',
            'reverse_handle' => AiValueNormalizer::trimmedStringOrNull($row->reverse_handle ?? null),
            'metadata' => $this->jsonArray($row->metadata ?? []),
        ];
    }

    /** @return array<string,mixed> */
    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $raw = AiValueNormalizer::trimmedStringOrNull($value);
        if ($raw !== null) {
            $decoded = json_decode($raw, true);

            return AiValueNormalizer::arrayOrEmpty($decoded);
        }

        return [];
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }
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
