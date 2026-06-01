<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendImpeccableProductSiteAssetsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the "Impeccable Product Site Assets And
 * Distribution" doc. With no args it renders the product-proof decision summary:
 * the CONTRATOS area count, the 6-step FLUXO pipeline with its terminal stage,
 * the four REGRAS PARA IA and worked classify/proof/distribution samples. Proves
 * the doc's distribution pipeline and proof-vs-readiness rules are live decision
 * logic, never just documentation.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-product-site-assets.md
 */
class AtlasProgrammingFrontendImpeccableProductSiteAssetsCommand extends Command
{
    protected $signature = 'atlas:aaeos:programming-frontend-impeccable-product-site-assets {--json : Print machine-readable JSON}';

    protected $description = 'Render the Atlas product-proof/distribution decision core (area map, distribution pipeline, proof-vs-readiness rules).';

    public function handle(AtlasProgrammingFrontendImpeccableProductSiteAssetsService $service): int
    {
        try {
            $payload = $service->describe();
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasProgrammingFrontendImpeccableProductSiteAssetsService::SCHEMA_VERSION,
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

        $this->components->twoColumnDetail('schema_version', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('area_count', (string) $payload['area_count']);
        $this->components->twoColumnDetail('pipeline_stages', (string) count($payload['pipeline']));
        $this->components->twoColumnDetail('pipeline_terminal', (string) $payload['pipeline_terminal_stage']);
        $this->components->twoColumnDetail('rules', (string) count($payload['rules']));
        $this->components->twoColumnDetail('sample_proof_admissible', $payload['sample_proof']['admissible_as_proof'] ? 'true' : 'false');
        $this->components->twoColumnDetail('sample_bundle_distributable', $payload['sample_distribution']['distributable'] ? 'true' : 'false');
        $this->components->twoColumnDetail('readiness_authorized', $payload['readiness_authorized'] ? 'true' : 'false');

        return self::SUCCESS;
    }
}
