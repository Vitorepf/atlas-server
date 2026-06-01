<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSourceConnectorsAndCaptureService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Source Connectors And Capture decider CLI.
 *
 *   php artisan atlas:aaeos:source-connectors-and-capture
 *     [--source=github_release_tag]  // show authority + action matrix row
 *     [--type=github]                // show required capture fields
 *     [--json]
 *
 * Read-only, deterministic. Emits the source classification, its action-matrix
 * row, the required capture fields for its type, and a connector-rule verdict
 * on a deliberately non-compliant run (scraping over an available API +
 * untreated injection) so the receipt shows the blocked disposition the doc
 * mandates.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/source-connectors-and-capture.md
 */
class AtlasSourceConnectorsAndCaptureCommand extends Command
{
    protected $signature = 'atlas:aaeos:source-connectors-and-capture
        {--source=social_post_thread : source key from the Per-Source Action Matrix}
        {--type=social_community : source type from the Capture Requirements}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas research · source connectors & capture decider (authority, action matrix, required fields, connector-rule verdict).';

    public function handle(AtlasSourceConnectorsAndCaptureService $service): int
    {
        try {
            $sourceOpt = $this->option('source');
            $typeOpt = $this->option('type');
            $source = is_string($sourceOpt) && $sourceOpt !== '' ? $sourceOpt : 'social_post_thread';
            $type = is_string($typeOpt) && $typeOpt !== '' ? $typeOpt : 'social_community';

            $classification = $service->classifySource($source);
            $action = $service->actionFor($source);
            $required = $service->requiredFields($type);

            // Demonstrate the connector-rule gate on a non-compliant run:
            // scraping while an official API exists, no raw evidence, wrong
            // schema, no tier, injection present but untreated.
            $ruleDemo = $service->evaluateConnectorRun([
                'official_api_available' => true,
                'used_scraping' => true,
                'raw_evidence_stored' => false,
                'normalized_schema' => 'something_else',
                'trust_tier_assigned' => false,
                'domain_allowlisted' => true,
                'rate_limited' => true,
                'secret_exposed_to_model' => false,
                'prompt_injection_present' => true,
                'injection_treated_as_hostile' => false,
            ]);

            $this->line((string) json_encode([
                'ok' => true,
                'classification' => $classification,
                'action_matrix' => $action,
                'required_capture_fields' => $required,
                'connector_rules' => $service->connectorRules(),
                'connector_rule_demo' => $ruleDemo,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'source_connectors_and_capture_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
