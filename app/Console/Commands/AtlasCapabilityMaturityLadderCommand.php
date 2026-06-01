<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCapabilityMaturityLadderService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Capability Maturity Ladder decider CLI.
 *
 *   php artisan atlas:aaeos:capability-maturity-ladder [--json]
 *
 * Read-only and deterministic. Classifies a capability against the documented
 * L0..L8 ladder using its evidence proofs. With safe defaults (a capability
 * that only has its canonical doc + owner — proof for L1 only) it demonstrates
 * the contract: the capability reaches exactly L1 (Documented), is NOT
 * complete, and the next promotion that blocks it (L1 -> L2, needing AP/spec)
 * is named.
 *
 * @see docs/engineering-knowledge-base/self-construction/capability-maturity-ladder.md
 */
class AtlasCapabilityMaturityLadderCommand extends Command
{
    protected $signature = 'atlas:aaeos:capability-maturity-ladder {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Self-Construction capability maturity classifier (L0..L8) with promotion-proof gating.';

    public function handle(AtlasCapabilityMaturityLadderService $service): int
    {
        try {
            // Safe default: a freshly governed capability that has only a
            // canonical doc + owner. That is exactly the L1 proof and nothing
            // beyond, so it must classify as L1 (Documented) and name L1 -> L2
            // as the blocking promotion.
            $decision = $service->classify([
                'capability' => 'capability-maturity-ladder',
                'proofs' => [
                    'canonical_doc_and_owner' => true,
                ],
            ]);

            $this->line((string) json_encode(
                [
                    'ok' => true,
                    'decision' => $decision,
                    'target_framing' => $service->targetFraming(),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'capability_maturity_ladder_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
