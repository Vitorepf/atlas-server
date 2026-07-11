<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeWorker;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use RuntimeException;

/**
 * Append-only evidence writer for Atlas-native worker attempts. The worker's self-report is NOT the
 * final evidence — this writer persists a canonical, audited row per attempt so files / commands /
 * gates / residual_risks survive crashes and replay.
 *
 * INVARIANTS:
 *   - REJECTS: missing task_packet_id, envelope_hash, files_changed, commands_run, missing
 *     tests_or_gates_result, unacknowledged scope_deviations, non Atlas-native runtime_owner.
 *   - APPEND-ONLY: kernel JsonlReceiptStore (flock LOCK_EX); existing rows are NEVER overwritten or edited.
 *   - IDEMPOTENT: an attempt with a (task_packet_id, envelope_hash) tuple already present is NOT
 *     re-written; status=already_recorded is returned and the file stays byte-identical.
 *   - DETERMINISTIC: row content_hash = sha256 over canonical fields.
 *   - OPTIONAL OUTCOME FIELDS: outcome, give_back_reason, commit_reference and implementation_notes
 *     are preserved verbatim in the row ONLY when present in the attempt; absent fields are simply
 *     omitted rather than written as empty placeholders.
 *   - REDACTION: a raw provider_transcript is NEVER persisted — only provider_transcript_hash and
 *     provider_transcript_redacted=true are stored. Every free-text field (give_back_reason,
 *     implementation_notes, residual_risks, and each commands_run command string) is scanned for
 *     secret-shaped substrings (API keys, bearer tokens, "secret="/"token="/"api_key=" pairs) and
 *     those substrings are replaced with [REDACTED] before the row is written.
 */
final class AtlasNativeWorkerEvidenceWriter
{
    public const SCHEMA = 'atlas.native_worker.evidence.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_ALREADY = 'already_recorded';

    private const SECRET_PATTERN = '/(sk-[A-Za-z0-9_-]{8,}|ghp_[A-Za-z0-9]{20,}|api[_-]?key\s*[:=]\s*\S+|secret\s*[:=]\s*\S+|token\s*[:=]\s*\S+|Bearer\s+[A-Za-z0-9._-]+)/i';

    /** @var null|callable():string */
    private $clock;

    public function __construct(private readonly string $ledgerPath, ?callable $clock = null)
    {
        $this->clock = $clock;
    }

