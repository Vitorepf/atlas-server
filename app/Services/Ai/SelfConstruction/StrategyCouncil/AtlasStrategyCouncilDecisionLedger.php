<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\StrategyCouncil;

use RuntimeException;

/**
 * Append-only ledger for Strategy Council CHOICES and REJECTED ALTERNATIVES.
 *
 * INVARIANTS:
 *   - APPEND-ONLY: fopen('a') + flock(LOCK_EX); existing rows are NEVER overwritten.
 *   - VALIDATES required fields; missing/invalid ⇒ throws and DOES NOT WRITE.
 *   - IDEMPOTENT: duplicate decision_hash returns status=already_recorded; no second row.
 *   - DETERMINISTIC decision_hash = sha256 over canonical {decision_id, selected, rejected,
 *     reason_vectors (sorted), ambition_level, evidence_refs (sorted), decided_at}.
 */
final class AtlasStrategyCouncilDecisionLedger
{
    public const SCHEMA = 'atlas.strategycouncil.decision.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_ALREADY = 'already_recorded';

    public const VALID_AMBITION_LEVELS = [
        AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_HOLD,
        AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_NARROW,
        AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_STANDARD,
        AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_BOLD,
    ];

    public function __construct(private readonly string $ledgerPath) {}

    /**
     * @param  array{
     *     decision_id:string,
     *     selected_candidate_id:string,
     *     rejected_candidate_ids:list<string>,
     *     reason_vectors:list<string>,
     *     ambition_level:string,
     *     evidence_refs:list<string>,
     *     decided_at:string
     * }  $payload
     * @return array{status:string, row?:array<string,mixed>}
     */
    public function append(array $payload): array
    {
        $decisionId = (string) ($payload['decision_id'] ?? '');
        $selected = (string) ($payload['selected_candidate_id'] ?? '');
        $rejected = is_array($payload['rejected_candidate_ids'] ?? null) ? array_values(array_map('strval', $payload['rejected_candidate_ids'])) : null;
        $reasons = is_array($payload['reason_vectors'] ?? null) ? array_values(array_map('strval', $payload['reason_vectors'])) : null;
        $ambition = (string) ($payload['ambition_level'] ?? '');
        $evidence = is_array($payload['evidence_refs'] ?? null) ? array_values(array_map('strval', $payload['evidence_refs'])) : null;
        $decidedAt = (string) ($payload['decided_at'] ?? '');

        if ($decisionId === '') {
            throw new RuntimeException('strategy decision ledger: missing decision_id');
        }
        if ($selected === '') {
            throw new RuntimeException('strategy decision ledger: missing selected_candidate_id');
        }
        if ($rejected === null) {
            throw new RuntimeException('strategy decision ledger: missing rejected_candidate_ids (use [] for none)');
        }
        if ($reasons === null) {
            throw new RuntimeException('strategy decision ledger: missing reason_vectors (use [] for none)');
        }
        if (! in_array($ambition, self::VALID_AMBITION_LEVELS, true)) {
            throw new RuntimeException('strategy decision ledger: invalid ambition_level: '.($ambition === '' ? 'missing' : $ambition));
        }
        if ($evidence === null || $evidence === []) {
            throw new RuntimeException('strategy decision ledger: missing evidence_refs');
        }
        if ($decidedAt === '') {
            throw new RuntimeException('strategy decision ledger: missing decided_at');
        }

        sort($rejected, SORT_STRING);
        sort($reasons, SORT_STRING);
        sort($evidence, SORT_STRING);

        $canonical = [
            'decision_id' => $decisionId,
            'selected_candidate_id' => $selected,
            'rejected_candidate_ids' => $rejected,
            'reason_vectors' => $reasons,
            'ambition_level' => $ambition,
            'evidence_refs' => $evidence,
            'decided_at' => $decidedAt,
        ];
        ksort($canonical);
        $decisionHash = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($this->alreadyRecorded($decisionHash)) {
            return ['status' => self::STATUS_ALREADY];
        }

        $row = $canonical + ['schema' => self::SCHEMA, 'decision_hash' => $decisionHash];
        $this->appendOnly($row);

        return ['status' => self::STATUS_OK, 'row' => $row];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        if (! is_file($this->ledgerPath)) {
            return [];
        }
        $out = [];
        foreach (file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function bySelectedCandidate(string $candidateId): array
    {
        return array_values(array_filter($this->all(), static fn (array $r): bool => (string) ($r['selected_candidate_id'] ?? '') === $candidateId));
    }

    /**
     * Priority query: filter by ambition level.
     *
     * @return list<array<string,mixed>>
     */
    public function byAmbitionLevel(string $level): array
    {
        return array_values(array_filter($this->all(), static fn (array $r): bool => (string) ($r['ambition_level'] ?? '') === $level));
    }

    /**
     * Refusal query: rows where $candidateId appears in rejected_candidate_ids.
     *
     * @return list<array<string,mixed>>
     */
    public function byRefusedCandidate(string $candidateId): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $r): bool => in_array($candidateId, (array) ($r['rejected_candidate_ids'] ?? []), true),
        ));
    }

    /**
     * Bounded export — at most $limit rows, oldest-first.
     *
     * @return list<array<string,mixed>>
     */
    public function export(int $limit = 50): array
    {
        return array_slice($this->all(), 0, max(0, $limit));
    }

    private function alreadyRecorded(string $decisionHash): bool
    {
        foreach ($this->all() as $r) {
            if ((string) ($r['decision_hash'] ?? '') === $decisionHash) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function appendOnly(array $row): void
    {
        $dir = dirname($this->ledgerPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = @fopen($this->ledgerPath, 'a');
        if ($fh === false) {
            throw new RuntimeException('strategy decision ledger cannot open '.$this->ledgerPath);
        }
        try {
            if (! flock($fh, LOCK_EX)) {
                throw new RuntimeException('strategy decision ledger cannot acquire LOCK_EX');
            }
            fwrite($fh, (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            fflush($fh);
            @\fsync($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
