<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasDocumentationRealityOutcomeGroundingService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * L2-O1 (first increment) — read-only Outcome-Grounding SCORER command.
 *
 * Grades each implemented capability/doc by whether a REAL, resolved outcome
 * signal links back to it (was it USED / did it WORK in the world?). Internal
 * truth (drift zero) is the gate; a spec/north-star doc grades not_implemented;
 * an implemented doc with NO real signal grades implemented_no_outcome_signal —
 * the honest default. It NEVER fabricates an outcome and NEVER writes anything.
 * Auto-discovered from app/Console/Commands.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-outcome-grounded-truth.md
 */
class AtlasDocumentationRealityOutcomeGroundingCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:documentation-reality-outcome-grounding
        {--capability= : Restrict to a single capability/doc id, slug or path substring}
        {--json : Emit canonical JSON}';

    protected $description = 'Read-only L2-O1 outcome-grounding scorer: grade implemented docs by whether a REAL outcome signal links to them. No signal => implemented_no_outcome_signal (never a fake pass). Writes nothing.';

    public function handle(AtlasDocumentationRealityOutcomeGroundingService $scorer): int
    {
        $capability = $this->str($this->option('capability'));

        $payload = ($capability !== null
            ? $scorer->gradeForDoc($capability)
            : $scorer->gradeAll())
            + ['generated_at' => now()->toJSON()];

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('evaluated', (string) data_get($payload, 'summary.evaluated', 0));
        $this->components->twoColumnDetail('outcome grounded', (string) data_get($payload, 'summary.outcome_grounded_count', 0));
        $this->components->twoColumnDetail('implemented, no signal', (string) data_get($payload, 'summary.implemented_no_outcome_count', 0));
        $this->components->twoColumnDetail('not implemented (spec)', (string) data_get($payload, 'summary.not_implemented_count', 0));
        $this->components->twoColumnDetail('outcome signal source available', data_get($payload, 'summary.outcome_signal_source_available') ? 'yes' : 'NO');
        $this->components->twoColumnDetail('fabricates outcome / writes', 'false / false');

        $grades = (array) ($payload['grades'] ?? []);
        if ($grades === []) {
            $this->info('No capabilities with evidence_refs to grade.');

            return self::SUCCESS;
        }

        $this->table(
            ['capability', 'computed', 'grade', 'grounded', 'signals'],
            collect($grades)->map(static fn (array $g): array => [
                Str::limit((string) ($g['capability_id'] ?? ''), 44),
                (string) ($g['computed_state'] ?? ''),
                (string) ($g['grade'] ?? ''),
                YesNo::format($g['outcome_grounded'] ?? false),
                (string) count((array) ($g['outcome_signals'] ?? [])),
            ])->all(),
        );

        if ((int) data_get($payload, 'summary.outcome_signal_source_available', 0) === 0
            || data_get($payload, 'summary.outcome_signal_source_available') === false) {
            $this->warn('Outcome-signal source unavailable: every implemented doc is implemented_no_outcome_signal. No outcome fabricated.');
        } elseif ((int) data_get($payload, 'summary.outcome_grounded_count', 0) === 0) {
            $this->info('No real outcome signals linked: implemented docs are honestly implemented_no_outcome_signal (technically alive, not yet world-validated).');
        }

        return self::SUCCESS;
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
