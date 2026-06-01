<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendImpeccableSkillCommandFlowService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Impeccable Skill And Command Flow doc. With
 * safe defaults it renders the command-family taxonomy, the ordered Fluxo, and
 * proves the doc's hard gates are live: missing PRODUCT.md blocks and forces
 * `teach`; `craft` without `shape` is refused; a screenshot with no read/inspect
 * is not evidence; `craft` is correctly routed to the Build family.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-skill-command-flow.md
 */
class AtlasProgrammingFrontendImpeccableSkillCommandFlowCommand extends Command
{
    protected $signature = 'atlas:aaeos:programming-frontend-impeccable-skill-command-flow {--json : Print machine-readable JSON}';

    protected $description = 'Render the Impeccable skill command taxonomy and evaluate the documented context gate, register lane, craft gates, evidence rule and Fluxo ordering.';

    public function handle(AtlasProgrammingFrontendImpeccableSkillCommandFlowService $service): int
    {
        try {
            $flow = $service->flow();

            // Safe default: the canonical Fluxo executed fully in order.
            $order = $service->evaluateFlowOrder($flow);

            // Safe default: classify `craft` -> must land in the Build family.
            $classify = $service->classifyCommand('craft');

            // Safe default: no PRODUCT.md -> blocked, forced to `teach`.
            $contextBlocked = $service->evaluateContextGate(false, false);

            // Safe default: craft attempted without shape -> refused.
            $craftRefused = $service->evaluateCraftGate([
                'shape_passed' => false,
                'image_generation_available' => true,
            ]);

            // Safe default: screenshot captured but never read/inspected -> not evidence.
            $screenshot = $service->evaluateScreenshotEvidence(true, false, false);

            $payload = [
                'ok' => true,
                'schema_version' => AtlasProgrammingFrontendImpeccableSkillCommandFlowService::SCHEMA_VERSION,
                'mode' => AtlasProgrammingFrontendImpeccableSkillCommandFlowService::MODE,
                'risk_level' => AtlasProgrammingFrontendImpeccableSkillCommandFlowService::RISK_LEVEL,
                'families' => $service->families(),
                'flow' => $flow,
                'craft_pre_code_gates' => $service->craftPreCodeGates(),
                'flow_order' => $order,
                'classify_craft' => $classify,
                'context_gate_without_product_md' => $contextBlocked,
                'craft_without_shape' => $craftRefused,
                'screenshot_without_read' => $screenshot,
            ];
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasProgrammingFrontendImpeccableSkillCommandFlowService::SCHEMA_VERSION,
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
        $this->components->twoColumnDetail('families', (string) count($payload['families']));
        $this->components->twoColumnDetail('flow_steps', (string) count($flow));
        $this->components->twoColumnDetail('flow_ordered', $order['ordered'] ? 'true' : 'false');
        $this->components->twoColumnDetail('craft_family', (string) ($classify['family'] ?? 'unknown'));
        $this->components->twoColumnDetail('no_product_md_blocks', $contextBlocked['blocked'] ? 'true' : 'false');
        $this->components->twoColumnDetail('no_product_md_forces', (string) ($contextBlocked['forced_command'] ?? 'none'));
        $this->components->twoColumnDetail('craft_without_shape_refused', $craftRefused['refused'] ? 'true' : 'false');
        $this->components->twoColumnDetail('unread_screenshot_is_evidence', $screenshot['counts_as_evidence'] ? 'true' : 'false');

        return self::SUCCESS;
    }
}
