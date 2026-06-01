<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasThesisMultiplierChannelService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Multiplier & Sovereign Channel thesis decider.
 *
 * Demonstrates the thesis contracts on safe defaults: the multiplier formula
 * (full stack vs pure pass-through), the canal-unico loop (through Atlas vs
 * direct provider), the feature filter (build / convert_to_adapter /
 * fix_before_shipping / measure_before_expanding) and the allowed/blocked
 * category lists.
 *
 * @see docs/engineering-knowledge-base/thesis/multiplier-channel.md
 */
final class AtlasThesisMultiplierChannelCommand extends Command
{
    protected $signature = 'atlas:aaeos:thesis-multiplier-channel {--json : Machine-readable JSON output}';

    protected $description = 'Decide Multiplier & Sovereign Channel thesis rules: multiplier formula, canal-unico loop, feature filter decisions and allowed/blocked categories.';

    public function handle(AtlasThesisMultiplierChannelService $service): int
    {
        try {
            $result = $service->snapshot();
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
