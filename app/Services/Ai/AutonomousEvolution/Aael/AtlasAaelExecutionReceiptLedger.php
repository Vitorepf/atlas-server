<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael;

use App\Support\CanonicalValue;
use Closure;

/**
 * Append-only AAEL execution receipt store. One JSON file per execution under
 * storage/atlas/loop/aael-receipts/<execution_id>.json.
 *
 * Schema: atlas.aael.execution_receipt.v1
 *
 *   {schema_version, execution_id, recorded_at, opportunities_payload_hash,
 *    prover_verdict, runner_result, drift_audit}
 *
 * Contract:
 *   - execution_id = sha256(canonical_json(opportunities_payload) || recorded_at)
 *   - record() is idempotent: identical inputs ⇒ byte-identical file (status=ok); a SECOND write with
 *     the SAME execution_id but DIFFERENT content returns status=conflict and does NOT rewrite.
 *   - storage path unreachable ⇒ status=error (loop output is preserved by the caller).
 *   - list() returns receipts ordered by recorded_at DESC.
 */
final class AtlasAaelExecutionReceiptLedger
{
    public const SCHEMA = 'atlas.aael.execution_receipt.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_ERROR = 'error';

    private const RELATIVE_DIR = 'atlas/loop/aael-receipts';

    private ?Closure $clock = null;

    public function __construct(private readonly ?string $rootOverride = null) {}

    public function setClock(Closure $clock): void
    {
        $this->clock = $clock;
    }

    public function rootPath(): string
    {
        return $this->rootOverride ?? storage_path(self::RELATIVE_DIR);
    }

    /**
     * @param  list<array<string,mixed>>  $opportunitiesPayload
     * @param  array<string,mixed>  $proverVerdict
     * @param  array<string,mixed>  $runnerResult
     * @param  array<string,mixed>  $driftAudit
     * @return array{status:string, execution_id:string, path:?string, error:?string}
     */
    public function record(array $opportunitiesPayload, array $proverVerdict, array $runnerResult, array $driftAudit): array
    {
        $recordedAt = $this->now();
        $payloadJson = $this->canonicalJson($opportunitiesPayload);
        $executionId = hash('sha256', $payloadJson.'|'.$recordedAt);

        $receipt = [
            'schema_version' => self::SCHEMA,
            'execution_id' => $executionId,
            'recorded_at' => $recordedAt,
            'opportunities_payload_hash' => hash('sha256', $payloadJson),
            'prover_verdict' => $proverVerdict,
            'runner_result' => $runnerResult,
            'drift_audit' => $driftAudit,
        ];
        $body = $this->canonicalJson($receipt)."\n";

        $root = $this->rootPath();
        if (! is_dir($root) && ! @mkdir($root, 0o755, true) && ! is_dir($root)) {
            return ['status' => self::STATUS_ERROR, 'execution_id' => $executionId, 'path' => null, 'error' => 'mkdir_failed'];
        }
        if (! is_writable($root)) {
            return ['status' => self::STATUS_ERROR, 'execution_id' => $executionId, 'path' => null, 'error' => 'storage_not_writable'];
        }

        $path = rtrim($root, '/').'/'.$executionId.'.json';
        $lockPath = $path.'.lock';

        // Exclusive lock guards the read-compare-write so two writers for the same
        // execution_id cannot both pass the is_file check and lose idempotency.
        $lockFd = @fopen($lockPath, 'c');
        if ($lockFd === false || ! @flock($lockFd, LOCK_EX | LOCK_NB)) {
            if (is_resource($lockFd)) {
                fclose($lockFd);
            }

            return ['status' => self::STATUS_ERROR, 'execution_id' => $executionId, 'path' => null, 'error' => 'lock_unavailable'];
        }

        try {
            if (is_file($path)) {
                $existing = (string) @file_get_contents($path);
                if ($existing === $body) {
                    return ['status' => self::STATUS_OK, 'execution_id' => $executionId, 'path' => $path, 'error' => null];
                }

                return ['status' => self::STATUS_CONFLICT, 'execution_id' => $executionId, 'path' => $path, 'error' => 'execution_id_collision_with_mutated_content'];
            }

            $written = @file_put_contents($path, $body, LOCK_EX);
            if ($written === false) {
                return ['status' => self::STATUS_ERROR, 'execution_id' => $executionId, 'path' => null, 'error' => 'write_failed'];
            }

            return ['status' => self::STATUS_OK, 'execution_id' => $executionId, 'path' => $path, 'error' => null];
        } finally {
            flock($lockFd, LOCK_UN);
            fclose($lockFd);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function list(): array
    {
        $root = $this->rootPath();
        if (! is_dir($root)) {
            return [];
        }
        $rows = [];
        foreach ((array) glob(rtrim($root, '/').'/*.json') as $path) {
            $body = (string) @file_get_contents((string) $path);
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        usort($rows, static function (array $a, array $b): int {
            $ta = (string) ($a['recorded_at'] ?? '');
            $tb = (string) ($b['recorded_at'] ?? '');

            return strcmp($tb, $ta) ?: strcmp((string) ($b['execution_id'] ?? ''), (string) ($a['execution_id'] ?? ''));
        });

        return $rows;
    }

    private function now(): string
    {
        if ($this->clock !== null) {
            return (string) ($this->clock)();
        }

        return function_exists('now') ? (string) now('UTC')->toIso8601String() : '1970-01-01T00:00:00+00:00';
    }

    private function canonicalJson(mixed $value): string
    {
        return (string) json_encode(CanonicalValue::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

}
