<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Foundry\FoundryEvidenceHarvesterService;
use Illuminate\Console\Command;

/**
 * Foundry AP-A · evidence harvest.
 *
 * Thin, read-only entrypoint over FoundryEvidenceHarvesterService::harvest().
 * AP-A INVIOLABLE RULE: harvests real evidence into a dossier; generates
 * NOTHING (zero proposal generation, zero canonical/doc writes, zero
 * production mutation). No git, no merge, no deploy, no secrets.
 */
class AtlasFoundryHarvestCommand extends Command
{
    protected $signature = 'atlas:foundry:harvest {--area=agentic_engineering_os : Area id to harvest} {--limit= : Max cycles to consider} {--json : Emit JSON}';

    protected $description = 'Harvest a read-only Foundry evidence dossier from real anchors (AP-A, generates nothing).';

    public function handle(FoundryEvidenceHarvesterService $harvester): int
    {
        $input = ['area_id' => (string) $this->option('area')];

        $limit = $this->option('limit');
        if ($limit !== null && $limit !== '' && ctype_digit((string) $limit)) {
            $input['limit'] = (int) $limit;
        }

        $dossier = $harvester->harvest($input);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($dossier, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $anchors = (array) ($dossier['anchors'] ?? []);
            $blockers = (array) ($dossier['blockers'] ?? []);
            $this->components->twoColumnDetail('Foundry dossier status', (string) ($dossier['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($dossier['area_id'] ?? 'agentic_engineering_os'));
            $this->components->twoColumnDetail('Anchors', (string) count($anchors));
            $this->components->twoColumnDetail('Blockers', (string) count($blockers));
            $this->components->twoColumnDetail('Dossier hash', (string) ($dossier['dossier_hash'] ?? ''));
        }

        return ($dossier['status'] ?? null) === FoundryEvidenceHarvesterService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
    }
}
