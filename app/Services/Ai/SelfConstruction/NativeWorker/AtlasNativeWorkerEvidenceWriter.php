<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeWorker;


use App\Services\Ai\SelfConstruction\Support\UsesUtcClock;
use RuntimeException;

/**
 * Append-only evidence writer for Atlas-native worker attempts. The worker's self-report is NOT the
 * final evidence — this writer persists a canonical, audited row per attempt so files / commands /
 * gates / residual_risks survive crashes and replay.
 *
 * INVARIANTS:
 *   - REJECTS: missing task_packet_id, envelope_hash, files_changed, commands_run, missing
 *     tests_or_gates_result, unacknowledged scope_deviations, non Atlas-native runtime_owner.
 *   - APPEND-ONLY: fopen('a') + flock(LOCK_EX); existing rows are NEVER overwritten or edited.
 *   - IDEMPOTENT: an attempt with a (task_packet_id, envelope_hash) tuple already present is NOT
 *     re-written; status=already_recorded is returned and the file stays byte-identical.
 *   - DETERMINISTIC: row content_hash = sha256 over canonical fields.
 */
final class AtlasNativeWorkerEvidenceWriter
{
    use UsesUtcClock;

    public const SCHEMA = 'atlas.native_worker.evidence.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_ALREADY = 'already_recorded';

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
        if ($commandsRun === null) {
            throw new RuntimeException('evidence writer: missing commands_run');
        }
        if ($gateResult === null || ! array_key_exists('passed', $gateResult)) {
            throw new RuntimeException('evidence writer: missing tests_or_gates_result.passed');
        }
        foreach ($scopeDevs as $dev) {
            if (! is_array($dev) || empty($dev['acknowledged'])) {
                throw new RuntimeException('evidence writer: unacknowledged scope deviation: '.(string) ($dev['path'] ?? '?'));
            }
        }

        if ($this->alreadyRecorded($taskId, $envHash)) {
            return ['status' => self::STATUS_ALREADY];
        }

        $row = [
            'schema' => self::SCHEMA,
            'recorded_at' => $this->now(),
            'task_packet_id' => $taskId,
            'envelope_hash' => $envHash,
            'runtime_owner' => $runtimeOwner,
            'files_changed' => $filesChanged,
            'commands_run' => $commandsRun,
            'tests_or_gates_result' => $gateResult,
            'scope_deviations' => $scopeDevs,
            'residual_risks' => $residualRisks,
        ];
        $canonical = $row;
        ksort($canonical);
        $row['content_hash'] = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

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
            throw new RuntimeException('evidence writer cannot open '.$this->ledgerPath);
        }
        try {
            if (! flock($fh, LOCK_EX)) {
                throw new RuntimeException('evidence writer cannot acquire LOCK_EX');
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
