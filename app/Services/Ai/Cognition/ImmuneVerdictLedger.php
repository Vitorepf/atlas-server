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

final class ImmuneVerdictLedger
{
    public const TABLE = 'immune_verdict_ledger';

    public const SCHEMA_VERSION = 'atlas.cognition.immune_verdict_ledger.v1';

    public const LABEL_TRUE_BLOCK = 'true_block';

    public const LABEL_FALSE_BLOCK = 'false_block';

    public const LABEL_MISSED_POISON = 'missed_poison';

    public const GATE_STATUS_PASS = 'pass';

    public const GATE_STATUS_BLOCK = 'block';

    public const GATE_STATUS_PENDING = 'pending';

    /** @var list<string> */
    public const GATE_STATUSES = [
        self::GATE_STATUS_PASS,
        self::GATE_STATUS_BLOCK,
        self::GATE_STATUS_PENDING,
    ];

    public const WRITER_UNKNOWN = 'unknown';
    public const FIELD_SAMPLE_LABEL = 'sample_label';
    public const FIELD_PROMOTION_STATUS = 'promotion_status';
    public const FIELD_PENDING_GATE_IDS = 'pending_gate_ids';
    public const FIELD_METADATA = 'metadata';
    public const FIELD_GATE_STATUSES = 'gate_statuses';
    public const FIELD_EXPECTED_BLOCK_GATE_IDS = 'expected_block_gate_ids';
    public const FIELD_DECIDED_AT = 'decided_at';
    public const FIELD_BLOCKING_GATE_IDS = 'blocking_gate_ids';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_CANDIDATE_HASH = 'candidate_hash';
    public const FIELD_WRITER = 'writer';
    public const FIELD_ID = 'id';
    public const FIELD_UPDATED_AT = 'updated_at';

    /** @var list<string> */
    public const LABELS = [
        self::LABEL_TRUE_BLOCK,
        self::LABEL_FALSE_BLOCK,
        self::LABEL_MISSED_POISON,
    ];

    /**
     * @param  array<string,mixed>  $verdict
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>|null
     */
    public function recordVerdict(string $candidateHash, string $writer, array $verdict, array $context = []): ?array
    {
        if (! DatabaseTableAvailability::has(self::TABLE)) {
            return null;
        }

        $row = $this->sampleFromVerdict($candidateHash, $writer, $verdict, $context);

        try {
            DB::table(self::TABLE)->insert($this->databaseRow($row));
        } catch (Throwable) {
            return null;
        }

        return $row;
    }

