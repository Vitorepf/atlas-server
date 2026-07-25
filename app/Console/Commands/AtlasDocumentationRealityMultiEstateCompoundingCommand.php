<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasDocumentationRealityMultiEstateCompoundingService;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * L2-O3 (first increment) — read-only cross-estate immunity propagation PROPOSER.
 *
 * Demonstrates a propagation proposal: given an antibody (a detector/gate pattern
 * synthesised by P3 from a rot that escaped in ONE project) and a target estate
 * list, it proposes immunising the operator's OTHER estates with the SAME antibody —
 * but ONLY the abstract, domain-agnostic pattern crosses. The sovereignty data
 * classes sensitive/secret/cyber NEVER cross an estate boundary; such an antibody
 * stays local and nothing is proposed to cross. Auto-discovered from
 * app/Console/Commands.
 *
 * THIS IS A PROPOSER, NOT A PROPAGATOR. It mutates nothing, transmits nothing, and
 * installs nothing — a human approves each cross-estate move. It always exits 0.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-multi-estate-compounding.md
 */
final class AtlasDocumentationRealityMultiEstateCompoundingCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:documentation-reality-multi-estate
        {--data-class=internal : The antibody source sovereignty data class (public|internal|sensitive|secret|cyber)}
        {--estate=* : Target estate/domain to propose immunising (repeatable; empty = all active estates)}
        {--json : Emit canonical JSON}';

    protected $description = 'Read-only L2-O3 cross-estate immunity propagation proposer: given an antibody + target estates, propose immunising the operator\'s OTHER estates with the SAME detector pattern. ONLY the abstract domain-agnostic pattern crosses; sovereignty classes sensitive/secret/cyber NEVER leave the machine and nothing crosses for them. Read-only proposer — never auto-propagates, never transmits, never installs. Always exits 0. Writes nothing.';

    public function handle(AtlasDocumentationRealityMultiEstateCompoundingService $proposer): int
    {
        $dataClass = (string) ($this->option('data-class') ?: AtlasDocumentationRealityMultiEstateCompoundingService::DEFAULT_DATA_CLASS);
        $estates = array_values((array) $this->option('estate'));

        $payload = $proposer->proposePropagation($this->demoAntibody(), $estates, $dataClass)
            + ['generated_at' => now()->toJSON()];

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            // PROPOSER, never a gate: always succeed.
            return self::SUCCESS;
        }

        $crossAllowed = (bool) data_get($payload, 'cross_estate_allowed');

        $this->components->twoColumnDetail('Multi-estate Compounding (L2-O3)', 'PROPOSAL — a human approves each cross-estate move');
        $this->components->twoColumnDetail('source data class', (string) data_get($payload, 'source_data_class', ''));
        $this->components->twoColumnDetail('cross-estate allowed', $crossAllowed ? 'yes (abstract pattern only)' : 'NO (stays local)');
        $this->components->twoColumnDetail('blocked reason', (string) (data_get($payload, 'blocked_reason') ?: '—'));
        $this->components->twoColumnDetail('sensitive/secret/cyber never cross', YesNo::trueFalse(data_get($payload, 'sovereignty.sensitive_secret_cyber_never_cross')));
        $this->components->twoColumnDetail('auto-propagates', YesNo::trueFalse(data_get($payload, 'sovereignty.auto_propagates')));
        $this->components->twoColumnDetail('writes / transmits', 'false / false');

        if ($crossAllowed) {
            $this->newLine();
            $this->line('<comment>What crosses (ABSTRACT pattern only — no project specifics):</comment>');
            $this->line('  detector kind: '.(string) data_get($payload, 'what_crosses.detector_kind', ''));
            $this->line('  failure kind:  '.(string) data_get($payload, 'what_crosses.failure_kind', ''));
            $this->line('  description:   '.(string) data_get($payload, 'what_crosses.pattern_description', ''));

            $this->newLine();
            $this->line('<comment>Proposed target estates (nothing is sent to them):</comment>');
            foreach ((array) data_get($payload, 'target_estates', []) as $estate) {
                $this->line('  - '.(string) ($estate['estate'] ?? '').(($estate['resolved'] ?? '') === 'true' ? '' : ' (unresolved)'));
            }
        } else {
            $this->newLine();
            $this->warn('Sovereignty gate: this antibody\'s class must NOT leave the machine. Nothing crosses; it stays local.');
        }

        $this->newLine();
        $this->line('<comment>What stays local (labels only — never the content):</comment>');
        foreach ((array) data_get($payload, 'what_stays_local', []) as $label) {
            $this->line('  - '.(string) $label);
        }

        $this->newLine();
        $this->warn('This is a PROPOSAL, not a propagation. It never auto-propagates, transmits, or installs. You approve each cross-estate move.');

        // PROPOSER, never a gate: always succeed regardless of the verdict.
        return self::SUCCESS;
    }

    /**
     * A demonstration antibody in the P3 shape (a detector/gate pattern). It carries
     * NO real sensitive content — it is a generic illustrative pattern so the command
     * can show the proposal end to end without touching any real failure record.
     *
     * @return array<string,mixed>
     */
    private function demoAntibody(): array
    {
        return [
            'failure_kind' => 'doc_drift',
            'reproducing_test_outline' => [
                'description' => 'Reproduce the drift escape first so the antibody fails on the unpatched system.',
                'must_fail_before_fix' => true,
            ],
            'proposed_detector' => [
                'kind' => 'frontmatter_rule',
                'where' => 'docs-health frontmatter validation',
                'description' => 'Reject the frontmatter shape that allowed the drift to ship.',
            ],
        ];
    }
}
