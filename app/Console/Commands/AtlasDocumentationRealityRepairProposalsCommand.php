<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Services\Engineering\AtlasDocumentationRealityRepairProposerService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * L1-P2 (first increment) — read-only reconciliation repair PROPOSER command.
 *
 * Prints conservative, doc-side repair proposals for every over-claim drift the
 * maturity ledger detects. It NEVER mutates anything: no file write, no command
 * exec, no auto-apply. Every proposal offers only doc-side options (downgrade the
 * claim, or supply evidence_refs) and explicitly never proposes code change or
 * deletion. Auto-discovered from app/Console/Commands like atlas:documentation-reality.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-generative-self-healing.md
 */
class AtlasDocumentationRealityRepairProposalsCommand extends Command
{
    use EmitsCanonicalJson;

    use ReadsNonEmptyStringOption;

    protected $signature = 'atlas:documentation-reality-repair-proposals
        {--capability= : Restrict to one owner doc (id/slug or path substring)}
        {--json : Emit canonical JSON}';

    protected $description = 'Read-only P2 reconciliation repair proposer: conservative doc-side repair proposals for detected doc<->code drift. Never applies, never touches code.';

    public function handle(AtlasDocumentationRealityRepairProposerService $proposer): int
    {
        $capability = $this->stringOption('capability');
        $payload = ($capability !== null
            ? $proposer->proposeForDoc($capability)
            : $proposer->proposeAll())
            + ['generated_at' => now()->toJSON()];

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('drift detected', (string) data_get($payload, 'summary.drift_count', 0));
        $this->components->twoColumnDetail('repair proposals', (string) data_get($payload, 'summary.proposal_count', 0));
        $this->components->twoColumnDetail('writes / auto-applies', 'false / false');

        $proposals = (array) ($payload['proposals'] ?? []);
        if ($proposals === []) {
            $this->info('No over-claim drift: nothing to reconcile. Every evaluated doc matches the code index.');

            return self::SUCCESS;
        }

        $this->table(
            ['owner doc', 'claimed', 'computed', 'recommended', 'options'],
            collect($proposals)->map(static fn (array $p): array => [
                Str::limit((string) ($p['owner_doc'] ?? ''), 54),
                (string) ($p['claimed_state'] ?? ''),
                (string) ($p['computed_state'] ?? ''),
                (string) ($p['recommended'] ?? ''),
                implode(' | ', array_map(
                    static fn (array $o): string => (string) ($o['kind'] ?? ''),
                    (array) ($p['repair_options'] ?? []),
                )),
            ])->all(),
        );

        $this->warn(data_get($payload, 'summary.proposal_count', 0).' doc-side repair proposal(s). These are PROPOSALS only — never auto-applied, never touching code. Route through the existing gates + Evidence Ledger to apply.');

        return self::SUCCESS;
    }

}
