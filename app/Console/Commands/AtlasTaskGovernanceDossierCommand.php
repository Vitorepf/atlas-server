<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Governance\AtlasTaskGovernanceObserveDossier;
use Illuminate\Console\Command;

/**
 * STRICTLY READ-ONLY reporter. Compiles the observe-mode governance verdict ledger into an
 * arming dossier so the operator can decide whether to flip enforce mode — this command never
 * mutates governance mode, config, or any ledger.
 */
final class AtlasTaskGovernanceDossierCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:task:governance-dossier
        {--since= : Only include verdicts decided at or after this ISO-8601 timestamp}
        {--json : Emit machine-readable JSON (default output format)}';

    /** @var string */
    protected $description = 'Read-only governance observe-mode dossier: what enforce mode WOULD have blocked, and the arming recommendation per risk level.';

    public function handle(): int
    {
        $since = trim((string) $this->option('since'));
        $ledgerPath = storage_path('atlas/governance/verification-court-verdict-ledger.jsonl');

        $dossier = (new AtlasTaskGovernanceObserveDossier($ledgerPath))->compile($since);

        $this->line((string) json_encode(
            ['status' => 'ok'] + $dossier,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        ));

        return self::SUCCESS;
    }
}