    /**
     * @param  array{
     *     task_packet_id:string,
     *     envelope_hash:string,
     *     runtime_owner?:string,
     *     files_changed?:list<string>,
     *     file_diffs?:array<string,string>,
     *     commands_run?:list<array<string,mixed>>,
     *     tests_or_gates_result?:array<string,mixed>,
     *     scope_deviations?:list<array{path:string, acknowledged:bool, reason?:string}>,
     *     residual_risks?:list<string>
     * }  $attempt
     * @return array{status:string, row?:array<string,mixed>, reason?:string}
     */
    public function append(array $attempt): array
    {
        $taskId = (string) ($attempt['task_packet_id'] ?? '');
        $envHash = (string) ($attempt['envelope_hash'] ?? '');
        $runtimeOwner = (string) ($attempt['runtime_owner'] ?? '');
        $filesChanged = is_array($attempt['files_changed'] ?? null) ? array_values(array_map('strval', $attempt['files_changed'])) : null;
        $fileDiffs = is_array($attempt['file_diffs'] ?? null) ? $attempt['file_diffs'] : null;
        $commandsRun = is_array($attempt['commands_run'] ?? null) ? array_values($attempt['commands_run']) : null;
        $gateResult = is_array($attempt['tests_or_gates_result'] ?? null) ? $attempt['tests_or_gates_result'] : null;
        $scopeDevs = is_array($attempt['scope_deviations'] ?? null) ? array_values($attempt['scope_deviations']) : [];
        $residualRisks = is_array($attempt['residual_risks'] ?? null) ? array_values(array_map('strval', $attempt['residual_risks'])) : [];

        if ($taskId === '') {
            throw new RuntimeException('evidence writer: missing task_packet_id');
        }
        if ($envHash === '') {
            throw new RuntimeException('evidence writer: missing envelope_hash');
        }
        if ($runtimeOwner !== AtlasNativeWorkerExecutionEnvelopeBuilder::RUNTIME_OWNER) {
            throw new RuntimeException('evidence writer: non Atlas-native runtime_owner: '.($runtimeOwner === '' ? 'missing' : $runtimeOwner));
        }
        if ($filesChanged === null) {
            throw new RuntimeException('evidence writer: missing files_changed');
        }
        if ($filesChanged === []) {
            throw new RuntimeException('evidence writer: files_changed must not be empty');
        }
        if ($commandsRun === null) {
            throw new RuntimeException('evidence writer: missing commands_run');
        }
        if ($commandsRun === []) {
            throw new RuntimeException('evidence writer: commands_run must not be empty');
        }
        $hasArtisan = false;
        foreach ($commandsRun as $cmd) {
            if (! is_array($cmd) || (string) ($cmd['command'] ?? '') === '') {
                throw new RuntimeException('evidence writer: command row missing required command string');
            }
            if (! array_key_exists('exit_code', $cmd) || ! is_int($cmd['exit_code'])) {
                throw new RuntimeException('evidence writer: command row missing required exit_code integer');
            }
            if (str_contains((string) $cmd['command'], 'php artisan')) {
                $hasArtisan = true;
            }
        }
        if (! $hasArtisan) {
            throw new RuntimeException('evidence writer: no runnable php artisan proof command in commands_run');
        }
        // Validate diffs after proof commands so a missing real command can never
        // be obscured by a later evidence field. Both remain mandatory.
        if ($fileDiffs === null || $fileDiffs === []) {
            throw new RuntimeException('evidence writer: file_diffs must not be empty when files_changed is non-empty');
        }
        $fileDiffsKeys = array_keys($fileDiffs);
        $missingDiffs = array_values(array_diff($filesChanged, $fileDiffsKeys));
        if ($missingDiffs !== []) {
            throw new RuntimeException('evidence writer: files_changed without corresponding diff: '.implode(', ', $missingDiffs));
        }
        if ($gateResult === null || ! array_key_exists('passed', $gateResult)) {
            throw new RuntimeException('evidence writer: missing tests_or_gates_result.passed');
        }
        if (! ($gateResult['passed'] ?? false)) {
            throw new RuntimeException('evidence writer: tests_or_gates_result.passed must be true');
        }
        foreach ($scopeDevs as $dev) {
            if (! is_array($dev) || empty($dev['acknowledged'])) {
                throw new RuntimeException('evidence writer: unacknowledged scope deviation: '.(string) ($dev['path'] ?? '?'));
            }
        }

        // AC4: redact secret-shaped substrings out of every free-text field before persisting.
        $redactedCommandsRun = array_map(function (array $cmd): array {
            $cmd['command'] = $this->redactSecrets((string) $cmd['command']);

            return $cmd;
        }, $commandsRun);
        $redactedResidualRisks = array_map(fn (string $r): string => $this->redactSecrets($r), $residualRisks);

        $row = [
            'schema' => self::SCHEMA,
            'recorded_at' => $this->now(),
            'task_packet_id' => $taskId,
            'envelope_hash' => $envHash,
            'runtime_owner' => $runtimeOwner,
            'files_changed' => $filesChanged,
            'file_diffs' => $fileDiffs,
            'commands_run' => $redactedCommandsRun,
            'tests_or_gates_result' => $gateResult,
            'scope_deviations' => $scopeDevs,
            'residual_risks' => $redactedResidualRisks,
        ];

        // Compute diff_hash over the canonicalised sorted file_diffs map.
        ksort($fileDiffs, SORT_STRING);
        $row['diff_hash'] = hash('sha256', (string) json_encode($fileDiffs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // AC3: preserve outcome, give_back_reason, commit_reference and implementation_notes
        // ONLY when present -- absent optional fields are simply omitted, never defaulted.
        if (array_key_exists('outcome', $attempt)) {
            $row['outcome'] = (string) $attempt['outcome'];
        }
        if (array_key_exists('give_back_reason', $attempt)) {
            $row['give_back_reason'] = $this->redactSecrets((string) $attempt['give_back_reason']);
        }
        if (array_key_exists('commit_reference', $attempt)) {
            $row['commit_reference'] = (string) $attempt['commit_reference'];
        }
        if (array_key_exists('implementation_notes', $attempt)) {
            $row['implementation_notes'] = $this->redactSecrets((string) $attempt['implementation_notes']);
        }

        // AC4: a raw provider transcript is NEVER persisted -- only its hash and a redaction flag.
        if (array_key_exists('provider_transcript', $attempt)) {
            $row['provider_transcript_hash'] = hash('sha256', (string) $attempt['provider_transcript']);
            $row['provider_transcript_redacted'] = true;
        }

        $canonical = $row;
        ksort($canonical);
        $row['content_hash'] = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Idempotency check on (task_packet_id, envelope_hash) runs INSIDE the store's write lock.
        $written = $this->store()->appendWith(
            fn (?string $lastLine): ?array => $this->alreadyRecorded($taskId, $envHash) ? null : $row,
        );

        return $written === null ? ['status' => self::STATUS_ALREADY] : ['status' => self::STATUS_OK, 'row' => $row];
    }

    private function store(): JsonlReceiptStore
    {
        return new JsonlReceiptStore($this->ledgerPath);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->store()->replay();
    }

    private function redactSecrets(string $value): string
    {
        return (string) preg_replace(self::SECRET_PATTERN, '[REDACTED]', $value);
    }

    private function now(): string
    {
        return is_callable($this->clock) ? (string) ($this->clock)() : gmdate(DATE_ATOM);
    }

    private function alreadyRecorded(string $taskId, string $envHash): bool
    {
        foreach ($this->all() as $r) {
            if ((string) ($r['task_packet_id'] ?? '') === $taskId
                && (string) ($r['envelope_hash'] ?? '') === $envHash) {
                return true;
            }
        }

        return false;
    }
}
