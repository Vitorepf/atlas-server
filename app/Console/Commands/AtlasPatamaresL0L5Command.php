<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasPatamaresL0L5Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Obras — Patamares L0 to L5 maturity-ladder CLI.
 *
 *   php artisan atlas:aaeos:patamares-l0-l5 [--json]
 *
 * Runs the whole-ladder audit over a reference bundle and emits the pass|fail
 * evidence document: the ordered L0..L5 ladder with readiness gates, a monotonic
 * promotion check, an L0 minimum-contract validation, the autonomy gate (A0..A5)
 * and the six L5 non-negotiable sovereign gates. Read-only and deterministic.
 *
 * @see docs/engineering-knowledge-base/obras/patamares-l0-l5.md
 */
class AtlasPatamaresL0L5Command extends Command
{
    protected $signature = 'atlas:aaeos:patamares-l0-l5 {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Obras · audits a bundle against the L0-L5 maturity ladder, promotion rules, L0 contract, A0-A5 autonomy gate and L5 non-negotiable gates.';

    public function handle(AtlasPatamaresL0L5Service $service): int
    {
        try {
            // Safe default: a conformant reference bundle that demonstrates a
            // green audit. Operators can wire real Obra/strategy state later.
            $bundle = [
                'promotion' => [
                    'from' => 'L0',
                    'to' => 'L1',
                    'from_ready' => true,
                ],
                'l0' => [
                    'id' => 'obra-001',
                    'name' => 'Reference Obra',
                    'type' => 'technical',
                    'domain' => 'engineering',
                    'objective' => 'ship validated runtime',
                    'status' => 'In Construction',
                    'deadline' => '2026-12-31',
                    'priority' => 'high',
                    'next_step' => 'render final artifact',
                    'description' => 'reference Obra for the L0 minimum contract',
                ],
                'autonomy' => [
                    'level' => AtlasPatamaresL0L5Service::PERSONAL_AUTONOMY_CEILING,
                    'enterprise' => false,
                    'controls' => [],
                ],
                'sovereign' => [], // no gate violated
            ];

            $result = $service->audit($bundle);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['status'] === AtlasPatamaresL0L5Service::STATUS_PASS
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'patamares_l0_l5_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
