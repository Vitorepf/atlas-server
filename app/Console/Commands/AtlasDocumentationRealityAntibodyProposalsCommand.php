<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasDocumentationRealityAntibodyProposerService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * L1-P3 (first increment) — read-only Self-Immunizing Antibody PROPOSER command.
 *
 * Reads the most recent escaped-failure capsules and prints one antibody proposal
 * per escape: the reproducing-test outline (REQUIRED, the un-skippable core) plus
 * the proposed detector spec. It NEVER mutates anything — no gate is created, no
 * test is written, no file is touched, nothing is executed or installed. A
 * human/gate writes the reproducing test FIRST and then the detector, through the
 * existing gates + Evidence Ledger. Auto-discovered from app/Console/Commands.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-self-immunizing-antibody.md
 */
class AtlasDocumentationRealityAntibodyProposalsCommand extends Command
{
    protected $signature = 'atlas:documentation-reality-antibody-proposals
        {--limit=20 : Max recent escaped-failure capsules to propose antibodies for}
        {--json : Emit canonical JSON}';

    protected $description = 'Read-only P3 antibody proposer: from escaped failures, propose the detector + reproducing-test outline that makes each un-repeatable. Never creates a gate, never writes, never executes.';

    public function handle(AtlasDocumentationRealityAntibodyProposerService $proposer): int
    {
        $limit = (int) $this->option('limit');
        $payload = $proposer->proposeRecent($limit) + ['generated_at' => now()->toJSON()];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('escaped failures', (string) data_get($payload, 'summary.failure_count', 0));
        $this->components->twoColumnDetail('antibodies proposed', (string) data_get($payload, 'summary.antibody_count', 0));
        $this->components->twoColumnDetail('creates gate / writes / executes', 'false / false / false');

        if (($payload['degraded'] ?? false) === true) {
            $this->info('Degraded: '.(string) ($payload['reason'] ?? 'no_failure_capsules').'. No escaped-failure capsules to immunise against — nothing fabricated.');

            return self::SUCCESS;
        }

        $antibodies = (array) ($payload['antibodies'] ?? []);
        if ($antibodies === []) {
            $this->info('No escaped failures: nothing to immunise.');

            return self::SUCCESS;
        }

        $this->table(
            ['failure ref', 'kind', 'detector', 'plug-in point', 'has repro test'],
            collect($antibodies)->map(static fn (array $a): array => [
                Str::limit((string) ($a['failure_ref'] ?? ''), 30),
                (string) ($a['failure_kind'] ?? ''),
                (string) data_get($a, 'proposed_detector.kind', ''),
                Str::limit((string) ($a['plug_in_point'] ?? ''), 40),
                ! empty(data_get($a, 'reproducing_test_outline.steps')) ? 'yes' : 'NO',
            ])->all(),
        );

        $this->warn(data_get($payload, 'summary.antibody_count', 0).' antibody proposal(s). PROPOSALS only — never auto-installed. Each requires its reproducing test written FIRST, then the detector, through the existing gates + Evidence Ledger.');

        return self::SUCCESS;
    }
}
