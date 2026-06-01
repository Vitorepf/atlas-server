<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiObrasOperatingSystemService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Obras Operating System — parent-OS conformance CLI.
 *
 *   php artisan atlas:aaeos:obras-operating-system [--json]
 *
 * Runs the whole-OS audit over a reference Obra bundle and emits the pass|fail
 * evidence document (lifecycle transition, 12 universal quality gates,
 * non-negotiable product law and final-criteria ladder). Read-only and
 * deterministic; it never mutates Obra state or relaxes a gate.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
 */
class AtlasAiObrasOperatingSystemCommand extends Command
{
    protected $signature = 'atlas:aaeos:obras-operating-system {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Obras OS · audits an Obra against the lifecycle state machine, 12 universal quality gates, product law and final-criteria ladder.';

    public function handle(AtlasAiObrasOperatingSystemService $service): int
    {
        try {
            // Safe default: a conformant reference Obra that demonstrates a green
            // audit — a valid gated transition, all quality gates answered, full
            // product-law compliance and a delivery that became a sovereign asset.
            $bundle = [
                'transition' => [
                    'from' => 'In Review',
                    'to' => 'Approved',
                    'evidence' => ['gates' => true],
                ],
                'quality_gates' => array_fill_keys(
                    array_keys(AtlasAiObrasOperatingSystemService::QUALITY_GATES),
                    true,
                ),
                'product_law' => array_fill_keys(
                    array_keys(AtlasAiObrasOperatingSystemService::PRODUCT_LAW),
                    true,
                ),
                'final_criteria' => [
                    'has_delivery' => true,
                    'became_asset' => true,
                    'increased_autonomy' => true,
                ],
                'claims_complete' => true,
            ];

            $result = $service->audit($bundle);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['status'] === AtlasAiObrasOperatingSystemService::STATUS_PASS
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'obras_operating_system_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
