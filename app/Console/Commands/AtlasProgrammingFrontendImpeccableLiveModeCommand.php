<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendImpeccableLiveModeService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Impeccable Live Mode Teardown doc. With safe
 * defaults it renders the ordered event loop, the 7 "Regras para IA", and proves
 * the doc's hard gates are live: a compliant loop in canonical order passes, and
 * a live source mutation without boundary + evidence is refused.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-live-mode.md
 */
class AtlasProgrammingFrontendImpeccableLiveModeCommand extends Command
{
    protected $signature = 'atlas:aaeos:programming-frontend-impeccable-live-mode {--json : Print machine-readable JSON}';

    protected $description = 'Render the Impeccable Live Mode event loop and evaluate the documented ordering, Regras para IA, and source-mutation boundary gate.';

    public function handle(AtlasProgrammingFrontendImpeccableLiveModeService $service): int
    {
        try {
            $flow = $service->flow();

            // Safe default: the canonical loop executed fully in order.
            $order = $service->evaluateEventLoopOrder($flow);

            // Safe default: a fully compliant live iteration.
            $rulesCompliant = $service->evaluateRules([
                'helper_port_distinct_from_app_url' => true,
                'poll_long_timeout_and_repolls' => true,
                'annotated_screenshot_read' => true,
                'text_disambiguation_when_repeated' => true,
                'target_is_not_generated_file' => true,
                'one_top_level_element_per_variant' => true,
                'cleanup_done_before_complete' => true,
            ]);

            // Safe default: a mutation that is missing boundary + evidence -> refused.
            $mutationRefused = $service->authorizeSourceMutation([]);

            $payload = [
                'ok' => true,
                'schema_version' => AtlasProgrammingFrontendImpeccableLiveModeService::SCHEMA_VERSION,
                'mode' => AtlasProgrammingFrontendImpeccableLiveModeService::MODE,
                'risk_level' => AtlasProgrammingFrontendImpeccableLiveModeService::RISK_LEVEL,
                'flow' => $flow,
                'rules' => $service->rules(),
                'risk_mitigations' => $service->riskMitigations(),
                'event_loop_order' => $order,
                'rules_compliant' => $rulesCompliant,
                'source_mutation_without_evidence' => $mutationRefused,
            ];
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasProgrammingFrontendImpeccableLiveModeService::SCHEMA_VERSION,
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
        $this->components->twoColumnDetail('mode', (string) $payload['mode']);
        $this->components->twoColumnDetail('risk_level', (string) $payload['risk_level']);
        $this->components->twoColumnDetail('flow_steps', (string) count($flow));
        $this->components->twoColumnDetail('event_loop_ordered', $order['ordered'] ? 'true' : 'false');
        $this->components->twoColumnDetail('rules_compliant', $rulesCompliant['compliant'] ? 'true' : 'false');
        $this->components->twoColumnDetail('mutation_without_evidence_authorized', $mutationRefused['authorized'] ? 'true' : 'false');

        return self::SUCCESS;
    }
}
