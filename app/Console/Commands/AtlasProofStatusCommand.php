<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevOutcomeMemoryService;
use Illuminate\Console\Command;

/**
 * SLICE 2 (Proof Loop DURO) — `atlas:proof:status`: publishes the REAL fake-green
 * counter, the precondition instrument for the Learning Loop. A fake-green is a
 * claimed success whose supplied execution evidence is a lie (0 tests, lint-as-suite,
 * fixed-smoke); the OutcomeMemory write-path refuses to promote it into learning and
 * stamps a persisted marker. This counts those markers — measured, never fabricated.
 */
final class AtlasProofStatusCommand extends Command
{
    protected $signature = 'atlas:proof:status {--json : machine-readable output}';

    protected $description = 'Proof Loop · fake-green counter (Dev OutcomeMemory suppressions), medição real';

    public function handle(): int
    {
        $devFakeGreen = DevOutcomeMemoryService::fakeGreenSuppressedCount();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'schema' => 'atlas.proof.status.v1',
                'dev_fake_green_suppressed' => $devFakeGreen,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('atlas proof — Proof Loop (medição real, não fabricada)');
        $this->line(sprintf('  Dev OutcomeMemory fake-green suprimidos (não promovidos ao learning): %d', $devFakeGreen));

        return self::SUCCESS;
    }
}
