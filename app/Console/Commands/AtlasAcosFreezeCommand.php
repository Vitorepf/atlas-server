<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use Illuminate\Console\Command;
use Throwable;

final class AtlasAcosFreezeCommand extends Command
{
    public const SCHEMA_VERSION = 'atlas.acos.measure_freeze.v1';

    public const JSONL_RELATIVE_PATH = 'app/atlas/evidence/acos-measure-freeze.jsonl';

    protected $signature = 'atlas:acos:freeze
        {--json= : Measure-freeze payload JSON; omitted uses the MAXG-01 default freeze payload}';

    protected $description = 'Record an ACOS measure_freeze in the Evidence Ledger with independent judge assertion.';

    public function handle(): int
    {
        $payload = $this->payloadFromOption();
        if ($payload === null) {
            return $this->emit(['ok' => false, 'reason' => 'invalid_json_payload'], self::FAILURE);
        }

        $author = trim((string) ($payload['author_engine_id'] ?? ''));
        $judge = trim((string) ($payload['judge_engine_id'] ?? ''));
        $measureId = trim((string) ($payload['measure_id'] ?? ''));

        if (($payload['kind'] ?? null) !== 'measure_freeze' || $measureId === '' || $author === '' || $judge === '') {
            return $this->emit(['ok' => false, 'reason' => 'invalid_measure_freeze_payload'], self::FAILURE);
        }
        if ($author === $judge) {
            return $this->emit(['ok' => false, 'reason' => 'judge_engine_id_must_differ_from_author_engine_id'], self::FAILURE);
        }

        // JSONL is the durable freeze surface when Postgres availability checks hang
        // (Schema::hasTable can block without throwing). Always check/write JSONL first.
        if ($this->jsonlAlreadyFrozen($measureId)) {
            return $this->emit([
                'ok' => true,
                'status' => 'already_frozen',
                'measure_id' => $measureId,
                'storage' => 'jsonl',
                'jsonl_path' => storage_path(self::JSONL_RELATIVE_PATH),
            ], self::SUCCESS);
        }

        $payload['schema_version'] = self::SCHEMA_VERSION;
        $payload['event_name'] = 'measure.freeze.recorded';
        $payload['recorded_at'] = now()->toIso8601String();
        $payload['judge_author_distinct'] = true;
        $payload['content_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // JSONL-first: Postgres Schema::hasTable can hang without throwing when the
        // local container is wedged; freeze must still seal. DB mirror is optional later.
        $jsonlPath = storage_path(self::JSONL_RELATIVE_PATH);
        @mkdir(dirname($jsonlPath), 0775, true);
        (new JsonlReceiptStore($jsonlPath))->append($payload);

        $dualReadExit = $this->recordDualRead($measureId, $payload);

        return $this->emit([
            'ok' => true,
            'status' => 'frozen',
            'measure_id' => $measureId,
            'event_id' => null,
            'storage' => 'jsonl',
            'content_hash' => $payload['content_hash'],
            'jsonl_path' => $jsonlPath,
            'dual_read_exit_code' => $dualReadExit,
        ], self::SUCCESS);
    }

    private function jsonlAlreadyFrozen(string $measureId): bool
    {
        $path = storage_path(self::JSONL_RELATIVE_PATH);
        if (! is_file($path)) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $row = json_decode(trim($line), true);
                if (! is_array($row)) {
                    continue;
                }
                if (($row['measure_id'] ?? null) === $measureId && ($row['kind'] ?? null) === 'measure_freeze') {
                    return true;
                }
            }
        } finally {
            fclose($handle);
        }

        return false;
    }

    /** @return array<string,mixed> */
    public static function defaultFreezePayload(): array
    {
        return [
            'kind' => 'measure_freeze',
            'measure_id' => 'aobg.latency_ledger.v1',
            'formula' => 'p50/p95 wall_ms per op from daily JSONL; ops∈{pack,recall,hook}',
            'thresholds' => [
                'pack_p95_ms_alert' => 18000,
                'recall_p95_ms_alert' => 15000,
                'hook_p95_ms_alert' => 20000,
                'denominator_min_samples' => 5,
            ],
            'denominator_min' => 5,
            'ttl_days' => 90,
            'author_engine_id' => 'cursor-grok-acos-max',
            'judge_engine_id' => 'codex-external-judge',
            'baseline_manual_2026_07_11' => [
                'turn_ms' => 65000,
                'hooks_ms' => [15180, 8110, 5220],
                'pack_hotspot_ms_range' => [13000, 18000],
                'basis' => 'manual_stopwatch_degraded',
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function payloadFromOption(): ?array
    {
        $raw = $this->option('json');
        if (! is_string($raw) || trim($raw) === '') {
            return self::defaultFreezePayload();
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $payload */
    private function recordDualRead(string $measureId, array $payload): int
    {
        try {
            $thresholds = (array) ($payload['thresholds'] ?? []);
            $dual = [
                'schema_version' => AtlasMeasureDualReadCommand::SCHEMA_VERSION,
                'medidor' => $measureId,
                'valor_antigo' => 'simulated_arlcg_estimate:degraded_manual',
                'valor_novo' => 'real_latency_ledger:pending_samples_min='.(int) ($payload['denominator_min'] ?? $thresholds['denominator_min_samples'] ?? 5),
                'justificativa' => 'MAXG-01 freeze: degraded simulated ARLCG latency estimate will be dual-read against real AOBG JSONL p50/p95 samples.',
                'commit' => trim((string) @shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse --short HEAD 2>/dev/null')) ?: 'unknown',
                'recorded_at' => now()->toIso8601String(),
            ];
            $path = storage_path(AtlasMeasureDualReadCommand::JSONL_RELATIVE_PATH);
            @mkdir(dirname($path), 0775, true);
            (new JsonlReceiptStore($path))->append($dual);

            return self::SUCCESS;
        } catch (Throwable) {
            return self::FAILURE;
        }
    }

    /** @param array<string,mixed> $payload */
    private function emit(array $payload, int $code): int
    {
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $code;
    }
}
