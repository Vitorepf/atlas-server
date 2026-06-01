<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasDocumentationRealityCausalSelfModelService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * L-inf (ONE promoted fragment: R1 causal self-model) — read-only CAUSAL
 * SELF-MODEL command.
 *
 * For a capability (or all of them), prints the causal chain — intent (what the doc
 * CLAIMS) -> truth (what the code RESOLVES) -> result (what OUTCOME resulted, honest
 * no-signal today) -> why (the causal account from the REAL gap signals) — with
 * CALIBRATED uncertainty on EVERY why-link and every link labelled proven|inferred.
 * It COMPOSES existing read-only reports and mutates NOTHING. Auto-discovered from
 * app/Console/Commands.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-causal-self-model-fragment.md
 */
class AtlasDocumentationRealityCausalSelfModelCommand extends Command
{
    protected $signature = 'atlas:documentation-reality-causal-self-model
        {--capability= : Explain one capability (id/slug or owner-doc path substring); omit to explain all}
        {--json : Emit canonical JSON}';

    protected $description = 'Read-only L-inf fragment (R1 causal self-model): explains a capability as intent->truth->result->why with calibrated uncertainty on every causal link, distinguishing inference from proof. Never fabricates a cause, never claims L-inf is done. Writes nothing.';

    public function handle(AtlasDocumentationRealityCausalSelfModelService $service): int
    {
        $capability = $this->str($this->option('capability'));

        $payload = ($capability !== null
            ? $service->explainCapability($capability)
            : $service->explainAll()) + ['generated_at' => now()->toJSON()];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('level', (string) ($payload['level'] ?? ''));
        $this->components->twoColumnDetail('one fragment, not the asymptote', ($payload['is_one_fragment_not_asymptote'] ?? false) ? 'yes' : 'NO');
        $this->components->twoColumnDetail('L-inf complete', ($payload['linf_complete'] ?? true) ? 'TRUE (BUG)' : 'false (never done)');
        $this->components->twoColumnDetail('every causal claim calibrated', data_get($payload, 'claim_policy.every_causal_claim_calibrated') ? 'yes' : 'NO');
        $this->components->twoColumnDetail('distinguishes inference from proof', data_get($payload, 'claim_policy.distinguishes_inference_from_proof') ? 'yes' : 'NO');
        $this->components->twoColumnDetail('writes', ($payload['writes'] ?? true) ? 'TRUE (BUG)' : 'false');

        if (($payload['available'] ?? true) === false) {
            $this->newLine();
            $this->warn('Causal model degraded (no fabricated cause): '.(string) ($payload['degraded_reason'] ?? 'unknown'));
            $this->line('  '.(string) data_get($payload, 'note.statement', ''));

            return self::SUCCESS;
        }

        $chains = (array) ($payload['causal_chains'] ?? []);
        if ($chains === []) {
            $this->newLine();
            $this->warn('No capability matched the filter.');

            return self::SUCCESS;
        }

        foreach ($chains as $chain) {
            $this->newLine();
            $this->components->twoColumnDetail(
                '<options=bold>CAPABILITY</>',
                (string) ($chain['capability_id'] ?? $chain['owner_doc'] ?? ''),
            );
            $this->components->twoColumnDetail('intent (doc CLAIMS)', (string) data_get($chain, 'intent.claimed_state', ''));
            $this->components->twoColumnDetail('truth (code RESOLVES)', (string) data_get($chain, 'truth.computed_state', ''));
            $this->components->twoColumnDetail('result (OUTCOME)', (string) data_get($chain, 'result.grade', ''));
            $this->components->twoColumnDetail('chain confidence', (string) data_get($chain, 'calibration.confidence', ''));

            $why = (array) ($chain['why'] ?? []);
            if ($why !== []) {
                $this->table(
                    ['inference', 'confidence', 'basis', 'why (causal link)'],
                    collect($why)->map(static fn (array $l): array => [
                        (string) ($l['inference'] ?? ''),
                        (string) data_get($l, 'uncertainty.confidence', ''),
                        Str::limit((string) ($l['basis'] ?? ''), 44),
                        Str::limit((string) ($l['statement'] ?? ''), 84),
                    ])->all(),
                );
            }

            $blindSpots = (array) data_get($chain, 'calibration.blind_spots', []);
            if ($blindSpots !== []) {
                $this->warn('Calibrated blind spots (R1 is ONE fragment of L-inf, not L-inf):');
                foreach ($blindSpots as $spot) {
                    $this->line('  - '.(string) $spot);
                }
            }
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
