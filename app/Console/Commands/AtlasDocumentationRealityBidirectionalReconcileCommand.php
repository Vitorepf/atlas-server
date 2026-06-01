<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasDocumentationRealityBidirectionalReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * L1-P2 (second increment) — read-only BIDIRECTIONAL doc<->code reconciliation
 * command. Prints, in one packet, BOTH directions of doc-side reconciliation:
 *   - over_claim_repairs (delegated to the repair proposer): the doc claims more
 *     than the code proves -> downgrade / supply evidence.
 *   - under_claim_upgrades (derived here): the code proves more than the doc claims
 *     -> upgrade the doc's implementation_state to match reality.
 *
 * It NEVER mutates anything: no file write, no command exec, no auto-apply, and it
 * NEVER generates or touches code in either direction. Auto-discovered from
 * app/Console/Commands like atlas:documentation-reality.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-bidirectional-reconciliation.md
 */
class AtlasDocumentationRealityBidirectionalReconcileCommand extends Command
{
    protected $signature = 'atlas:documentation-reality-bidirectional-reconcile
        {--capability= : Restrict to one owner doc (id/slug or path substring)}
        {--json : Emit canonical JSON}';

    protected $description = 'Read-only P2 bidirectional doc<->code reconciliation: over-claim repairs (delegated) PLUS under-claim doc upgrades, both doc-side. Never applies, never generates code.';

    public function handle(AtlasDocumentationRealityBidirectionalReconciliationService $reconciler): int
    {
        $capability = $this->stringOption('capability');
        $payload = ($capability !== null
            ? $reconciler->reconcileForDoc($capability)
            : $reconciler->reconcileAll())
            + ['generated_at' => now()->toJSON()];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        if (($payload['degraded'] ?? false) === true) {
            $this->warn('Reconciliation withheld: '.(string) ($payload['degraded_reason'] ?? 'degraded').'. An empty code-intelligence index makes every doc look mis-claimed; re-run index-code first.');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('over-claim repairs', (string) data_get($payload, 'summary.over_claim_count', 0));
        $this->components->twoColumnDetail('under-claim upgrades', (string) data_get($payload, 'summary.under_claim_count', 0));
        $this->components->twoColumnDetail('total reconciliations', (string) data_get($payload, 'summary.total_reconciliations', 0));
        $this->components->twoColumnDetail('writes / auto-applies / generates code', 'false / false / false');

        $this->renderUnderClaim((array) ($payload['under_claim_upgrades'] ?? []));
        $this->renderOverClaim((array) ($payload['over_claim_repairs'] ?? []));

        if ((int) data_get($payload, 'summary.total_reconciliations', 0) === 0) {
            $this->info('No drift and no under-claim: doc and code already agree in both directions.');

            return self::SUCCESS;
        }

        $this->warn(data_get($payload, 'summary.total_reconciliations', 0).' doc-side reconciliation(s). These are PROPOSALS only — never auto-applied, never generating or touching code. Route through the existing gates + Evidence Ledger to apply.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int,array<string,mixed>>  $upgrades
     */
    private function renderUnderClaim(array $upgrades): void
    {
        if ($upgrades === []) {
            return;
        }

        $this->newLine();
        $this->line('<info>under-claim doc upgrades</info> (code proves more than the doc claims):');
        $this->table(
            ['owner doc', 'claimed', 'computed', 'recommended', 'option'],
            collect($upgrades)->map(static fn (array $u): array => [
                Str::limit((string) ($u['owner_doc'] ?? ''), 54),
                (string) ($u['claimed_state'] ?? ''),
                (string) ($u['computed_state'] ?? ''),
                (string) ($u['recommended'] ?? ''),
                implode(' | ', array_map(
                    static fn (array $o): string => (string) ($o['kind'] ?? ''),
                    (array) ($u['repair_options'] ?? []),
                )),
            ])->all(),
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $repairs
     */
    private function renderOverClaim(array $repairs): void
    {
        if ($repairs === []) {
            return;
        }

        $this->newLine();
        $this->line('<info>over-claim repairs</info> (doc claims more than the code proves):');
        $this->table(
            ['owner doc', 'claimed', 'computed', 'recommended', 'options'],
            collect($repairs)->map(static fn (array $p): array => [
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
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
