<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Trinity\MaestroToLoop;

use InvalidArgumentException;

/**
 * TRINITY MAESTRO→LOOP — FUEL RECEIPT LEDGER. Append-only on-disk JSONL journal of every step on the
 * Maestro→Loop fuel-consumption edge. The {@see AtlasLoopMaestroFuelGateInjector} reads this to decide
 * blocked/consumed; downstream tools (morning-digest, evolution-report) audit it.
 *
 * STEPS (whitelisted):
 *   - `digest_recorded`     : Maestro outcome digested into the loop's material substrate
 *   - `giveback_seeded`     : a give-back materialised into a negative-target seed for origination
 *   - `cancelled_mined`     : cancelled-task cluster mined for re-origination fuel
 *   - `origination_consumed`: an origination tick consumed the listed packet_ids
 *
 * INVARIANTS:
 *   - APPEND-ONLY at the API level: no `update()` / `delete()` / `truncate()` method exists.
 *   - IDEMPOTENT on the composite key `(step, packet_id)`: a second record() with the same key is a no-op.
 *   - Whitelist enforced: any step outside the 4 allowed values throws InvalidArgumentException.
 */
final class AtlasLoopMaestroFuelReceiptLedger
{
    public const STEP_DIGEST_RECORDED = 'digest_recorded';

    public const STEP_GIVEBACK_SEEDED = 'giveback_seeded';

    public const STEP_CANCELLED_MINED = 'cancelled_mined';

    public const STEP_ORIGINATION_CONSUMED = 'origination_consumed';

    public const ALLOWED_STEPS = [
        self::STEP_DIGEST_RECORDED,
        self::STEP_GIVEBACK_SEEDED,
        self::STEP_CANCELLED_MINED,
        self::STEP_ORIGINATION_CONSUMED,
    ];

    private ?string $storageRoot = null;

    public function __construct(?string $storageRoot = null)
    {
        $this->storageRoot = $storageRoot;
    }

    public function setStorageRootForTesting(?string $path): void
    {
        $this->storageRoot = $path === null ? null : rtrim($path, '/');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function record(string $step, string $packetId, array $payload = []): bool
    {
        if (! in_array($step, self::ALLOWED_STEPS, true)) {
            throw new InvalidArgumentException('Unknown fuel-receipt step: '.$step.' (allowed: '.implode(', ', self::ALLOWED_STEPS).')');
        }
        if (trim($packetId) === '') {
            throw new InvalidArgumentException('Fuel receipt packet_id must not be empty');
        }

        if ($this->present($step, $packetId)) {
            return false; // idempotent — already recorded
        }

        $line = [
            'recorded_at_unix' => time(),
            'step' => $step,
            'packet_id' => $packetId,
            'payload' => $payload,
        ];

        $path = $this->ledgerPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($path, json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);

        return true;
    }

    /**
     * @return list<array<string,mixed>>  all receipts in insertion order
     */
    public function all(): array
    {
        $path = $this->ledgerPath();
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    public function present(string $step, string $packetId): bool
    {
        foreach ($this->all() as $r) {
            if ((string) ($r['step'] ?? '') === $step && (string) ($r['packet_id'] ?? '') === $packetId) {
                return true;
            }
        }

        return false;
    }

    public function count(?string $step = null, ?string $packetId = null): int
    {
        $n = 0;
        foreach ($this->all() as $r) {
            if ($step !== null && (string) ($r['step'] ?? '') !== $step) {
                continue;
            }
            if ($packetId !== null && (string) ($r['packet_id'] ?? '') !== $packetId) {
                continue;
            }
            $n++;
        }

        return $n;
    }

    public function ledgerPath(): string
    {
        if ($this->storageRoot !== null && $this->storageRoot !== '') {
            return $this->storageRoot.'/fuel-receipts.jsonl';
        }
        if (function_exists('storage_path')) {
            try {
                return (string) storage_path('app/atlas/trinity/maestro-to-loop/fuel-receipts.jsonl');
            } catch (\Throwable) {
            }
        }

        return sys_get_temp_dir().'/atlas-trinity-maestro-fuel-receipts.jsonl';
    }
}
