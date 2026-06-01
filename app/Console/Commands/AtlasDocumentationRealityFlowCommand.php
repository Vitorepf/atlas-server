<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasDocumentationRealityFlowService;
use Illuminate\Console\Command;

/**
 * Read-only demonstration of the documented "Fluxo alvo para IA" of the ADRS
 * generative leap, composed as ONE flow:
 *
 *   tarefa -> P1 predict -> O2 advise -> L0 write-boundary verdict -> P2 reconcile
 *          -> P3 immunise (only if a failure is supplied) -> L-inf reflective note.
 *
 * It calls each ALREADY-BUILT capability and surfaces its REAL output (summarised).
 * It is NOT the enforcement (the L0 pre-commit hook is) and it mutates NOTHING — no
 * file write, no mutating command exec, no auto-action. Auto-discovered from
 * app/Console/Commands. ALWAYS exits 0 (it is a read-only demonstration, never a gate).
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-flow.md
 */
final class AtlasDocumentationRealityFlowCommand extends Command
{
    protected $signature = 'atlas:documentation-reality-flow
        {--kind=doc : Proposed artifact kind (doc|symbol)}
        {--slug= : Proposed doc slug}
        {--graph-id= : Proposed doc graph_id}
        {--capability=* : Proposed capability (repeatable)}
        {--symbol= : Proposed symbol name (kind=symbol)}
        {--objective= : The operator objective (woven into the O2 advisory)}
        {--paths= : Comma-separated repo-relative paths the change would touch (drives the L0 verdict + P2 focus)}
        {--json : Emit canonical JSON}';

    protected $description = 'Read-only demonstration of the documented ADRS "Fluxo alvo para IA": compose P1 predict + O2 advise + L0 write-boundary verdict + P2 reconcile + P3 immunise + L-inf note for a proposed change. Surfaces each already-built capability\'s REAL output; it is NOT the enforcement (the pre-commit hook is) and mutates nothing. Always exits 0.';

    public function handle(AtlasDocumentationRealityFlowService $flow): int
    {
        $payload = $flow->forProposedChange($this->proposal(), $this->touchedPaths())
            + ['generated_at' => now()->toJSON()];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            // Read-only demonstration, never a gate: always succeed.
            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Documented flow', 'Fluxo alvo para IA (read-only composition)');
        $this->components->twoColumnDetail('pre_write: P1 verdict', (string) data_get($payload, 'pre_write.predictive.verdict', 'n/a'));
        $this->components->twoColumnDetail('pre_write: P1 would_duplicate', data_get($payload, 'pre_write.predictive.would_duplicate') ? 'YES' : 'no');
        $this->components->twoColumnDetail('pre_write: O2 recommendation', (string) data_get($payload, 'pre_write.intent_advisory.recommendation', 'n/a'));
        $this->components->twoColumnDetail('write_boundary: enforced via', (string) data_get($payload, 'write_boundary.is_active_via', ''));
        $this->components->twoColumnDetail('write_boundary: L0 decision', (string) data_get($payload, 'write_boundary.enforcement.decision', '(hook is the enforcement)'));
        $this->components->twoColumnDetail('post_write: P2 drift / proposals', sprintf(
            '%s / %s',
            (string) data_get($payload, 'post_write.reconciliation.drift_count', 0),
            (string) data_get($payload, 'post_write.reconciliation.proposal_count', 0),
        ));
        $this->components->twoColumnDetail('post_write: P3 immunization', (string) (data_get($payload, 'post_write.immunization.note')
            ?? ('antibodies: '.(string) data_get($payload, 'post_write.immunization.antibody_count', 0))));
        $this->components->twoColumnDetail('reflective note confidence', (string) data_get($payload, 'reflective_note.headline_confidence', 'n/a'));
        $this->components->twoColumnDetail('writes / composes only', 'false / true');

        $this->newLine();
        $this->line('<info>'.(string) data_get($payload, 'reflective_note.note', '').'</info>');
        $this->warn('This is a READ-ONLY composition of the seven already-built capabilities. It is NOT the enforcement (the L0 pre-commit hook is) and it changes no behavior.');

        // Read-only demonstration, never a gate: always succeed.
        return self::SUCCESS;
    }

    /**
     * Build the proposed-artifact payload from the discrete flags, in the same shape
     * P1 simulate() / O2 adviseProposal() take. The objective rides along so the
     * service can weave it into the O2 advisory.
     *
     * @return array<string,mixed>
     */
    private function proposal(): array
    {
        return array_filter([
            'kind' => (string) ($this->option('kind') ?: 'doc'),
            'slug' => (string) ($this->option('slug') ?: ''),
            'graph_id' => (string) ($this->option('graph-id') ?: ''),
            'capabilities' => array_values((array) $this->option('capability')),
            'symbol_name' => (string) ($this->option('symbol') ?: ''),
            'objective' => (string) ($this->option('objective') ?: ''),
        ], static fn ($value): bool => $value !== '' && $value !== []);
    }

    /**
     * Parse the comma-separated --paths flag into a list of repo-relative touched
     * paths.
     *
     * @return array<int,string>
     */
    private function touchedPaths(): array
    {
        $raw = (string) ($this->option('paths') ?: '');
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $p): string => trim($p),
            explode(',', $raw),
        ), static fn (string $p): bool => $p !== ''));
    }
}
