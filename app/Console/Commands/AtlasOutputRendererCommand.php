<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasOutputRendererService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Output Renderer doc. With no args it runs a
 * worked render of a kernel result that carries a receipt, evidence and a
 * warning — proving the documented invariants are live: canonical truth is
 * carried verbatim, receipt/evidence are never omitted, and failures/warnings
 * are never hidden. It renders presentation only; it never alters truth.
 *
 * @see docs/engineering-knowledge-base/system-graph/output-renderer.md
 */
class AtlasOutputRendererCommand extends Command
{
    protected $signature = 'atlas:aaeos:output-renderer {--json : Print machine-readable JSON}';

    protected $description = 'Render a sample Kernel result to a surface and prove the Output Renderer invariants (read-only over truth).';

    public function handle(AtlasOutputRendererService $service): int
    {
        try {
            $sample = [
                'kind' => AtlasOutputRendererService::KIND_PLAN,
                'event_id' => 'evt_sample_001',
                'status' => 'completed_with_warnings',
                'content' => ['steps' => ['scaffold', 'implement', 'verify']],
                'receipt' => ['decision' => 'approved', 'signed' => true],
                'evidence' => ['ledger_ref' => 'led_001'],
                'warnings' => ['1 test flaky on retry'],
                // A surface trying to hide the warning for aesthetics — must fail.
                'hide_warnings' => true,
            ];

            $rendered = $service->render($sample, 'atlas_code');
            $audit = $service->preservesTruth($sample, $rendered);

            $payload = [
                'ok' => true,
                'schema' => $rendered['schema'],
                'surface' => $rendered['surface'],
                'layout' => $rendered['layout'],
                'kind' => $rendered['kind'],
                'panel_kinds' => $rendered['presentation']['panel_kinds'],
                'canonical_fingerprint' => $rendered['canonical_fingerprint'],
                'altered_canonical' => $rendered['altered_canonical'],
                'violations' => $rendered['violations'],
                'preserves_truth' => $audit['preserves_truth'],
                'manifest' => $service->manifest(),
            ];
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema' => AtlasOutputRendererService::SCHEMA,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('schema', (string) $payload['schema']);
        $this->components->twoColumnDetail('surface', (string) $payload['surface']);
        $this->components->twoColumnDetail('layout', (string) $payload['layout']);
        $this->components->twoColumnDetail('kind', (string) $payload['kind']);
        $this->components->twoColumnDetail('panels', implode(', ', $payload['panel_kinds']));
        $this->components->twoColumnDetail('altered_canonical', $payload['altered_canonical'] ? 'true' : 'false');
        $this->components->twoColumnDetail('preserves_truth', $payload['preserves_truth'] ? 'true' : 'false');
        $this->components->twoColumnDetail('risk_suppression_flagged', $payload['violations'] === [] ? 'false' : 'true');

        return self::SUCCESS;
    }
}
