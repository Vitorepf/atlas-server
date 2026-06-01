<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendImpeccableDetectorExtensionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the "Impeccable Detector And Browser Extension"
 * doc. With no args it renders the detector decision summary: the engine
 * strength ladder, the 29=16+13 rule-catalogue invariant, the exit-code
 * contract and a worked engine-selection sample. Proves the doc's FLUXO
 * (choose engine) and REGRAS PARA IA (browser > regex, pixel contrast, evidence
 * not final proof) are live decision logic, never just documentation.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-detector-extension.md
 */
class AtlasProgrammingFrontendImpeccableDetectorExtensionCommand extends Command
{
    protected $signature = 'atlas:aaeos:programming-frontend-impeccable-detector-extension {--json : Print machine-readable JSON}';

    protected $description = 'Render the Atlas frontend detector decision core (engine selection, strength ladder, exit-code contract).';

    public function handle(AtlasProgrammingFrontendImpeccableDetectorExtensionService $service): int
    {
        try {
            $payload = $service->describe();
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasProgrammingFrontendImpeccableDetectorExtensionService::SCHEMA_VERSION,
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

        $catalogue = $payload['rule_catalogue'];
        $this->components->twoColumnDetail('schema_version', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('rules_total', (string) $catalogue['total']);
        $this->components->twoColumnDetail('rules_slop', (string) $catalogue['slop']);
        $this->components->twoColumnDetail('rules_quality', (string) $catalogue['quality']);
        $this->components->twoColumnDetail('sum_matches_total', $catalogue['sum_matches_total'] ? 'true' : 'false');
        $this->components->twoColumnDetail('exit_on_findings', (string) $payload['exit_codes']['findings_exist']);
        $this->components->twoColumnDetail('sample_url_engine', (string) $payload['sample_choose_engine']['engine']);
        $this->components->twoColumnDetail('runtime_authorized', $payload['runtime_authorized'] ? 'true' : 'false');

        return self::SUCCESS;
    }
}
