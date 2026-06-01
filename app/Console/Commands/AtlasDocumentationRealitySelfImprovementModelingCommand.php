<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasDocumentationRealitySelfImprovementModelingService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * L-inf (ONE promoted fragment: R3 self-improving modeling / meta-learning) —
 * read-only, PROPOSAL-ONLY command.
 *
 * Composes R1 (causal self-model) + R2 (epistemic humility), reads the self-model's
 * OWN declared limits, classifies each as a MODELING-limit or a DATA-limit, and for
 * each modeling-limit PRINTS a grounded, calibrated proposal for the next measurable
 * self-model improvement. Data-limits are surfaced honestly as awaiting external
 * reality with NO build proposal (R3 never proposes manufacturing data). It mutates
 * NOTHING, applies NOTHING, and never claims it HAS improved itself. Auto-discovered
 * from app/Console/Commands.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-self-improvement-modeling-fragment.md
 */
class AtlasDocumentationRealitySelfImprovementModelingCommand extends Command
{
    protected $signature = 'atlas:documentation-reality-self-improvement-modeling
        {--json : Emit canonical JSON}';

    protected $description = 'Read-only, proposal-only L-inf fragment (R3 self-improving modeling): watches R1/R2 declared limits and PROPOSES the next measurable self-model improvement for modeling-limits, while excluding data-limits (never proposes manufacturing external data). Never self-modifies, never claims L-inf done. Writes nothing.';

    public function handle(AtlasDocumentationRealitySelfImprovementModelingService $service): int
    {
        $payload = $service->proposeModelingImprovements() + ['generated_at' => now()->toJSON()];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('level', (string) ($payload['level'] ?? ''));
        $this->components->twoColumnDetail('one fragment, not the asymptote', ($payload['is_one_fragment_not_asymptote'] ?? false) ? 'yes' : 'NO');
        $this->components->twoColumnDetail('L-inf complete', ($payload['linf_complete'] ?? true) ? 'TRUE (BUG)' : 'false (never done)');
        $this->components->twoColumnDetail('proposal only (never self-modifies)', data_get($payload, 'claim_policy.proposal_only') && data_get($payload, 'claim_policy.self_modifies') === false ? 'yes' : 'NO');
        $this->components->twoColumnDetail('never proposes fabricating data', data_get($payload, 'claim_policy.never_proposes_fabricating_data') ? 'yes' : 'NO');
        $this->components->twoColumnDetail('writes', ($payload['writes'] ?? true) ? 'TRUE (BUG)' : 'false');

        if (($payload['available'] ?? true) === false) {
            $this->newLine();
            $this->warn('R3 degraded (no fabricated limit): '.(string) ($payload['degraded_reason'] ?? 'unknown'));
            $this->line('  '.(string) data_get($payload, 'note.statement', ''));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->twoColumnDetail('modeling-limits (proposed)', (string) data_get($payload, 'summary.modeling_limits', 0));
        $this->components->twoColumnDetail('data-limits (excluded, await world)', (string) data_get($payload, 'summary.data_limits', 0));

        $proposals = (array) ($payload['proposals'] ?? []);
        if ($proposals === []) {
            $this->newLine();
            $this->warn('No declared limits found to classify (R1/R2 emitted none this run).');

            return self::SUCCESS;
        }

        $modeling = array_values(array_filter(
            $proposals,
            static fn (array $p): bool => ($p['classification'] ?? '') === AtlasDocumentationRealitySelfImprovementModelingService::CLASSIFICATION_MODELING_LIMIT,
        ));
        $data = array_values(array_filter(
            $proposals,
            static fn (array $p): bool => ($p['classification'] ?? '') === AtlasDocumentationRealitySelfImprovementModelingService::CLASSIFICATION_DATA_LIMIT,
        ));

        if ($modeling !== []) {
            $this->newLine();
            $this->info('MODELING-limit proposals (proposal-only — a human decides; R3 never applies):');
            $this->table(
                ['confidence', 'capability', 'limit (grounded)', 'proposed next rung'],
                collect($modeling)->map(static fn (array $p): array => [
                    (string) data_get($p, 'uncertainty.confidence', ''),
                    Str::limit((string) ($p['capability'] ?? ''), 26),
                    Str::limit((string) ($p['limit_ref'] ?? ''), 54),
                    Str::limit((string) ($p['proposed_next_rung'] ?? ''), 88),
                ])->all(),
            );
        }

        if ($data !== []) {
            $this->newLine();
            $this->warn('DATA-limits — excluded from proposals (await external reality; proposed_next_rung=null, never manufactured):');
            $this->table(
                ['capability', 'limit (grounded)', 'awaits', 'next rung'],
                collect($data)->map(static fn (array $p): array => [
                    Str::limit((string) ($p['capability'] ?? ''), 26),
                    Str::limit((string) ($p['limit_ref'] ?? ''), 60),
                    (string) ($p['awaits'] ?? ''),
                    $p['proposed_next_rung'] === null ? 'null (correct)' : 'NON-NULL (BUG)',
                ])->all(),
            );
        }

        $this->newLine();
        $this->line('R3 is ONE measurable fragment of L-inf, not the asymptote; linf_complete=false; the mother reflective-self-model doc is unchanged north_star.');

        return self::SUCCESS;
    }
}
