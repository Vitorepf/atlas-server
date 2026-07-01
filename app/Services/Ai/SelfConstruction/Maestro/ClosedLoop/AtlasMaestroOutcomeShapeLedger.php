<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ClosedLoop;

use DomainException;
use Generator;
use Throwable;

final class AtlasMaestroOutcomeShapeLedger
{
    public const SCHEMA = 'atlas.maestro.closed_loop.outcome_shape_ledger.v1';

    private const OUTCOMES = ['delivered', 'give_back', 'rejected', 'stale', 'quarantine'];

    private const PROOF_RESULTS = ['passed', 'failed'];

    private const GIVE_BACK_ROOT_CAUSES = [
        'malformed_spec',
        'forbidden_scope',
        'failing_gate',
        'duplicate_capability',
        'transient_concurrency',
    ];

    public function __construct(private readonly ?string $path = null)
    {
    }

    /**
     * @param  array<string,mixed>  $shapeFacts
     */
    public function record(string $taskPacketId, array $shapeFacts, string $outcome): void
    {
        $taskPacketId = trim($taskPacketId);
        if ($taskPacketId === '') {
            throw new DomainException('task_packet_id_required');
        }
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new DomainException('invalid_maestro_outcome');
        }

        $giveBackRootCause = $this->normalizeRootCause($shapeFacts['give_back_root_cause'] ?? null);

        $shape = [
            'origin_kind' => $this->originKind($shapeFacts['origin_kind'] ?? null),
            'allowed_files_count' => max(0, (int) ($shapeFacts['allowed_files_count'] ?? 0)),
            'scope_in_size' => max(0, (int) ($shapeFacts['scope_in_size'] ?? 0)),
            'acceptance_criteria_count' => max(0, (int) ($shapeFacts['acceptance_criteria_count'] ?? 0)),
            'required_evidence_count' => max(0, (int) ($shapeFacts['required_evidence_count'] ?? 0)),
            'has_tests_path' => (bool) ($shapeFacts['has_tests_path'] ?? false),
            'wave_bucket' => (string) ($shapeFacts['wave_bucket'] ?? 'unknown'),
            'give_back_root_cause' => $giveBackRootCause,
            // AC2: task family, worker class, proof result and poison signal — bounded
            // categorical/boolean facts only, never a raw transcript.
            'file_family' => $this->normalizeBoundedString($shapeFacts['file_family'] ?? null),
            'task_shape' => $this->normalizeBoundedString($shapeFacts['task_shape'] ?? null),
            'worker_id' => $this->normalizeBoundedString($shapeFacts['worker_id'] ?? null),
            'proof_command_class' => $this->normalizeBoundedString($shapeFacts['proof_command_class'] ?? null),
            'proof_result' => $this->normalizeProofResult($shapeFacts['proof_result'] ?? null),
            'poison_signal' => (bool) ($shapeFacts['poison_signal'] ?? false),
        ];

        $entry = array_merge(
            ['schema' => self::SCHEMA, 'task_packet_id' => $taskPacketId],
            $shape,
            ['outcome' => $outcome, 'shape_hash' => hash('sha256', (string) json_encode($shape))],
        );

        $this->appendIdempotent($entry);
    }

    /**
     * @return Generator<int,array<string,mixed>>
     */
    public function stream(): Generator
    {
        $path = $this->ledgerPath();
        if (! is_file($path)) {
            return;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $row = json_decode($line, true);
                if (is_array($row)) {
                    yield $row;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function appendIdempotent(array $entry): void
    {
        $path = $this->ledgerPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        $lockPath = $path.'.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new DomainException('maestro_shape_ledger_lock_open_failed');
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new DomainException('maestro_shape_ledger_lock_failed');
            }

            $lines = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
            foreach ($lines as $line) {
                $row = json_decode($line, true);
                if (is_array($row)
                    && ($row['task_packet_id'] ?? null) === $entry['task_packet_id']
                    && ($row['outcome'] ?? null) === $entry['outcome']
                    && ($row['shape_hash'] ?? null) === $entry['shape_hash']) {
                    return;
                }
            }

            $lines[] = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $tmp = $path.'.tmp.'.bin2hex(random_bytes(4));
            file_put_contents($tmp, implode(PHP_EOL, $lines).PHP_EOL, LOCK_EX);
            rename($tmp, $path);
        } catch (Throwable $exception) {
            throw $exception instanceof DomainException
                ? $exception
                : new DomainException('maestro_shape_ledger_append_failed', previous: $exception);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function normalizeRootCause(mixed $rootCause): string
    {
        return in_array($rootCause, self::GIVE_BACK_ROOT_CAUSES, true) ? (string) $rootCause : 'unknown';
    }

    private function originKind(mixed $originKind): string
    {
        return in_array($originKind, ['orphan', 'doc_gap'], true) ? (string) $originKind : 'orphan';
    }

    /** Bounded categorical label — trimmed and length-capped so a caller can never smuggle a raw transcript in. */
    private function normalizeBoundedString(mixed $value): string
    {
        $clean = trim((string) $value);

        return $clean === '' ? 'unknown' : substr($clean, 0, 80);
    }

    private function normalizeProofResult(mixed $proofResult): string
    {
        return in_array($proofResult, self::PROOF_RESULTS, true) ? (string) $proofResult : 'unknown';
    }

    /**
     * AC4: aggregate success/give_back/quarantine/false-green-risk counts per task family
     * (file_family), for closed-loop routing and respec decisions.
     *
     * @return array<string, array{success_count:int, give_back_count:int, quarantine_count:int, false_green_risk_count:int, total:int}>
     */
    public function aggregateByTaskFamily(): array
    {
        $aggregate = [];
        foreach ($this->stream() as $row) {
            $family = (string) ($row['file_family'] ?? 'unknown');
            $aggregate[$family] ??= [
                'success_count' => 0,
                'give_back_count' => 0,
                'quarantine_count' => 0,
                'false_green_risk_count' => 0,
                'total' => 0,
            ];

            $outcome = (string) ($row['outcome'] ?? '');
            if ($outcome === 'delivered') {
                $aggregate[$family]['success_count']++;
            }
            if ($outcome === 'give_back') {
                $aggregate[$family]['give_back_count']++;
            }
            if ($outcome === 'quarantine') {
                $aggregate[$family]['quarantine_count']++;
            }
            if ((bool) ($row['poison_signal'] ?? false)) {
                $aggregate[$family]['false_green_risk_count']++;
            }
            $aggregate[$family]['total']++;
        }

        ksort($aggregate);

        return $aggregate;
    }

    private function ledgerPath(): string
    {
        return $this->path ?? storage_path('atlas/loop/maestro/closed-loop/shape-ledger.jsonl');
    }
}
