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

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_OK = 'ok';

    public const STATUS_PENDING_WINDOW = 'pending_window';


    public const FIELD_STATUS = 'status';

    public const FIELD_SIGNATURE = 'signature';

    public const FIELD_SCHEMA_VERSION = 'schema_version';

    public const FIELD_HOSTILE_CLASS = 'hostile_class';

    public const FIELD_ORIGIN_REF = 'origin_ref';

    public const FIELD_HIT_COUNT = 'hit_count';

    public const FIELD_CONTENT_HASH = 'content_hash';

    public const FIELD_LAST_HIT_AT = 'last_hit_at';

    public const FIELD_MODE = 'mode';

    public const FIELD_DECAY_DAYS = 'decay_days';

    public const FIELD_CREATED_AT = 'created_at';

    public const FIELD_UPDATED_AT = 'updated_at';

    public const ORIGIN_VERDICT = 'immune_verdict';

    public const ORIGIN_MEMORY_REVERT = 'memory_revert';

    public const DECAY_DAYS_CONFIG_KEY = 'atlas.aaeos.immune_signature.decay_days';

    public const DEFAULT_DECAY_DAYS = 90;

    public const MODE_CONFIG_KEY = 'atlas.aaeos.immune_signature.mode';

    public const MODE_OFF = 'off';

    public const MODE_OBSERVE = 'observe';

    public const MODE_ENFORCE = 'enforce';

    public const DEFAULT_MODE = self::MODE_OBSERVE;

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
        $existing = $this->findBySignature($derived[self::FIELD_SIGNATURE]);
        if ($existing !== null) {
            return $existing;
        }

        $now = CarbonImmutable::now('UTC');
        $row = [
            'id' => (string) Str::uuid(),
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_SIGNATURE => $derived[self::FIELD_SIGNATURE],
            self::FIELD_CONTENT_HASH => $derived['family'][self::FIELD_CONTENT_HASH],
            'signature_family' => $derived['family'],
            self::FIELD_ORIGIN_REF => $originRef,
            'origin_kind' => $originKind,
            self::FIELD_HOSTILE_CLASS => $derived['family'][self::FIELD_HOSTILE_CLASS],
            self::FIELD_HIT_COUNT => 0,
            'first_seen' => $now->toIso8601String(),
            self::FIELD_LAST_HIT_AT => null,
            self::FIELD_STATUS => self::STATUS_ACTIVE,
            'reverse_handle' => 'atlas:immune:signature-revoke --ref='.$derived[self::FIELD_SIGNATURE],
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
        if ($this->mode() === self::MODE_OFF || ! DatabaseTableAvailability::has(self::TABLE)) {
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
            self::FIELD_SIGNATURE => AiValueNormalizer::trimmedScalarStringOrNull($cell[self::FIELD_SIGNATURE] ?? null) ?? '',
            self::FIELD_HOSTILE_CLASS => AiValueNormalizer::trimmedScalarStringOrNull($cell[self::FIELD_HOSTILE_CLASS] ?? null) ?? '',
            self::FIELD_ORIGIN_REF => AiValueNormalizer::trimmedScalarStringOrNull($cell[self::FIELD_ORIGIN_REF] ?? null) ?? '',
            'hit_count_after' => ((int) (AiValueNormalizer::finiteFloatOrNull($cell[self::FIELD_HIT_COUNT] ?? null) ?? 0)) + 1,
            self::FIELD_MODE => $this->mode(),
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
        if ($row === null || ($row[self::FIELD_STATUS] ?? '') !== self::STATUS_ACTIVE) {
            return null;
        }

        try {
            DB::table(self::TABLE)
                ->where('id', $row['id'])
                ->update([
                    self::FIELD_STATUS => self::STATUS_REVOKED,
                    self::FIELD_UPDATED_AT => now(),
                ]);
        } catch (Throwable) {
            return null;
        }

        $row[self::FIELD_STATUS] = self::STATUS_REVOKED;

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
                    self::FIELD_STATUS => self::STATUS_DECAYED,
                    self::FIELD_UPDATED_AT => now(),
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
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                'measure_id' => self::MEASURE_ID,
                self::FIELD_STATUS => self::STATUS_UNAVAILABLE,
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
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                'measure_id' => self::MEASURE_ID,
                self::FIELD_STATUS => self::STATUS_UNAVAILABLE,
                'active_cells' => 0,
                'cells_with_hit_count_gte_2' => 0,
            ];
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'generated_at' => now()->toIso8601String(),
            self::FIELD_MODE => $this->mode(),
            self::FIELD_STATUS => $soaked >= 3 ? self::STATUS_OK : self::STATUS_PENDING_WINDOW,
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

        return in_array($mode, [self::MODE_OFF, self::MODE_OBSERVE, self::MODE_ENFORCE], true) ? $mode : self::DEFAULT_MODE;
    }

    public function enforceEnabled(): bool
    {
        return $this->mode() === self::MODE_ENFORCE;
    }

    private function recordHit(string $id): void
    {
        try {
            DB::table(self::TABLE)
                ->where('id', $id)
                ->where('status', self::STATUS_ACTIVE)
                ->update([
                    self::FIELD_HIT_COUNT => DB::raw('hit_count + 1'),
                    self::FIELD_LAST_HIT_AT => now(),
                    self::FIELD_UPDATED_AT => now(),
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
            self::FIELD_SCHEMA_VERSION => $row[self::FIELD_SCHEMA_VERSION],
            self::FIELD_SIGNATURE => $row[self::FIELD_SIGNATURE],
            self::FIELD_CONTENT_HASH => $row[self::FIELD_CONTENT_HASH],
            'signature_family' => json_encode($row['signature_family'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            self::FIELD_ORIGIN_REF => $row[self::FIELD_ORIGIN_REF],
            'origin_kind' => $row['origin_kind'],
            self::FIELD_HOSTILE_CLASS => $row[self::FIELD_HOSTILE_CLASS],
            self::FIELD_HIT_COUNT => $row[self::FIELD_HIT_COUNT],
            'first_seen' => $this->parseDate($row['first_seen'] ?? null) ?? $now,
            self::FIELD_LAST_HIT_AT => $this->parseDate($row[self::FIELD_LAST_HIT_AT] ?? null),
            self::FIELD_STATUS => $row[self::FIELD_STATUS],
            'reverse_handle' => $row['reverse_handle'],
            'metadata' => json_encode($row['metadata'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            self::FIELD_CREATED_AT => $now,
            self::FIELD_UPDATED_AT => $now,
        ];
    }

    /** @return array<string,mixed> */
    private function rowFromDatabase(object $row): array
    {
        return [
            'id' => AiValueNormalizer::trimmedScalarStringOrNull($row->id ?? null) ?? '',
            self::FIELD_SCHEMA_VERSION => AiValueNormalizer::trimmedScalarStringOrNull($row->schema_version ?? null) ?? '',
            self::FIELD_SIGNATURE => AiValueNormalizer::trimmedScalarStringOrNull($row->signature ?? null) ?? '',
            self::FIELD_CONTENT_HASH => AiValueNormalizer::trimmedScalarStringOrNull($row->content_hash ?? null) ?? '',
            'signature_family' => $this->jsonArray($row->signature_family ?? []),
            self::FIELD_ORIGIN_REF => AiValueNormalizer::trimmedScalarStringOrNull($row->origin_ref ?? null) ?? '',
            'origin_kind' => AiValueNormalizer::trimmedScalarStringOrNull($row->origin_kind ?? null) ?? '',
            self::FIELD_HOSTILE_CLASS => AiValueNormalizer::trimmedScalarStringOrNull($row->hostile_class ?? null) ?? '',
            self::FIELD_HIT_COUNT => (int) $row->hit_count,
            'first_seen' => AiValueNormalizer::trimmedString($row->first_seen ?? ''),
            self::FIELD_LAST_HIT_AT => AiValueNormalizer::trimmedStringOrNull($row->last_hit_at ?? null),
            self::FIELD_STATUS => AiValueNormalizer::trimmedScalarStringOrNull($row->status ?? null) ?? '',
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
