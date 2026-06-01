<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * R4 keystone command — computes machine-verified implementation_state for every
 * doc that declares evidence_refs, by resolving each ref against the Code
 * Intelligence index. Emits the capability truth ledger and flags over-claim
 * drift. --strict exits non-zero on any over-claim so the loop / CI can block a
 * doc that claims more than the code proves.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-documentation-as-law-proposal.md
 */
class AtlasAaeosMaturityCommand extends Command
{
    protected $signature = 'atlas:aaeos:maturity
        {--capability= : Restrict to a single doc id/slug or path substring}
        {--coverage : Report corpus-wide doc<->runtime coverage instead of the per-doc ledger}
        {--strict : Exit non-zero when any doc over-claims (claimed rank > computed rank)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Compute machine-verified implementation_state for docs declaring evidence_refs, from the code intelligence index.';

    public function handle(AtlasAaeosImplementationTruthService $truth): int
    {
        if ((bool) $this->option('coverage')) {
            return $this->renderCoverage($truth);
        }

        $ledger = $truth->ledger($this->stringOption('capability'));
        $payload = $ledger + ['generated_at' => now()->toJSON()];

        $driftCount = (int) data_get($ledger, 'summary.drift_count', 0);
        $byComputed = (array) data_get($ledger, 'summary.by_computed_state', []);
        $exit = ((bool) $this->option('strict') && $driftCount > 0) ? self::FAILURE : self::SUCCESS;

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $exit;
        }

        $this->components->twoColumnDetail('evaluated docs', (string) data_get($ledger, 'summary.evaluated', 0));
        $this->components->twoColumnDetail('over-claim drift', (string) $driftCount);
        $this->components->twoColumnDetail('computed spec', (string) ($byComputed['spec'] ?? 0));
        $this->components->twoColumnDetail('computed partial', (string) ($byComputed['partial'] ?? 0));
        $this->components->twoColumnDetail('computed verified', (string) ($byComputed['verified'] ?? 0));

        $capabilities = (array) ($ledger['capabilities'] ?? []);
        if ($capabilities !== []) {
            $this->table(
                ['capability', 'claimed', 'computed', 'drift'],
                collect($capabilities)->map(fn (array $row): array => [
                    Str::limit((string) $row['capability_id'], 46),
                    $row['claimed_state'],
                    $row['computed_state'],
                    $row['drift'] ? 'OVER-CLAIM' : ($row['under_claim'] ? 'under' : '-'),
                ])->all(),
            );
        }

        if ($driftCount > 0) {
            $this->warn("{$driftCount} doc(s) over-claim implementation_state vs. the code index. Ship the missing refs or lower the claim.");
        } else {
            $this->info('No over-claim: every evaluated doc is backed by the code index for what it claims.');
        }

        return $exit;
    }

    private function renderCoverage(AtlasAaeosImplementationTruthService $truth): int
    {
        $coverage = $truth->coverage();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($coverage, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('canonical docs', (string) $coverage['total_canonical_docs']);
        $this->components->twoColumnDetail('claim runtime', (string) $coverage['claims_runtime']);
        $this->components->twoColumnDetail('verifiably backed (evidence_refs)', (string) $coverage['verifiably_backed']);
        $this->components->twoColumnDetail('unverifiable claims (the gap)', (string) $coverage['unverifiable_claims']);
        $this->components->twoColumnDetail('doc<->runtime coverage', $coverage['coverage_pct'].'%  ('.$coverage['score_out_of_10'].'/10)');

        if ((int) $coverage['unverifiable_claims'] > 0) {
            $this->warn("{$coverage['unverifiable_claims']} doc(s) claim runtime without machine-checkable evidence_refs. Add evidence_refs (or lower the claim) to raise coverage toward 10/10.");
        } else {
            $this->info('Every runtime-claiming doc is machine-verifiable.');
        }

        return self::SUCCESS;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
