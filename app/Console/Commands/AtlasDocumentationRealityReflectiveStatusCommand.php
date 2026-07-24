<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasDocumentationRealityReflectiveStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * L-inf (ONE promoted fragment: R2 epistemic humility) — read-only REFLECTIVE
 * SELF-STATUS command.
 *
 * Prints the ADRS's self-assessment: one calibrated, blind-spot-bearing claim per
 * rung (L0, L1-P1/P2/P3, L2-O1/O2/O3) plus a HEADLINE that answers "is the ADRS
 * doc<->runtime 10/10?" WITHOUT a bare verdict — always WITH its declared blind
 * spots (first-increments-only, outcome_grounded=0, claim-set-incompleteness). It
 * COMPOSES existing read-only reports and mutates NOTHING. Auto-discovered from
 * app/Console/Commands.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-reflective-status-fragment.md
 */
class AtlasDocumentationRealityReflectiveStatusCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:documentation-reality-reflective-status
        {--json : Emit canonical JSON}';

    protected $description = 'Read-only L-inf fragment (R2 epistemic humility): the ADRS reflective self-status. Answers "is the ADRS 10/10?" with calibrated uncertainty + declared blind-spots on every self-claim — never a bare verdict, never claims L-inf is done. Writes nothing.';

    public function handle(AtlasDocumentationRealityReflectiveStatusService $service): int
    {
        $payload = $service->selfAssessment() + ['generated_at' => now()->toJSON()];

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('level', (string) ($payload['level'] ?? ''));
        $this->components->twoColumnDetail('one fragment, not the asymptote', ($payload['is_one_fragment_not_asymptote'] ?? false) ? 'yes' : 'NO');
        $this->components->twoColumnDetail('L-inf complete', ($payload['linf_complete'] ?? true) ? 'TRUE (BUG)' : 'false (never done)');
        $this->components->twoColumnDetail('every claim carries calibrated uncertainty', data_get($payload, 'claim_policy.every_claim_carries_calibrated_uncertainty') ? 'yes' : 'NO');
        $this->components->twoColumnDetail('writes', ($payload['writes'] ?? true) ? 'TRUE (BUG)' : 'false');

        $claims = (array) ($payload['claims'] ?? []);
        if ($claims !== []) {
            $this->newLine();
            $this->table(
                ['rung', 'confidence', 'blind spots', 'claim'],
                collect($claims)->map(static fn (array $c): array => [
                    (string) ($c['rung'] ?? ''),
                    (string) ($c['confidence'] ?? ''),
                    (string) count((array) ($c['blind_spots'] ?? [])),
                    Str::limit((string) ($c['claim'] ?? ''), 96),
                ])->all(),
            );
        }

        $this->newLine();
        $this->components->twoColumnDetail('<options=bold>HEADLINE</>', (string) data_get($payload, 'headline.question', ''));
        $this->line('  '.(string) data_get($payload, 'headline.assessment', ''));
        $this->components->twoColumnDetail('headline confidence', (string) data_get($payload, 'headline.confidence', ''));
        $this->components->twoColumnDetail('headline is a bare verdict', data_get($payload, 'headline.is_bare_verdict') ? 'TRUE (BUG)' : 'false');

        $blindSpots = (array) data_get($payload, 'headline.declared_blind_spots', []);
        if ($blindSpots !== []) {
            $this->newLine();
            $this->warn('Declared blind spots (the answer is NOT 10/10):');
            foreach ($blindSpots as $spot) {
                $this->line('  - '.(string) $spot);
            }
        }

        return self::SUCCESS;
    }
}
