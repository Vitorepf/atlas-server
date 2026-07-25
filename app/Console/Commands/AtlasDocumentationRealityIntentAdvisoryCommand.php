<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasDocumentationRealityIntentAdvisoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * L2-O2 (first increment) — read-only Intent ADVISORY command.
 *
 * Asks, BEFORE the build: "is this the RIGHT thing to build for your objective, or
 * is there something of higher leverage?" — and answers ONLY with an opinion +
 * leverage questions, deferring to the operator. It wraps the L1-P1 simulate()
 * technical signal with an intent layer. Auto-discovered from app/Console/Commands.
 *
 * THIS IS ADVICE, NOT A GATE. It ALWAYS exits 0 (success) regardless of the
 * recommendation — blocking would make it a decision, which the contract forbids.
 * It mutates nothing.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-intent-coformation.md
 */
final class AtlasDocumentationRealityIntentAdvisoryCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:documentation-reality-intent-advisory
        {--kind=doc : Proposed artifact kind (doc|symbol)}
        {--slug= : Proposed doc slug}
        {--graph-id= : Proposed doc graph_id}
        {--capability=* : Proposed capability (repeatable)}
        {--symbol= : Proposed symbol name (kind=symbol)}
        {--objective= : The operator objective to weigh leverage against (optional)}
        {--json : Emit canonical JSON}';

    protected $description = 'Read-only L2-O2 intent advisory: before building, surface considerations + leverage questions about a proposed spec (duplication, drift, owner, fit-to-objective) for the operator to weigh — ADVISORY + human-gated, never a decision, never overrides the operator; the judgment is the operator\'s. Always exits 0. Writes nothing.';

    public function handle(AtlasDocumentationRealityIntentAdvisoryService $advisor): int
    {
        $objective = $this->str($this->option('objective')) ?? '';

        $payload = $advisor->adviseProposal($this->proposal(), $objective)
            + ['generated_at' => now()->toJSON()];

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            // ADVISORY, never a gate: always succeed.
            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Intent Advisory (L2-O2)', 'ADVISORY OPINION — the operator decides');
        $this->components->twoColumnDetail('advisory recommendation', (string) data_get($payload, 'advisory_recommendation.value', ''));
        $this->components->twoColumnDetail('is a decision', data_get($payload, 'advisory_recommendation.is_a_decision') ? 'yes' : 'NO (advisory only)');
        $this->components->twoColumnDetail('never overrides operator', YesNo::trueFalse(data_get($payload, 'sovereignty.never_overrides_operator')));
        $this->components->twoColumnDetail('technical signal verdict', (string) data_get($payload, 'technical_signal.verdict', ''));
        $this->components->twoColumnDetail('objective stated', YesNo::format(data_get($payload, 'proposal.objective_stated')));
        $this->components->twoColumnDetail('writes / auto-acts', 'false / false');

        $this->newLine();
        $this->line('<comment>Considerations (for you to weigh — not instructions):</comment>');
        foreach ((array) ($payload['considerations'] ?? []) as $consideration) {
            $this->line('  - '.(string) ($consideration['consideration'] ?? ''));
        }

        $this->newLine();
        $this->line('<comment>Leverage questions (the operator answers these):</comment>');
        foreach ((array) ($payload['leverage_questions'] ?? []) as $question) {
            $this->line('  ? '.Str::of((string) $question)->trim());
        }

        $this->newLine();
        $this->line('<info>'.(string) data_get($payload, 'advisory_recommendation.rationale', '').'</info>');
        $this->warn('This is ADVICE, not a gate or a decision. You decide what gets built.');

        // ADVISORY, never a gate: always succeed regardless of the recommendation.
        return self::SUCCESS;
    }

    /**
     * Build the proposed-artifact payload from the discrete flags, in the same shape
     * P1 simulate() takes.
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
        ], static fn ($value): bool => $value !== '' && $value !== []);
    }

    private function str(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
