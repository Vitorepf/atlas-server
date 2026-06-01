<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasPolicyProfileModelService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Atlas AI Resolver Corpus Policy Profile Model.
 *
 * @see docs/engineering-knowledge-base/resolver-corpus/policy-profile-model.md
 */
final class AtlasPolicyProfileModelCommand extends Command
{
    protected $signature = 'atlas:aaeos:policy-profile-model {--json : Machine-readable JSON output}';

    protected $description = 'Show the Atlas resolver-corpus Policy Profile Model: layering, flow resolution and the audited-override invariant.';

    public function handle(AtlasPolicyProfileModelService $service): int
    {
        try {
            $result = $service->model();
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
