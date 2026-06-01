<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasReportingAndPublicationContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Surfaces the research reporting/publication contract decider.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/reporting-and-publication-contract.md
 */
class AtlasReportingAndPublicationContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:reporting-and-publication-contract {--json : Emit canonical JSON}';

    protected $description = 'Decide whether a drafted research report may publish (shape, conclusions, channel, critical-topic gate) and how alerts may dispatch.';

    public function handle(AtlasReportingAndPublicationContractService $service): int
    {
        try {
            // Safe default: a complete, channel-linked report on a critical topic
            // (security) -> the gate must HOLD it for human review even though the
            // shape and conclusion fields are all present.
            $conclusion = array_fill_keys($service->conclusionFields(), 'present');

            $payload = $service->decidePublication([
                'topic' => 'security advisory',
                'sections' => $service->reportSections(),
                'conclusions' => [$conclusion],
                'channel' => 'atlas_dashboard',
                'links_to_run' => true,
                'links_to_evidence' => true,
            ]);

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode(
                    $payload,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ));

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('Publication decision', (string) $payload['decision']);
            $this->components->twoColumnDetail('Critical topic', (string) ($payload['critical_topic'] ?? 'none'));
            $this->components->twoColumnDetail('Requires human review', $payload['requires_human_review'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('Sections complete', $payload['sections_complete'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('Conclusions complete', $payload['conclusions_complete'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('Channel valid', $payload['channel_valid'] ? 'yes' : 'no');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $envelope = [
                'schema' => AtlasReportingAndPublicationContractService::RECEIPT_SCHEMA,
                'ok' => false,
                'error' => $e->getMessage(),
            ];

            $this->line((string) json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
