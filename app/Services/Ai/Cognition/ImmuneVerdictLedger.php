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

    /** @var list<string> */
    private const LABELS = [
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
        $gateStatuses = $this->normalizeGateStatuses(AiValueNormalizer::arrayOrEmpty($verdict['gate_statuses'] ?? null));
        $expectedBlockGateIds = $this->normalizeGateIds(AiValueNormalizer::arrayOrEmpty($context['expected_block_gate_ids'] ?? null));
        $blockingGateIds = $this->normalizeGateIds(AiValueNormalizer::arrayOrEmpty($verdict['blocking_gate_ids'] ?? null));
        $sampleLabel = $this->sampleLabel(
            is_scalar($context['sample_label'] ?? null) ? (string) $context['sample_label'] : '',
            $gateStatuses,
            $expectedBlockGateIds,
            $blockingGateIds,
        );
        $decidedAt = $this->parseDate($context['decided_at'] ?? null) ?? CarbonImmutable::now('UTC');

        return [
            'id' => (string) Str::uuid(),
            'schema_version' => self::SCHEMA_VERSION,
            'candidate_hash' => $this->candidateHash($candidateHash),
            'writer' => trim($writer) !== '' ? trim($writer) : 'unknown',
            'gate_statuses' => $gateStatuses,
            'promotion_status' => is_scalar($verdict['promotion_status'] ?? null)
                ? (string) $verdict['promotion_status']
                : 'unclassified',
            'blocking_gate_ids' => $blockingGateIds,
            'pending_gate_ids' => $this->normalizeGateIds(AiValueNormalizer::arrayOrEmpty($verdict['pending_gate_ids'] ?? null)),
            'expected_block_gate_ids' => $expectedBlockGateIds,
            'sample_label' => $sampleLabel,
            'decided_at' => $decidedAt->toIso8601String(),
            'metadata' => AiValueNormalizer::arrayOrEmpty($context['metadata'] ?? null),
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
                if (($gateStatuses[$gateId] ?? '') !== 'block') {
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
            'id' => $row['id'],
            'schema_version' => $row['schema_version'],
            'candidate_hash' => $row['candidate_hash'],
            'writer' => $row['writer'],
            'gate_statuses' => json_encode($row['gate_statuses'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'promotion_status' => $row['promotion_status'],
            'blocking_gate_ids' => json_encode($row['blocking_gate_ids'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'pending_gate_ids' => json_encode($row['pending_gate_ids'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'expected_block_gate_ids' => json_encode($row['expected_block_gate_ids'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'sample_label' => $row['sample_label'],
            'metadata' => json_encode($row['metadata'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'decided_at' => $row['decided_at'],
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return array<string,mixed> */
    private function rowFromDatabase(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'schema_version' => (string) $row->schema_version,
            'candidate_hash' => (string) $row->candidate_hash,
            'writer' => (string) $row->writer,
            'gate_statuses' => $this->jsonArray($row->gate_statuses ?? []),
            'promotion_status' => (string) $row->promotion_status,
            'blocking_gate_ids' => $this->jsonList($row->blocking_gate_ids ?? []),
            'pending_gate_ids' => $this->jsonList($row->pending_gate_ids ?? []),
            'expected_block_gate_ids' => $this->jsonList($row->expected_block_gate_ids ?? []),
            'sample_label' => AiValueNormalizer::trimmedStringOrNull($row->sample_label ?? null),
            'metadata' => $this->jsonArray($row->metadata ?? []),
            'decided_at' => (string) $row->decided_at,
        ];
    }

    /** @param array<string,mixed> $statuses @return array<string,string> */
    private function normalizeGateStatuses(array $statuses): array
    {
        $out = [];
        foreach ($statuses as $gateId => $status) {
            $gateId = AiValueNormalizer::upperTrimmedString($gateId);
            $status = AiValueNormalizer::lowerTrimmedString($status);
            if (preg_match('/^G[0-8]$/', $gateId) === 1 && in_array($status, ['pass', 'block', 'pending'], true)) {
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
