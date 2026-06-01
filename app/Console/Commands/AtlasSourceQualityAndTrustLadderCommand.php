<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSourceQualityAndTrustLadderService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Source Quality And Trust Ladder decider CLI.
 *
 *   php artisan atlas:aaeos:source-quality-and-trust-ladder
 *     [--tier=4]                 // classify this tier + show its promotion right
 *     [--json]
 *
 * Read-only, deterministic. Emits the tier classification, the tier's allowed
 * promotion, and a demonstration of the anti-hallucination gate verdict.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/source-quality-and-trust-ladder.md
 */
class AtlasSourceQualityAndTrustLadderCommand extends Command
{
    protected $signature = 'atlas:aaeos:source-quality-and-trust-ladder
        {--tier= : explicit trust tier 0..5 to classify (default 4 = community lead)}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas research · source quality & trust ladder decider (tier, promotion right, anti-hallucination gate).';

    public function handle(AtlasSourceQualityAndTrustLadderService $service): int
    {
        try {
            $tierOpt = $this->option('tier');
            $tier = is_string($tierOpt) && ctype_digit($tierOpt) ? (int) $tierOpt : 4;

            $classification = $service->classifyTier(['tier' => $tier]);
            $promotion = $service->promotionFor($tier);

            // Demonstrate the anti-hallucination gate on a deliberately failing
            // packet (invented URL + LLM-as-proof) so the receipt shows the
            // lead-only disposition that the doc mandates.
            $gateDemo = $service->evaluatePacket([
                'source_url_invented' => true,
                'source_type_known' => true,
                'claim_has_source_pointer' => true,
                'llm_text_as_proof' => true,
            ]);

            $this->line((string) json_encode([
                'ok' => true,
                'classification' => $classification,
                'promotion' => $promotion,
                'anti_hallucination_gate_demo' => $gateDemo,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'source_quality_and_trust_ladder_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
