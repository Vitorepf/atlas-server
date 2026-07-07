<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Product\AtlasProductDeliveryOutcomeMemoryService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevOutcomeMemoryService;
use App\Services\Ai\Programming\Forge\Intelligence\ForgeOutcomeMemoryService;
use Illuminate\Console\Command;

/**
 * SLICE 2 (Proof Loop DURO) — `atlas:proof:status`: publishes the REAL fake-green
 * counter across the 3 OutcomeMemory surfaces (Dev + Forge + Product), the precondition
 * instrument for the Learning Loop. A fake-green is a claimed success whose supplied
 * execution evidence is a lie (0 tests, lint-as-suite, fixed-smoke); the OutcomeMemory
 * write-path refuses to promote it into learning and stamps a persisted marker. This
 * counts those markers — measured, never fabricated.
 */
final class AtlasProofStatusCommand extends Command
{
    protected $signature = 'atlas:proof:status {--json : machine-readable output}';

    protected $description = 'Proof Loop · contador de fake-green (Dev+Forge+Product OutcomeMemory), medição real';

    public function handle(): int
    {
        $dev = DevOutcomeMemoryService::fakeGreenSuppressedCount();
        $forge = ForgeOutcomeMemoryService::fakeGreenSuppressedCount();
        $product = AtlasProductDeliveryOutcomeMemoryService::fakeGreenSuppressedCount();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'schema' => 'atlas.proof.status.v1',
                'dev_fake_green_suppressed' => $dev,
                'forge_fake_green_suppressed' => $forge,
                'product_fake_green_suppressed' => $product,
                'total_fake_green_suppressed' => $dev + $forge + $product,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('atlas proof — Proof Loop cross-surface (medição real, não fabricada)');
        $this->line(sprintf('  fake-green suprimidos (não promovidos ao learning): Dev=%d · Forge=%d · Product=%d · total=%d',
            $dev, $forge, $product, $dev + $forge + $product));

        return self::SUCCESS;
    }
}
