<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Services\Engineering\AtlasDocumentationRealityCodeContractProposerService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * L1-P2 (third increment) — read-only doc-AHEAD-of-code CODE-CONTRACT PROPOSER
 * command. Completes the P2 reconciliation triangle (over-claim + under-claim +
 * this). Prints, per runtime-claiming doc that NAMED code (evidence_refs) the index
 * cannot resolve, a CONTRACT to build that code: a signature DESCRIPTION per missing
 * symbol/command/route, a given/when/then test OUTLINE per missing test, and a
 * produced-by-real-run note per missing receipt.
 *
 * It NEVER mutates anything: it never generates or writes runnable code, never writes
 * any file, never executes any command, never auto-applies. Each item is is_code=false
 * and must_be_implemented_by_human=true. Auto-discovered from app/Console/Commands.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-code-contract-proposals.md
 */
class AtlasDocumentationRealityCodeContractProposalsCommand extends Command
{
    use EmitsCanonicalJson;

    use ReadsNonEmptyStringOption;

    protected $signature = 'atlas:documentation-reality-code-contract-proposals
        {--capability= : Restrict to one owner doc (id/slug or path substring)}
        {--json : Emit canonical JSON}';

    protected $description = 'Read-only P2 doc-ahead-of-code contract proposer: when a doc NAMED code (evidence_refs) the index cannot resolve, propose the contract (signature + test outline) so a human makes the code catch up. Never generates code, never writes, never auto-applies.';

    public function handle(AtlasDocumentationRealityCodeContractProposerService $proposer): int
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

        if (($payload['degraded'] ?? false) === true) {
            $this->warn('Contracts withheld: '.(string) ($payload['degraded_reason'] ?? 'degraded').'. An empty code-intelligence index makes every declared ref look unresolved; re-run index-code first.');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('docs with code-ahead gaps', (string) data_get($payload, 'summary.docs_with_gaps', 0));
        $this->components->twoColumnDetail('total unresolved declared refs', (string) data_get($payload, 'summary.total_unresolved_refs', 0));
        $this->components->twoColumnDetail('generates code / writes / auto-applies', 'false / false / false');

        $proposals = (array) ($payload['proposals'] ?? []);
        if ($proposals === []) {
            $this->info('No doc-ahead-of-code gaps: every runtime-claiming doc that names code already resolves it.');

            return self::SUCCESS;
        }

        foreach ($proposals as $proposal) {
            $this->newLine();
            $this->line('<info>'.Str::limit((string) ($proposal['owner_doc'] ?? ''), 70).'</info> ('.(string) ($proposal['claimed_state'] ?? '').'): the doc NAMED code the index cannot resolve');
            $this->table(
                ['kind', 'declared ref', 'contract (description, never code)', 'is_code', 'by human'],
                collect((array) ($proposal['contract_items'] ?? []))->map(static fn (array $item): array => [
                    (string) ($item['kind'] ?? ''),
                    Str::limit((string) ($item['ref'] ?? ''), 36),
                    Str::limit((string) ($item['contract'] ?? ''), 64),
                    ($item['is_code'] ?? null) === false ? 'false' : 'TRUE?!',
                    ($item['must_be_implemented_by_human'] ?? null) === true ? 'yes' : 'NO',
                ])->all(),
            );
        }

        $this->newLine();
        $this->warn(data_get($payload, 'summary.docs_with_gaps', 0).' doc(s) ahead of code. CONTRACTS only — never code, never written, never auto-applied. A human implements each (symbol -> command/route -> test -> receipt) through the existing gates + Evidence Ledger.');

        return self::SUCCESS;
    }

}