    /**
     * @param  array<string,mixed>  $verdict
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function sampleFromVerdict(string $candidateHash, string $writer, array $verdict, array $context = []): array
    {
        $gateStatuses = $this->normalizeGateStatuses(AiValueNormalizer::arrayOrEmpty($verdict[self::FIELD_GATE_STATUSES] ?? null));
        $expectedBlockGateIds = $this->normalizeGateIds(AiValueNormalizer::arrayOrEmpty($context[self::FIELD_EXPECTED_BLOCK_GATE_IDS] ?? null));
        $blockingGateIds = $this->normalizeGateIds(AiValueNormalizer::arrayOrEmpty($verdict[self::FIELD_BLOCKING_GATE_IDS] ?? null));
        $sampleLabel = $this->sampleLabel(
            AiValueNormalizer::trimmedScalarStringOrNull($context[self::FIELD_SAMPLE_LABEL] ?? null) ?? '',
            $gateStatuses,
            $expectedBlockGateIds,
            $blockingGateIds,
        );
        $decidedAt = $this->parseDate($context[self::FIELD_DECIDED_AT] ?? null) ?? CarbonImmutable::now('UTC');

        return [
            self::FIELD_ID => (string) Str::uuid(),
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_CANDIDATE_HASH => $this->candidateHash($candidateHash),
            self::FIELD_WRITER => trim($writer) !== '' ? trim($writer) : self::WRITER_UNKNOWN,
            self::FIELD_GATE_STATUSES => $gateStatuses,
            self::FIELD_PROMOTION_STATUS => AiValueNormalizer::trimmedScalarStringOrNull($verdict[self::FIELD_PROMOTION_STATUS] ?? null) ?? 'unclassified',
            self::FIELD_BLOCKING_GATE_IDS => $blockingGateIds,
            self::FIELD_PENDING_GATE_IDS => $this->normalizeGateIds(AiValueNormalizer::arrayOrEmpty($verdict[self::FIELD_PENDING_GATE_IDS] ?? null)),
            self::FIELD_EXPECTED_BLOCK_GATE_IDS => $expectedBlockGateIds,
            self::FIELD_SAMPLE_LABEL => $sampleLabel,
            self::FIELD_DECIDED_AT => $decidedAt->toIso8601String(),
            self::FIELD_METADATA => AiValueNormalizer::arrayOrEmpty($context[self::FIELD_METADATA] ?? null),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function rows(?int $days = null): array
    {
        if (! DatabaseTableAvailability::has(self::TABLE)) {
            return [];
        }

        try {
            $query = DB::table(self::TABLE);
            if ($days !== null) {
                $query->where('decided_at', '>=', CarbonImmutable::now('UTC')->subDays(max(1, $days)));
            }

            return $query
                ->orderBy('decided_at')
                ->get()
                ->map(fn (object $row): array => $this->rowFromDatabase($row))
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string,mixed>  $gateStatuses
     * @param  list<string>  $expectedBlockGateIds
     * @param  list<string>  $blockingGateIds
     */
    private function sampleLabel(string $declared, array $gateStatuses, array $expectedBlockGateIds, array $blockingGateIds): ?string
    {
        $declared = AiValueNormalizer::lowerTrimmedString($declared);
        if (in_array($declared, self::LABELS, true)) {
            return $declared;
        }

        if ($expectedBlockGateIds !== []) {
            foreach ($expectedBlockGateIds as $gateId) {
                if (($gateStatuses[$gateId] ?? '') !== self::GATE_STATUS_BLOCK) {
                    return self::LABEL_MISSED_POISON;
                }
            }

            return self::LABEL_TRUE_BLOCK;
        }

        return $blockingGateIds !== [] ? null : null;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function databaseRow(array $row): array
    {
        return [
            self::FIELD_ID => $row[self::FIELD_ID],
            self::FIELD_SCHEMA_VERSION => $row[self::FIELD_SCHEMA_VERSION],
            self::FIELD_CANDIDATE_HASH => $row[self::FIELD_CANDIDATE_HASH],
            self::FIELD_WRITER => $row[self::FIELD_WRITER],
            self::FIELD_GATE_STATUSES => json_encode($row[self::FIELD_GATE_STATUSES], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            self::FIELD_PROMOTION_STATUS => $row[self::FIELD_PROMOTION_STATUS],
            self::FIELD_BLOCKING_GATE_IDS => json_encode($row[self::FIELD_BLOCKING_GATE_IDS], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            self::FIELD_PENDING_GATE_IDS => json_encode($row[self::FIELD_PENDING_GATE_IDS], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            self::FIELD_EXPECTED_BLOCK_GATE_IDS => json_encode($row[self::FIELD_EXPECTED_BLOCK_GATE_IDS], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            self::FIELD_SAMPLE_LABEL => $row[self::FIELD_SAMPLE_LABEL],
            self::FIELD_METADATA => json_encode($row[self::FIELD_METADATA], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            self::FIELD_DECIDED_AT => $row[self::FIELD_DECIDED_AT],
            'created_at' => now(),
            self::FIELD_UPDATED_AT => now(),
        ];
    }

    /** @return array<string,mixed> */
    private function rowFromDatabase(object $row): array
    {
        return [
            self::FIELD_ID => AiValueNormalizer::trimmedScalarStringOrNull($row->id ?? null) ?? '',
            self::FIELD_SCHEMA_VERSION => AiValueNormalizer::trimmedScalarStringOrNull($row->schema_version ?? null) ?? '',
            self::FIELD_CANDIDATE_HASH => AiValueNormalizer::trimmedScalarStringOrNull($row->candidate_hash ?? null) ?? '',
            self::FIELD_WRITER => AiValueNormalizer::trimmedScalarStringOrNull($row->writer ?? null) ?? '',
            self::FIELD_GATE_STATUSES => $this->jsonArray($row->gate_statuses ?? []),
            self::FIELD_PROMOTION_STATUS => AiValueNormalizer::trimmedScalarStringOrNull($row->promotion_status ?? null) ?? '',
            self::FIELD_BLOCKING_GATE_IDS => $this->jsonList($row->blocking_gate_ids ?? []),
            self::FIELD_PENDING_GATE_IDS => $this->jsonList($row->pending_gate_ids ?? []),
            self::FIELD_EXPECTED_BLOCK_GATE_IDS => $this->jsonList($row->expected_block_gate_ids ?? []),
            self::FIELD_SAMPLE_LABEL => AiValueNormalizer::trimmedStringOrNull($row->sample_label ?? null),
            self::FIELD_METADATA => $this->jsonArray($row->metadata ?? []),
            self::FIELD_DECIDED_AT => AiValueNormalizer::trimmedString($row->decided_at ?? ''),
        ];
    }

    /** @param array<string,mixed> $statuses @return array<string,string> */
    private function normalizeGateStatuses(array $statuses): array
    {
        $out = [];
        foreach ($statuses as $gateId => $status) {
            $gateId = AiValueNormalizer::upperTrimmedString($gateId);
            $status = AiValueNormalizer::lowerTrimmedString($status);
            if (preg_match('/^G[0-8]$/', $gateId) === 1 && in_array($status, self::GATE_STATUSES, true)) {
                $out[$gateId] = $status;
            }
        }

        ksort($out);

        return $out;
    }

    /** @param list<mixed> $gateIds @return list<string> */
    private function normalizeGateIds(array $gateIds): array
    {
        $out = [];
        foreach ($gateIds as $gateId) {
            $gateId = AiValueNormalizer::upperTrimmedString($gateId);
            if (preg_match('/^G[0-8]$/', $gateId) === 1) {
                $out[$gateId] = true;
            }
        }

        return array_keys($out);
    }

    private function candidateHash(string $candidateHash): string
    {
        $candidateHash = AiValueNormalizer::lowerTrimmedString($candidateHash);

        return preg_match('/^[a-f0-9]{64}$/', $candidateHash) === 1
            ? $candidateHash
            : hash('sha256', $candidateHash);
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

    /** @return list<string> */
    private function jsonList(mixed $value): array
    {
        return array_values(array_map('strval', $this->jsonArray($value)));
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
