<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasDecide\AtlasSelfModelReadModelService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * ASI-13 — `atlas:decide:self-model --category=X --json`
 *
 * Reports the canonical (task_category, route) self-model consumed by the
 * Decide layer with `{basis, n, rate}` per candidate route + gaps folded via
 * `AtlasExternalBrainModelCapabilityGapLedger` (the plan's first Decide
 * caller for that ledger).
 */
final class AtlasDecideSelfModelCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:decide:self-model
        {--category= : task_category to query (required)}
        {--window-days=30 : recall window (days)}
        {--declared-routes=* : provider[:model] route also present in the manifest but with no outcome yet}
        {--json : print machine-readable JSON}';

    protected $description = 'ASI-13 self-model read model consumed by the Decide layer.';

    public function handle(AtlasSelfModelReadModelService $service): int
    {
        $category = trim((string) $this->option('category'));
        if ($category === '') {
            $this->error('Missing --category');

            return self::FAILURE;
        }

        $result = $service->readForTaskCategory($category, [
            'window_days' => (int) $this->option('window-days'),
            'declared_routes' => (array) $this->option('declared-routes'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));

            return self::SUCCESS;
        }

        $summary = (array) ($result['summary'] ?? []);
        $this->info(sprintf(
            'self-model category=%s routes=%d proven=%d inferred=%d declared=%d',
            $category,
            (int) ($summary['total_routes'] ?? 0),
            (int) ($summary['proven_routes'] ?? 0),
            (int) ($summary['inferred_routes'] ?? 0),
            (int) ($summary['declared_routes'] ?? 0),
        ));

        return self::SUCCESS;
    }
}
