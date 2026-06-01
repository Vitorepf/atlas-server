<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasWorldModelRtService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas World Model runtime CLI.
 *
 *   php artisan atlas:aaeos:world-model-rt [--json]
 *
 * Read-only and deterministic. Builds a world snapshot from a small, safe
 * default set of world entities + edges and emits the snapshot decision: the
 * closed entity/edge taxonomy, valid counts, source count and the four quality
 * gates. The default set deliberately includes a sourceless entity and an
 * inference-only causal edge so the output demonstrates the governance refusals
 * (the snapshot is blocked until every quality gate passes).
 *
 * @see docs/engineering-knowledge-base/atlas-world-model.md
 */
class AtlasWorldModelRtCommand extends Command
{
    protected $signature = 'atlas:aaeos:world-model-rt {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas world model · build a freshness/confidence-gated digital-world snapshot from entities + edges.';

    public function handle(AtlasWorldModelRtService $service): int
    {
        try {
            $decision = $service->buildSnapshot([
                'entities' => [
                    [
                        'type' => 'plataforma',
                        'name' => 'example-marketplace',
                        'source' => 'official-platform-docs',
                        'date' => '2026-05-20',
                        'age_days' => 12,
                        'claim_kind' => 'fact',
                        'confidence' => 0.9,
                    ],
                    [
                        'type' => 'oportunidade',
                        'name' => 'underserved-niche',
                        'source' => 'research-runtime',
                        'date' => '2026-05-25',
                        'age_days' => 7,
                        'claim_kind' => 'estimate',
                        'confidence' => 0.6,
                    ],
                    [
                        // Intentionally sourceless -> demonstrates the
                        // "Fonte fraca vira fato" refusal and fails a gate.
                        'type' => 'mercado',
                        'name' => 'rumored-trend',
                        'claim_kind' => 'inference',
                    ],
                ],
                'edges' => [
                    [
                        'type' => 'integrates_with',
                        'from' => 'example-marketplace',
                        'to' => 'payments-provider',
                        'source' => 'official-platform-docs',
                        'date' => '2026-05-20',
                        'claim_kind' => 'fact',
                    ],
                    [
                        // Inference-only causal edge -> refused by the
                        // "Nao transformar correlacao em causalidade" rule.
                        'type' => 'creates_opportunity',
                        'from' => 'underserved-niche',
                        'to' => 'new-product-line',
                        'source' => 'analyst-note',
                        'date' => '2026-05-25',
                        'claim_kind' => 'inference',
                    ],
                ],
            ]);

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'world_model_rt_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
