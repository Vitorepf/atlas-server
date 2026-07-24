<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasDev\Runtime\AtlasDevDesktopAcceptanceEvidenceService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasDevDesktopAcceptanceCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:dev:desktop:acceptance
        {--json : Emit canonical JSON}
        {--strict : Return non-zero when evidence gate is blocked}
        {--min-real-runs=1 : Minimum passed real provider smoke records required}
        {--max-age-hours=168 : Maximum accepted evidence age in hours}';

    protected $description = 'Audit persisted Atlas Dev Desktop real-smoke acceptance evidence without calling a provider.';

    public function handle(AtlasDevDesktopAcceptanceEvidenceService $evidence): int
    {
        $payload = $evidence->inspect(
            minRealRuns: (int) $this->option('min-real-runs'),
            maxAgeHours: (int) $this->option('max-age-hours'),
        );

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);
        } else {
            $this->components->twoColumnDetail('Atlas Dev Desktop acceptance evidence', (string) $payload['status']);
            $this->components->twoColumnDetail('Records', (string) data_get($payload, 'summary.records_found', 0));
            $this->components->twoColumnDetail('Passed records', (string) data_get($payload, 'summary.passed_records', 0));
            if (($payload['remaining_blockers'] ?? []) !== []) {
                $this->warn('Blockers: '.implode(', ', (array) $payload['remaining_blockers']));
            }
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'passed'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
