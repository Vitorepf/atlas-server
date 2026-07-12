<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

use DateTimeImmutable;

/**
 * Projects canonical task outcomes into the fields consumed by the Strategy
 * Council. Missing, invalid or expired observations remain uncertainty; they
 * never become a positive learning signal.
 */
final class AtlasExternalBrainOutcomeSignalProjector
{
    public const SCHEMA = 'atlas.external_brain.outcome_signal_projection.v1';

    /**
     * @param list<array<string,mixed>> $candidates
     * @param list<array<string,mixed>> $outcomes
     * @param array<string,int|float> $recurrenceMap
     * @return array{schema:string,candidates:list<array<string,mixed>>,signals:array<string,array<string,mixed>>}
     */
    public function project(array $candidates, array $outcomes, array $recurrenceMap = [], ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $byKey = [];
        foreach ($outcomes as $outcome) {
            if (! is_array($outcome)) {
                continue;
            }
            foreach ($this->keys($outcome) as $key) {
                $byKey[$key][] = $outcome;
            }
        }

        $projected = [];
        $signals = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $id = (string) ($candidate['candidate_id'] ?? '');
            $keys = $this->keys($candidate);
            $rows = [];
            foreach ($keys as $key) {
                foreach ($byKey[$key] ?? [] as $row) {
                    $rows[spl_object_hash((object) $row).':'.count($rows)] = $row;
                }
            }

            $signal = $this->signal(array_values($rows), $keys, $recurrenceMap, $now);
            $projectedCandidate = $candidate;
            foreach ($signal as $field => $value) {
                if ($field !== 'evidence_refs') {
                    $projectedCandidate[$field] = $value;
                }
            }
            if ($signal['evidence_refs'] !== []) {
                $projectedCandidate['evidence_refs'] = array_values(array_unique(array_merge(
                    (array) ($candidate['evidence_refs'] ?? []),
                    $signal['evidence_refs'],
                )));
            }

            $projected[] = $projectedCandidate;
            $signals[$id] = $signal;
        }

        return ['schema' => self::SCHEMA, 'candidates' => $projected, 'signals' => $signals];
    }

    /** @param array<string,mixed> $row @return list<string> */
    private function keys(array $row): array
    {
        $keys = [];
        foreach (['candidate_id', 'task_family', 'pattern_family', 'outcome_key'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                $keys[] = $field.':'.$value;
            }
        }

        return array_values(array_unique($keys));
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $keys @param array<string,int|float> $recurrenceMap @return array<string,mixed> */
    private function signal(array $rows, array $keys, array $recurrenceMap, DateTimeImmutable $now): array
    {
        $success = 0;
        $adverse = 0;
        $freshness = 0.0;
        $refs = [];
        foreach ($rows as $row) {
            $outcome = strtolower(trim((string) ($row['outcome'] ?? $row['result'] ?? $row['status'] ?? '')));
            if (in_array($outcome, ['delivered', 'success', 'passed'], true)) {
                $success++;
            } elseif (in_array($outcome, ['give_back', 'proxy', 'poison', 'quarantine', 'failed', 'failed_gate'], true)) {
                $adverse++;
            }
            $freshness = max($freshness, $this->freshness($row, $now));
            $ref = trim((string) ($row['outcome_id'] ?? $row['task_packet_id'] ?? $row['receipt_hash'] ?? ''));
            if ($ref !== '') {
                $refs[] = 'outcome:'.hash('sha256', $ref);
            }
        }

        $count = $success + $adverse;
        $successRate = $count > 0 ? round($success / $count, 6) : null;
        $recurrence = 0.0;
        foreach ($keys as $key) {
            $recurrence = max($recurrence, (float) ($recurrenceMap[$key] ?? $recurrenceMap[strstr($key, ':') ?: $key] ?? 0));
        }
        $hasFreshEvidence = $count > 0 && $freshness > 0.0;
        $confidence = $hasFreshEvidence
            ? round(min(1.0, $count / 3) * $freshness * ($successRate ?? 0.0), 6)
            : 0.0;

        return [
            'outcome_count' => $count,
            'outcome_success_rate' => $successRate,
            'outcome_freshness' => round($freshness, 6),
            'outcome_confidence' => $confidence,
            'outcome_gap' => $hasFreshEvidence ? 0 : 1,
            'outcome_signal' => ! $hasFreshEvidence ? 'unknown' : ($adverse > $success ? 'negative' : 'positive'),
            'failure_recurrence' => $adverse,
            'recurrence' => max((int) round($recurrence), count($rows) > 1 ? count($rows) - 1 : 0),
            'evidence_refs' => array_values(array_unique($refs)),
        ];
    }

    /** @param array<string,mixed> $row */
    private function freshness(array $row, DateTimeImmutable $now): float
    {
        $raw = $row['observed_at'] ?? $row['recorded_at'] ?? $row['completed_at'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return 0.0;
        }
        try {
            $ageDays = max(0.0, ((float) $now->format('U') - (float) (new DateTimeImmutable($raw))->format('U')) / 86400);
        } catch (\Throwable) {
            return 0.0;
        }

        return match (true) {
            $ageDays <= 7 => 1.0,
            $ageDays <= 30 => 0.7,
            $ageDays <= 90 => 0.3,
            default => 0.0,
        };
    }
}
