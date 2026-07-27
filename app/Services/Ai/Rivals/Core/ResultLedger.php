<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;
use RuntimeException;

/**
 * Ledger append-only com hash chain: entry_hash = sha256(prev_hash + payload
 * canônico). Corrupção de qualquer linha quebra a chain na verificação.
 * ponytail: flock single-writer local; fila multi-processo só se precisar.
 */
class ResultLedger
{
    public function append(string $runId, array $verdict, array $context = []): array
    {
        $path = RunPaths::ledgerPath();
        RunPaths::ensureDir(dirname($path));

        $handle = fopen($path, 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('rivals_ledger_lock_failed');
        }

        try {
            $prevHash = 'genesis';
            $lastLine = null;
            $entries = [];
            while (($line = fgets($handle)) !== false) {
                if (trim($line) !== '') {
                    $lastLine = $line;
                    $decoded = json_decode($line, true);
                    if (is_array($decoded)) {
                        $entries[] = $decoded;
                    }
                }
            }
            if ($lastLine !== null) {
                $prevHash = (json_decode($lastLine, true) ?? [])['entry_hash'] ?? 'genesis';
            }

            $state = (new RunStateMachine)->current($runId);
            $revision = (int) ($context['revision'] ?? $state['revision'] ?? 1);
            $entryType = (string) ($context['entry_type'] ?? 'adjudication');
            $verdictHash = self::hashPayload($verdict);
            $evidenceHash = self::fileHash(RunPaths::evidencePath($runId));
            $adjudicationHash = self::fileHash(RunPaths::adjudicationPath($runId));
            $reportHash = self::fileHash(RunPaths::reportPath($runId));
            $logicalId = hash('sha256', implode('|', [
                $runId,
                (string) $revision,
                $entryType,
                $verdictHash,
                $evidenceHash ?? 'none',
                $adjudicationHash ?? 'none',
                $reportHash ?? 'none',
            ]));
            foreach ($entries as $existing) {
                if (($existing['logical_id'] ?? null) === $logicalId) {
                    return $existing + ['idempotent_replay' => true];
                }
            }
            $latestForRun = null;
            foreach (array_reverse($entries) as $existing) {
                if (($existing['run_id'] ?? null) === $runId) {
                    $latestForRun = $existing;
                    break;
                }
            }
            $entry = [
                'schema_version' => SchemaContract::LEDGER_ENTRY,
                'entry_id' => 'le_'.bin2hex(random_bytes(8)),
                'logical_id' => $logicalId,
                'entry_type' => $entryType,
                'prev_hash' => $prevHash,
                'run_id' => $runId,
                'revision' => $revision,
                'verdict' => $verdict,
                'verdict_hash' => $verdictHash,
                'evidence_pack_hash' => $evidenceHash,
                'adjudication_hash' => $adjudicationHash,
                'report_hash' => $reportHash,
                'supersedes_entry_id' => $context['supersedes_entry_id']
                    ?? $latestForRun['entry_id']
                    ?? null,
                'appended_at' => now()->toIso8601String(),
            ];
            $violations = SchemaContract::validate($entry, SchemaContract::LEDGER_ENTRY);
            if ($violations !== []) {
                throw new RuntimeException('rivals_invalid_ledger_entry:'.implode(',', $violations));
            }
            $entry['entry_hash'] = self::hashEntry($entry);

            fseek($handle, 0, SEEK_END);
            fwrite($handle, json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL);
            fflush($handle);
            if (function_exists('fsync')) {
                fsync($handle);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $entry;
    }

    public function appendReport(string $runId, array $report): array
    {
        return $this->append($runId, [
            'verdict' => ($report['pipeline_valid'] ?? false) ? 'valid' : 'invalid',
            'pipeline_valid' => (bool) ($report['pipeline_valid'] ?? false),
            'internal_claim_allowed' => (bool) ($report['internal_claim_allowed'] ?? false),
            'public_claim_allowed' => (bool) ($report['public_claim_allowed'] ?? false),
            'not_ready_reasons' => $report['not_ready_reasons'] ?? [],
        ], ['entry_type' => 'report']);
    }

    public function appendBundle(string $runId, array $bundle): array
    {
        return $this->append($runId, [
            'verdict' => 'bundled',
            'bundle_hash' => $bundle['bundle_hash'] ?? null,
            'files' => count((array) ($bundle['files'] ?? [])),
        ], ['entry_type' => 'bundle']);
    }

    public function supersede(string $runId, string $reason): array
    {
        return $this->append($runId, [
            'verdict' => 'superseded',
            'reason' => $reason,
        ], ['entry_type' => 'supersede']);
    }

    /** @return array{verified: bool, evidence_present: bool, entries: int, failures: list<string>} */
    public function verifyChain(): array
    {
        $path = RunPaths::ledgerPath();
        if (! is_file($path)) {
            // `verified` stays true here on purpose: a fresh install has no
            // ledger yet and `atlas:rivals doctor` is right to call that healthy.
            // What was missing is the distinction between "nothing to verify" and
            // "verified evidence" — without it, `rm` on the ledger (or
            // quarantineCorruptEpoch truncating it after real corruption) read as
            // intact tamper-evidence to anyone gating on `verified` alone.
            return ['verified' => true, 'evidence_present' => false, 'entries' => 0, 'failures' => []];
        }

        $failures = [];
        $prevHash = 'genesis';
        $count = 0;
        foreach (array_filter(explode(PHP_EOL, file_get_contents($path))) as $index => $line) {
            $entry = json_decode($line, true);
            if (! is_array($entry)) {
                $failures[] = "line_unparseable:{$index}";

                continue;
            }
            $count++;
            if (($entry['prev_hash'] ?? null) !== $prevHash) {
                $failures[] = "chain_broken_prev_hash:line_{$index}";
            }
            $recorded = $entry['entry_hash'] ?? '';
            $schema = $entry['schema_version'] ?? null;
            unset($entry['entry_hash']);
            $computed = $schema === SchemaContract::LEDGER_ENTRY_V1
                ? self::hashLegacyEntry($entry)
                : self::hashEntry($entry);
            if ($computed !== $recorded) {
                $failures[] = "entry_hash_mismatch:line_{$index}";
            }
            $prevHash = $recorded;
        }

        return ['verified' => $failures === [], 'evidence_present' => $count > 0, 'entries' => $count, 'failures' => $failures];
    }

    /** @return array{verified: bool, entries: int, current_runs: int, legacy_entries: int, failures: list<string>} */
    public function verifySemantic(): array
    {
        $chain = $this->verifyChain();
        $failures = $chain['failures'];
        $latest = [];
        $legacy = 0;
        foreach ($this->entries() as $entry) {
            if (($entry['schema_version'] ?? null) === SchemaContract::LEDGER_ENTRY_V1) {
                $legacy++;

                continue;
            }
            $runId = (string) ($entry['run_id'] ?? '');
            $latest[$runId] = $entry;
            if (($entry['verdict_hash'] ?? null) !== self::hashPayload((array) ($entry['verdict'] ?? []))) {
                $failures[] = 'semantic_verdict_hash_mismatch:'.($entry['entry_id'] ?? 'unknown');
            }
        }
        $entryIds = array_column($this->entries(), null, 'entry_id');
        foreach ($latest as $runId => $entry) {
            $supersedes = $entry['supersedes_entry_id'] ?? null;
            if ($supersedes !== null && ! isset($entryIds[$supersedes])) {
                $failures[] = "semantic_supersedes_missing:{$runId}";
            }
            if (($entry['entry_type'] ?? null) === 'supersede') {
                continue;
            }
            foreach ([
                'evidence_pack_hash' => RunPaths::evidencePath($runId),
                'adjudication_hash' => RunPaths::adjudicationPath($runId),
            ] as $field => $path) {
                $recorded = $entry[$field] ?? null;
                if ($recorded !== null && self::fileHash($path) !== $recorded) {
                    $failures[] = "semantic_file_hash_mismatch:{$runId}:{$field}";
                }
            }
            if (($entry['report_hash'] ?? null) !== null) {
                $recorded = $entry['report_hash'] ?? null;
                if ($recorded === null || self::fileHash(RunPaths::reportPath($runId)) !== $recorded) {
                    $failures[] = "semantic_file_hash_mismatch:{$runId}:report_hash";
                }
            }
        }

        return [
            'verified' => $failures === [],
            'entries' => $chain['entries'],
            'current_runs' => count($latest),
            'legacy_entries' => $legacy,
            'failures' => array_values(array_unique($failures)),
        ];
    }

    public function tail(int $lines = 10): array
    {
        $path = RunPaths::ledgerPath();
        if (! is_file($path)) {
            return [];
        }
        $all = array_values(array_filter(explode(PHP_EOL, file_get_contents($path))));

        return array_map(fn ($l) => json_decode($l, true), array_slice($all, -$lines));
    }

    /** @return list<array<string, mixed>> */
    public function entries(): array
    {
        $path = RunPaths::ledgerPath();
        if (! is_file($path)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $line): mixed => json_decode($line, true),
            array_filter(explode(PHP_EOL, (string) file_get_contents($path))),
        ), 'is_array'));
    }

    private static function hashEntry(array $entryWithoutHash): string
    {
        return self::hashPayload($entryWithoutHash);
    }

    private static function hashLegacyEntry(array $entryWithoutHash): string
    {
        ksort($entryWithoutHash);

        return hash('sha256', json_encode($entryWithoutHash, JSON_UNESCAPED_SLASHES));
    }

    private static function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode(
            self::canonicalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonicalize(...), $value);
    }

    private static function fileHash(string $path): ?string
    {
        return is_file($path) ? hash_file('sha256', $path) : null;
    }

    /**
     * Re-bind ledger semantic hashes to on-disk adjudication/report after rebuild.
     *
     * @return array<string, mixed>
     */
    public function repairSemanticForRun(string $runId): array
    {
        $this->supersede($runId, 'ledger_repair_semantic');
        $adjPath = RunPaths::adjudicationPath($runId);
        $reportPath = RunPaths::reportPath($runId);
        $appended = [];
        if (is_file($adjPath)) {
            $adj = json_decode((string) file_get_contents($adjPath), true) ?? [];
            $appended['adjudication'] = $this->append($runId, $adj, ['entry_type' => 'adjudication']);
        }
        if (is_file($reportPath)) {
            $report = json_decode((string) file_get_contents($reportPath), true) ?? [];
            $appended['report'] = $this->appendReport($runId, $report);
        }
        $verify = $this->verifySemantic();

        return [
            'schema_version' => 'atlas.rivals2.ledger_repair.v1',
            'run_id' => $runId,
            'status' => ($verify['verified'] ?? false) ? 'ok' : 'error',
            'appended' => array_keys($appended),
            'verify' => $verify,
        ];
    }

    /**
     * Quarantine corrupted ledger chain and start a fresh epoch (does not rewrite bytes).
     *
     * @return array<string, mixed>
     */
    public function quarantineCorruptEpoch(): array
    {
        $path = RunPaths::ledgerPath();
        if (! is_file($path)) {
            return [
                'schema_version' => 'atlas.rivals2.ledger_quarantine.v1',
                'status' => 'ok',
                'reason' => 'ledger_absent',
            ];
        }
        $chain = $this->verifyChain();
        if (($chain['verified'] ?? false) === true) {
            return [
                'schema_version' => 'atlas.rivals2.ledger_quarantine.v1',
                'status' => 'ok',
                'reason' => 'chain_already_valid',
                'entries' => $chain['entries'] ?? 0,
            ];
        }
        $stamp = gmdate('Ymd_His');
        $quarantine = dirname($path).'/ledger.quarantine.'.$stamp.'.jsonl';
        if (! rename($path, $quarantine)) {
            throw new RuntimeException('rivals_ledger_quarantine_rename_failed');
        }
        file_put_contents($path, '');
        $meta = [
            'schema_version' => 'atlas.rivals2.ledger_epoch.v1',
            'quarantined_at' => now()->toIso8601String(),
            'quarantine_path' => $quarantine,
            'failures' => $chain['failures'] ?? [],
            'previous_entries' => $chain['entries'] ?? 0,
        ];
        file_put_contents(
            dirname($path).'/ledger.epoch.'.$stamp.'.json',
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        return [
            'schema_version' => 'atlas.rivals2.ledger_quarantine.v1',
            'status' => 'ok',
            'quarantine_path' => $quarantine,
            'new_ledger' => $path,
            'previous_failures' => $chain['failures'] ?? [],
        ];
    }
}
