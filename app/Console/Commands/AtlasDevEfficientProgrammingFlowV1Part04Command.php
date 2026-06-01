<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowV1Part04Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Efficient Programming Flow v1 · Parte 4 — operational-summary decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-efficient-programming-flow-v1-part04 [--json]
 *
 * Read-only, deterministic, zero side effect. Exercises the documented §26.4–§27
 * slice with safe defaults: a disabled-flag 503 gate, the unlock-order check, an
 * APP_KEY fail-closed validation, a snapshot-replay-then-close stream check, a
 * path-redaction smoke, the HMAC confirmation-pin verdict and the final rule,
 * then emits the verdicts plus the manifest as JSON.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-04.md
 */
class AtlasDevEfficientProgrammingFlowV1Part04Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-efficient-programming-flow-v1-part04 {--json}';

    protected $description = 'Atlas Dev efficient programming flow (Parte 4) · evaluate flags/503, APP_KEY fail-closed, stream close, path redaction and HMAC pin.';

    public function handle(AtlasDevEfficientProgrammingFlowV1Part04Service $service): int
    {
        try {
            $payload = [
                'ok' => true,
                'manifest' => $service->manifest(),
                'flag_gate_run_disabled' => $service->resolveFlagGate('run', false),
                'unlock_order_run_before_plan' => $service->unlockOrderRespected(false, true, false),
                'app_key_too_short' => $service->validateAppKey('base64:' . base64_encode('short')),
                'app_key_valid' => $service->validateAppKey('base64:' . base64_encode(str_repeat('k', 32))),
                'stream_valid' => $service->validateStreamSequence(['phase:plan', 'receipt:run', 'stream_closed']),
                'stream_invalid_after_close' => $service->validateStreamSequence(['phase:plan', 'stream_closed', 'phase:extra']),
                'redaction_smoke_leak' => $service->redactionSmoke('{"path":"/Users/op/ws"}'),
                'confirmation_pin_mismatch' => $service->confirmationPinVerdict('compact_sdd_hash', true),
                'delivery_state' => $service->deliveryState(),
                'final_rule' => $service->finalRule(),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_flow_v1_part04_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
