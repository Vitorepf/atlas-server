<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;

/**
 * Best-effort kernel evidence emission for Atlas Dev gates.
 *
 * Closes the audit-trail gap where Dev gates produced receipts only on the
 * filesystem while Forge/AiWorker emit kernel ledger events: Verification
 * Court and replay can now see Dev gate outcomes through the same ledger.
 *
 * Service-located on purpose: the gates are `new`-constructed by several
 * pipelines, so constructor injection would only cover callers that opt in.
 * Emission is strictly best-effort — a ledger/DB failure must never break a
 * Dev gate (same contract as AiWorker's ledger writes), and a bare unit test
 * without a container simply skips emission.
 */
final class DevGateLedgerEmitter
{
    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     */
    public static function emit(string $gate, string $status, array $payload = [], array $context = []): void
    {
        try {
            if (! function_exists('app') || ! app()->bound('app')) {
                return;
            }

            $type = match ($status) {
                'passed', 'applied', 'skipped' => LedgerEventType::GatePassed,
                'failed', 'blocked' => LedgerEventType::GateBlocked,
                default => LedgerEventType::GateEvaluated,
            };

            app(AtlasEvidenceLedger::class)->record(
                $type,
                ['gate' => $gate, 'status' => $status] + $payload,
                ['emitter_stage' => 'atlas.dev.'.$gate] + $context,
            );
        } catch (\Throwable) {
            // ponytail: best-effort evidence — never let ledger IO break a Dev gate.
        }
    }
}
