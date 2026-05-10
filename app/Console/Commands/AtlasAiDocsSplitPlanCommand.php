<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Architecture\AtlasDocumentationSplitPlanService;
use Illuminate\Console\Command;

class AtlasAiDocsSplitPlanCommand extends Command
{
    protected $signature = 'atlas:ai:docs-split-plan
        {--owner= : Filter by owner_area, for example kernel_architecture or memory_open_brain}
        {--severity= : Filter by severity, for example critical, high, medium, legacy_critical}
        {--status= : Filter by split status, for example split_required or split_required_grandfathered}
        {--json : Print machine-readable JSON}';

    protected $description = 'Return the canonical split plan for oversized Atlas AI documentation.';

    public function handle(AtlasDocumentationSplitPlanService $splitPlan): int
    {
        $payload = $splitPlan->plan([
            'owner' => $this->option('owner'),
            'severity' => $this->option('severity'),
            'status' => $this->option('status'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Docs Split Plan</>', $payload['status']);
        $this->components->twoColumnDetail('Split required', (string) $payload['split_required_count']);
        $this->components->twoColumnDetail('Total docs with debt', (string) $payload['total_split_required_count']);
        $this->components->twoColumnDetail('Blocking', (string) $payload['blocking_count']);
        $this->components->twoColumnDetail('Grandfathered', (string) $payload['grandfathered_count']);
        $this->components->twoColumnDetail('Ready for new docs', data_get($payload, 'summary.ready_for_new_docs') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Next', (string) data_get($payload, 'recommended_actions.0', 'review_docs_split_plan'));
        $this->components->twoColumnDetail('Filters', collect($payload['filters'])
            ->filter()
            ->map(fn (string $value, string $key): string => "{$key}={$value}")
            ->implode(', ') ?: 'none');

        $this->newLine();
        $this->table(
            ['path', 'severity', 'owner', 'lines', 'limit', 'first child doc'],
            collect($payload['docs'])->take(12)->map(fn (array $doc): array => [
                $doc['path'],
                $doc['severity'] ?? '-',
                $doc['owner_area'] ?? '-',
                (string) $doc['line_count'],
                (string) $doc['limit'],
                data_get($doc, 'proposed_child_docs.0', '-'),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
