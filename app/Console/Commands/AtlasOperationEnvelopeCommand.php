<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasOperationEnvelopeService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operation Envelope admission gate CLI.
 *
 *   php artisan atlas:aaeos:operation-envelope
 *     [--candidate='{"trace":"t-1", ...}']
 *     [--phase=execute]
 *     [--json]
 *
 * Read-only, deterministic. With --candidate it decides whether the candidate
 * operation context carries a complete, traceable envelope (and, with --phase,
 * whether that phase may proceed). Without --candidate it emits the required
 * envelope contract manifest.
 *
 * @see docs/engineering-knowledge-base/system-graph/operation-envelope.md
 */
class AtlasOperationEnvelopeCommand extends Command
{
    protected $signature = 'atlas:aaeos:operation-envelope
        {--candidate= : JSON candidate operation/envelope context to admit}
        {--phase= : phase to guard (route|plan|execute)}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Kernel · Operation Envelope admission gate (sem envelope nao ha execucao; trace e bloqueio) + contract manifest.';

    public function handle(AtlasOperationEnvelopeService $service): int
    {
        try {
            $candidateRaw = $this->option('candidate');
            $phase = $this->option('phase');

            if (is_string($candidateRaw) && trim($candidateRaw) !== '') {
                $decoded = json_decode($candidateRaw, true);
                $candidate = is_array($decoded) ? $decoded : [];

                if (is_string($phase) && trim($phase) !== '') {
                    $payload = [
                        'ok' => true,
                        'guard' => $service->guardPhase($candidate, $phase),
                    ];
                } else {
                    $payload = [
                        'ok' => true,
                        'admission' => $service->admit($candidate),
                    ];
                }
            } else {
                $payload = [
                    'ok' => true,
                    'manifest' => $service->manifest(),
                ];
            }

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'operation_envelope_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
