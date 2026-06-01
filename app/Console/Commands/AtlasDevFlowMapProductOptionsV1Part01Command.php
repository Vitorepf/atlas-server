<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevFlowMapProductOptionsV1Part01Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Flow Map And Product Options v1 · Parte 1 — settled-rules decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-flow-map-product-options-v1-part01 [--json]
 *
 * Read-only, deterministic. Exercises the closed slice with safe defaults: the
 * golden-rule gate with a partially-met set (blocks), the order-of-battle gate
 * with steps 1..5 done (arena prep still blocked), the honest completion machine
 * for a no-evidence `passed` (downgraded) and an evidence-backed `passed`, the
 * spec routing for a low-risk and an R5 intake, and a context-entry completeness
 * check, then emits the verdicts plus the manifest as JSON.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-01.md
 */
class AtlasDevFlowMapProductOptionsV1Part01Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-flow-map-product-options-v1-part01 {--json}';

    protected $description = 'Atlas Dev flow map and product options (Parte 1) · evaluate the golden-rule arena gate, the order-of-battle gate, the honest completion state machine, risk-based spec routing and the context-diary schema.';

    public function handle(AtlasDevFlowMapProductOptionsV1Part01Service $service): int
    {
        try {
            $payload = [
                'ok' => true,
                'manifest' => $service->manifest(),
                'golden_rule_partial' => $service->goldenRuleGate([
                    'execution_contract' => true,
                    'reproducible_local_results' => true,
                    'selected_battery_tasks' => false,
                    'quality_cost_time_metrics' => false,
                    'escalation_policy' => false,
                    'comparison_criterion' => false,
                ]),
                'golden_rule_all_met' => $service->goldenRuleGate([
                    'execution_contract' => true,
                    'reproducible_local_results' => true,
                    'selected_battery_tasks' => true,
                    'quality_cost_time_metrics' => true,
                    'escalation_policy' => true,
                    'comparison_criterion' => true,
                ]),
                'order_of_battle_blocked' => $service->orderOfBattleGate([
                    'understand_current_atlas_dev_flows',
                    'receive_user_contexts_and_record_value',
                    'extract_product_and_architecture_principles',
                    'turn_principles_into_testable_hypotheses',
                    'turn_hypotheses_into_small_atlas_dev_changes',
                ]),
                'completion_passed_no_evidence' => $service->honestCompletion('passed', false),
                'completion_passed_with_evidence' => $service->honestCompletion('passed', true),
                'completion_unknown_state' => $service->honestCompletion('looks_good', false),
                'spec_routing_low_risk' => $service->specRoutingFor('R1'),
                'spec_routing_high_risk' => $service->specRoutingFor('R5'),
                'context_entry_incomplete' => $service->contextEntryComplete([
                    'fonte' => 'conversa',
                    'texto_recebido' => 'ideia',
                ]),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_flow_map_product_options_v1_part01_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
