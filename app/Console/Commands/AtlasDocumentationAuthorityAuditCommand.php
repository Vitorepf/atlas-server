<?php

namespace App\Console\Commands;

use App\Services\Engineering\EngineeringDocumentationAuthorityAuditService;
use Illuminate\Console\Command;

class AtlasDocumentationAuthorityAuditCommand extends Command
{
    protected $signature = 'atlas:ai:docs-authority-audit
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero when blockers exist}';

    protected $description = 'Audit canonical docs for duplicate authority, runtime names and capability overlap.';

    public function handle(EngineeringDocumentationAuthorityAuditService $service): int
    {
        $payload = $service->report();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return (bool) $this->option('strict') && $payload['status'] === 'blocked'
                ? self::FAILURE
                : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Docs Authority Audit', (string) $payload['status']);
        $this->components->twoColumnDetail('Canonical docs', (string) data_get($payload, 'summary.canonical_doc_count', 0));
        $this->components->twoColumnDetail('Blockers', (string) data_get($payload, 'summary.blocker_count', 0));
        $this->components->twoColumnDetail('Review items', (string) data_get($payload, 'summary.review_item_count', 0));
        $this->components->twoColumnDetail('Capability clusters', (string) data_get($payload, 'summary.capability_overlap_group_count', 0));

        if ($payload['blockers'] !== []) {
            $this->newLine();
            $this->warn('Blocking duplicate authority found.');
            $this->table(
                ['reason', 'key', 'paths'],
                collect($payload['blockers'])->take(10)->map(static fn (array $blocker): array => [
                    $blocker['reason'] ?? '',
                    $blocker['key'] ?? '',
                    implode("\n", (array) ($blocker['paths'] ?? [])),
                ])->all(),
            );
        }

        return (bool) $this->option('strict') && $payload['status'] === 'blocked'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
