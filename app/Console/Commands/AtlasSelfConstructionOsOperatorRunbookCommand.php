<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSelfConstructionOsOperatorRunbookService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Self-Construction OS Operator Runbook v1
 * governance: the pre/post macro-sprint checklists, next_required_slice stop
 * signal, runtime_safety_all_false reading, violations-vs-warnings
 * classification, stop-and-ask conditions and the pre-runtime gate.
 *
 * Projection-only. Authorizes no runtime, dispatches no provider.
 *
 * @see docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-operator-runbook-v1.md
 */
final class AtlasSelfConstructionOsOperatorRunbookCommand extends Command
{
    protected $signature = 'atlas:aaeos:self-construction-os-operator-runbook {--json : Machine-readable JSON output}';

    protected $description = 'Show the Self-Construction OS Operator Runbook governance: pre/post sprint gates, next_required_slice stop signal, runtime_safety_all_false reading, violation/warning rules, stop conditions and the pre-runtime gate.';

    public function handle(AtlasSelfConstructionOsOperatorRunbookService $service): int
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
