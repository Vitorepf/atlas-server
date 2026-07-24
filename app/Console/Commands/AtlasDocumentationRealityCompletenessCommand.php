<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasDocumentationRealityCompletenessService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Support\YesNo;

/**
 * ADRS RUNTIME COMPLETENESS — read-only, honest, DISAMBIGUATED completeness check.
 *
 * Dogfood: the ADRS proves its OWN runtime-completeness honestly. It reports THREE
 * SEPARATE AXES and NEVER conflates them into one undifferentiated "100%":
 *   (a) runtime_completeness — the buildable mechanisms (the achievable 10/10),
 *       met or not, with per-mechanism evidence;
 *   (b) asymptote — asymptote_complete=false ALWAYS (the permanent compass, never
 *       part of the 10/10);
 *   (c) reality_dependent — O1 outcome_grounded count, reported honestly and NOT
 *       counted toward completeness.
 * It COMPOSES the R2 reflective self-status + the AAEOS truth ledger and mutates
 * NOTHING. Auto-discovered from app/Console/Commands.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-system.md
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-evolution-ladder.md
 */
class AtlasDocumentationRealityCompletenessCommand extends Command
{
    protected $signature = 'atlas:documentation-reality-completeness
        {--json : Emit canonical JSON}';

    protected $description = 'Read-only ADRS runtime-completeness check. Reports THREE separate axes honestly: (a) runtime_completeness (the buildable mechanisms — the achievable 10/10), (b) asymptote (linf_complete=false ALWAYS — the permanent compass), (c) reality_dependent (O1 grounded count, NEVER counted toward completeness). Never claims the asymptote, never folds grounded into completeness. Writes nothing.';

    public function handle(AtlasDocumentationRealityCompletenessService $service): int
    {
        $payload = $service->assess() + ['generated_at' => now()->toJSON()];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('level', (string) ($payload['level'] ?? ''));
        $this->newLine();

        // (a) runtime_completeness — the achievable 10/10.
        $rc = (array) ($payload['runtime_completeness'] ?? []);
        $this->components->twoColumnDetail('<options=bold>AXIS (a) runtime_completeness</>', ($rc['runtime_complete'] ?? false) ? 'MET' : 'NOT MET');
        $this->components->twoColumnDetail('  built', sprintf('%d/%d', (int) ($rc['built_count'] ?? 0), (int) ($rc['total_mechanisms'] ?? 0)));
        $this->components->twoColumnDetail('  hardened', sprintf('%d/%d', (int) ($rc['hardened_count'] ?? 0), (int) ($rc['total_mechanisms'] ?? 0)));
        $unresolved = (array) ($rc['unresolved'] ?? []);
        $this->components->twoColumnDetail('  unresolved', $unresolved === [] ? 'none' : implode(', ', array_map('strval', $unresolved)));

        $mechanisms = (array) ($rc['mechanisms'] ?? []);
        if ($mechanisms !== []) {
            $this->newLine();
            $this->table(
                ['rung', 'mechanism', 'built', 'hardened', 'drift'],
                collect($mechanisms)->map(static fn (array $m): array => [
                    (string) ($m['rung'] ?? ''),
                    Str::limit((string) ($m['key'] ?? ''), 36),
                    ($m['built'] ?? false) ? 'yes' : 'NO',
                    ($m['hardened'] ?? false) ? 'yes' : 'NO',
                    ($m['drift_exempt'] ?? false) ? 'n/a' : (($m['drift'] ?? null) === false ? 'false' : (($m['drift'] ?? null) === true ? 'TRUE' : '?')),
                ])->all(),
            );
        }

        // (b) asymptote — the permanent compass.
        $this->newLine();
        $asymptote = (array) ($payload['asymptote'] ?? []);
        $this->components->twoColumnDetail('<options=bold>AXIS (b) asymptote</>', ($asymptote['asymptote_complete'] ?? true) ? 'TRUE (BUG)' : 'false (permanent compass, never done)');
        $this->components->twoColumnDetail('  counts toward completeness', ($asymptote['counts_toward_runtime_completeness'] ?? true) ? 'YES (BUG)' : 'no');

        // (c) reality_dependent — honest, not counted.
        $reality = (array) ($payload['reality_dependent'] ?? []);
        $this->components->twoColumnDetail('<options=bold>AXIS (c) reality_dependent</>', sprintf('outcome_grounded = %d (honest)', (int) ($reality['outcome_grounded_count'] ?? 0)));
        $this->components->twoColumnDetail('  counts toward completeness', ($reality['counts_toward_runtime_completeness'] ?? true) ? 'YES (BUG)' : 'no (function of real-world outcomes)');

        // Verdict — never claims the asymptote, never folds grounded in.
        $this->newLine();
        $verdict = (array) ($payload['verdict'] ?? []);
        $this->components->twoColumnDetail('<options=bold>VERDICT runtime_complete</>', YesNo::trueFalse($verdict['runtime_complete'] ?? false));
        $this->components->twoColumnDetail('verdict claims asymptote', ($verdict['claims_asymptote'] ?? true) ? 'TRUE (BUG)' : 'false');
        $this->components->twoColumnDetail('verdict folds grounded into completeness', ($verdict['folds_grounded_into_completeness'] ?? true) ? 'TRUE (BUG)' : 'false');
        $this->line('  '.(string) ($verdict['statement'] ?? ''));

        return self::SUCCESS;
    }
}
