<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasDocumentationRealityAutoHealService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Operator entrypoint for the C4 commit-boundary auto-heal
 * ({@see AtlasDocumentationRealityAutoHealService}). It downgrades each over-claiming
 * STAGED canonical doc to the honest computed implementation_state, re-stages it, and
 * reports an auditable receipt per heal.
 *
 * SAFETY POSTURE (the operator stays in control of the commit flow):
 *   - DEFAULTS TO DRY-RUN. Without --apply (or with --dry-run) it only REPORTS the heals
 *     it WOULD make and writes nothing — no file edit, no `git add`. A real heal requires
 *     the explicit --apply switch.
 *   - It operates ONLY on the explicit root resolved here (defaults to the current repo
 *     root). It does NOT walk the real docs/ tree directly — it acts on the STAGED set of
 *     that root, and only ever rewrites docs the operator has already staged.
 *   - It NEVER installs/modifies/re-enables a git hook. Wiring this in FRONT of the
 *     disabled commit gate is the operator's separate manual switch; this command only
 *     builds + proves the mechanism.
 */
class AtlasDocumentationRealityAutoHealCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:documentation-reality-auto-heal
        {--staged : Heal the staged canonical docs (the default and only scope)}
        {--root= : The repo root to operate on (defaults to the current repo root)}
        {--apply : Actually write the downgrade + re-stage (without this, the run is a dry-run preview)}
        {--dry-run : Force a dry-run preview even if --apply is passed (report only, write nothing)}
        {--json : Emit canonical JSON}';

    protected $description = 'Commit-boundary auto-heal: downgrade each over-claiming staged canonical doc to its honest computed implementation_state (dry-run by default).';

    public function handle(AtlasDocumentationRealityAutoHealService $healer): int
    {
        // Default-safe: a run is a dry-run unless --apply is explicitly given, and --dry-run
        // always forces preview-only even alongside --apply.
        $dryRun = ! ((bool) $this->option('apply')) || (bool) $this->option('dry-run');

        $root = $this->resolveRoot();

        $result = $healer->heal($root, $dryRun);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));

            return self::SUCCESS;
        }

        $this->renderTable($result, $dryRun);

        return self::SUCCESS;
    }

    private function resolveRoot(): string
    {
        $root = trim((string) ($this->option('root') ?? ''));

        return $root !== '' ? $root : base_path();
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function renderTable(array $result, bool $dryRun): void
    {
        $this->components->twoColumnDetail('Auto-heal mode', $dryRun ? 'dry-run (preview — nothing written)' : 'apply (write + re-stage)');
        $this->components->twoColumnDetail('Repo root', (string) ($result['repo_root'] ?? ''));

        if (($result['degraded'] ?? false) === true) {
            $this->newLine();
            $this->warn('Degraded — auto-heal withheld: '.(string) ($result['degraded_reason'] ?? ''));
            $this->line('  (the code-intelligence index is empty or absent; a blind downgrade is refused.)');

            return;
        }

        $summary = (array) ($result['summary'] ?? []);
        $this->components->twoColumnDetail('Staged canonical docs', (string) ($summary['staged_canonical_docs'] ?? 0));
        $this->components->twoColumnDetail('Over-claims', (string) ($summary['over_claims'] ?? 0));
        $this->components->twoColumnDetail($dryRun ? 'Would heal' : 'Healed', (string) ($summary['healed'] ?? 0));

        $heals = (array) ($result['heals'] ?? []);
        if ($heals !== []) {
            $this->newLine();
            $this->line($dryRun ? 'Would downgrade (preview):' : 'Downgraded:');
            foreach ($heals as $heal) {
                $this->line(sprintf(
                    '  - %s : %s -> %s%s',
                    (string) ($heal['doc_path'] ?? ''),
                    (string) ($heal['before_state'] ?? ''),
                    (string) ($heal['after_state'] ?? ''),
                    $dryRun ? '' : (($heal['restaged'] ?? false) === true ? ' (re-staged)' : ' (re-stage FAILED)'),
                ));
            }
        } else {
            $this->newLine();
            $this->line('No over-claiming staged canonical docs to heal.');
        }
    }
}
