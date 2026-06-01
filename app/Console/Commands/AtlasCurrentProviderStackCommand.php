<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCurrentProviderStackService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Current Provider Stack policy decider.
 *
 * Demonstrates the policy contracts on safe defaults: rail descriptors for the
 * five current-stack rails, route decisions (in-stack allowed, outside-stack
 * blocked with an exclusion receipt), subsidy-first spend policy (paygo blocked
 * unless human decision + cap + evidence), the Codex premium leverage boundary,
 * the ordered work flow, and the hard stops.
 *
 * @see docs/engineering-knowledge-base/atlas-current-provider-stack-v1.md
 */
final class AtlasCurrentProviderStackCommand extends Command
{
    protected $signature = 'atlas:aaeos:current-provider-stack {--json : Machine-readable JSON output}';

    protected $description = 'Decide Current Provider Stack policy: stack allow-list, subsidy-first spend, Codex leverage boundary, ordered flow and hard stops.';

    public function handle(AtlasCurrentProviderStackService $service): int
    {
        try {
            $result = [
                'rail_codex' => $service->describeRail('codex_gpt55'),
                'rail_minimax' => $service->describeRail('minimax_m27'),
                'route_in_stack' => $service->routeProvider('cursor_cli_composer'),
                'route_outside_stack' => $service->routeProvider('some_external_provider'),
                'route_outside_with_decision' => $service->routeProvider('some_external_provider', true),
                'spend_subsidy' => $service->spendDecision('subsidy'),
                'spend_paygo_blocked' => $service->spendDecision('paygo'),
                'spend_paygo_allowed' => $service->spendDecision('paygo', true, true, true),
                'codex_high_leverage' => $service->codexSpendDecision('final_review'),
                'codex_cheap_blocked' => $service->codexSpendDecision('cheap_scout'),
                'flow_in_progress' => $service->flowProgress([
                    'local_context_index_shards_ownership',
                    'subsidized_scout',
                ]),
                'flow_complete' => $service->flowProgress(
                    AtlasCurrentProviderStackService::FLOW_STEPS
                ),
                'hard_stops_clear' => $service->evaluateHardStops([
                    'paygo_without_cap' => false,
                    'codex_escalated_to_cheap_work' => false,
                ]),
                'hard_stops_block' => $service->evaluateHardStops([
                    'paygo_without_cap' => true,
                    'minimax_edit_without_lease_or_allowed_files' => true,
                ]),
            ];
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
