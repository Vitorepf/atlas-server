<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeLiveAuthorityBootstrapService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;
use Illuminate\Console\Command;
use JsonException;

/**
 * AP-790 · reliable 24h autonomous loop runner CLI.
 *
 * Drives the real AP-786 autonomous evolution session in a durable, resilient loop
 * with locks, budgets, rate limit, pause/kill switch, crash recovery and an
 * append-only ledger. It never reimplements selection/execution/merge.
 */
final class AtlasSoftwareCompanyReliable24hLoopCommand extends Command
{
    protected $signature = 'atlas:software-company-stewardship:reliable-24h-loop
        {--area=agentic_engineering_os : Canonical area_id}
        {--focus=dev_forge : Area focus slice}
        {--scope-profile=factory_max : Selection scope profile: balanced or factory_max}
        {--provider=cursor_cli : Atlas provider driver passed to AP-786}
        {--model=composer-2.5-fast : Provider model passed to AP-786}
        {--repo-root= : Git repository root}
        {--actor=operator : Operator/session actor}
        {--max-runtime-minutes=1440 : Stop the loop after this many wall-clock minutes}
        {--max-cycles= : Stop after this many cycles this run (default: runtime/other budgets)}
        {--max-merges= : Stop after this many merges this run}
        {--max-findings=200 : Findings scanned per cycle before priority/seen/quarantine filtering}
        {--max-blocked-in-row=3 : Stop after this many consecutive blocked cycles}
        {--sleep-seconds=0 : Rate limit: seconds to sleep between cycles}
        {--lock-lease-seconds=3600 : Exclusive lock lease TTL; stale locks expire for crash recovery}
        {--execute : Drive AP-786 in execute mode (real sandbox/provider/commit/merge via AP-786)}
        {--auto-merge : Ask AP-769/AP-774 ff-only merge of eligible branches (via AP-786)}
        {--allow-code-auto-merge : Allow AP-774 bugfix/cleanup code auto-merge when validation passes}
        {--allow-direct-provider-driver : Legacy diagnostic only; forwarded to AP-786}
        {--continue-on-blocked : Keep looping when a cycle is blocked or waiting review}
        {--multi-agent-workcell : AP-801 forward to AP-786 so each executed cycle is projected through the multi-agent lane workcell; never invokes a provider itself}
        {--pull-main : Let AP-786 pull/update main after a successful merge}
        {--cleanup-worktrees : Safe cleanup of clean, merged sandbox worktrees after a merge (AP-756)}
        {--forge-obra= : AP-788 real governed Obra UUID for owner=forge; never fabricated}
        {--forge-live-topology-json= : AP-788 live Forge provider topology JSON object (requires status=live)}
        {--forge-live-decision-json= : AP-788 live Forge decision JSON object (requires decision + operator_actor)}
        {--forge-dispatch-mode= : AP-788 forge_runtime_dispatch (default) | forge_parallel_durable | forge_provider_invoke}
        {--forge-role= : AP-788 Forge role: primary_builder|critical_reviewer|context_scout|repair_agent|local_tool_runner}
        {--forge-provider-authorization : AP-788 explicit provider-execution authorization (only for forge_provider_invoke)}
        {--forge-budget-approved : AP-788 explicit budget approval (only for forge_provider_invoke)}
        {--bootstrap-forge-authority : AP-789 derive REAL forge live topology/decision/AWIS readiness for --forge-obra and inject when ready; blocks honestly otherwise}
        {--forge-operator-actor= : AP-789 operator actor authorizing forge dispatch; defaults to --actor; never fabricated}
        {--record : Append AP-786 session receipts}
        {--dry-run : Plan only; forces AP-786 execute=false (no provider/branch/commit/merge)}
        {--json : Emit JSON}';

    protected $description = 'AP-790 · reliable 24h autonomous loop runner wrapping AP-786 (locks, budgets, pause/kill, crash recovery, append-only ledger).';

    public function handle(Reliable24hLoopRunnerService $runner, ForgeLiveAuthorityBootstrapService $forgeBootstrap): int
    {
        try {
            $forgeAuthority = AtlasSoftwareCompanyAutonomousEvolutionSessionCommand::parseForgeAuthority([
                'obra' => $this->option('forge-obra'),
                'topology_json' => $this->option('forge-live-topology-json'),
                'decision_json' => $this->option('forge-live-decision-json'),
                'dispatch_mode' => $this->option('forge-dispatch-mode'),
                'role' => $this->option('forge-role'),
                'provider_authorization' => (bool) $this->option('forge-provider-authorization'),
                'budget_approved' => (bool) $this->option('forge-budget-approved'),
            ]);
        } catch (JsonException $e) {
            $payload = [
                'schema_version' => 'atlas.software_company_stewardship.command_error.v1',
                'status' => 'blocked',
                'reason' => 'forge_authority_json_invalid',
                'detail' => $e->getMessage(),
            ];
            $this->emit($payload);

            return self::FAILURE;
        }

        $bootstrapSummary = null;
        if ((bool) $this->option('bootstrap-forge-authority')) {
            $report = $forgeBootstrap->bootstrap([
                'forge_obra' => (string) ($this->option('forge-obra') ?: ''),
                'forge_operator_actor' => (string) ($this->option('forge-operator-actor') ?: ''),
                'forge_role' => (string) ($this->option('forge-role') ?: ''),
                'actor' => (string) $this->option('actor'),
                'workspace' => (string) ($this->option('repo-root') ?: ''),
            ]);
            $forgeAuthority = array_merge($forgeAuthority, (array) ($report['forge_inputs'] ?? []));
            $bootstrapSummary = [
                'status' => (string) ($report['status'] ?? 'blocked'),
                'ready_for_forge_owner_runtime' => (bool) ($report['ready_for_forge_owner_runtime'] ?? false),
                'forge_live_topology_injected' => array_key_exists('forge_live_topology', (array) ($report['forge_inputs'] ?? [])),
                'forge_live_decision_injected' => array_key_exists('forge_live_decision', (array) ($report['forge_inputs'] ?? [])),
                'blockers' => array_values((array) ($report['blockers'] ?? [])),
                'next_actions' => array_values((array) ($report['next_actions'] ?? [])),
                'evidence_refs' => array_values((array) ($report['evidence_refs'] ?? [])),
                'authority_is_real_or_blocked' => true,
                'provider_router_used' => false,
            ];
        }

        $input = array_merge([
            'area_id' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'scope_profile' => (string) $this->option('scope-profile'),
            'provider' => (string) $this->option('provider'),
            'model' => (string) ($this->option('model') ?: ''),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'actor' => (string) $this->option('actor'),
            'max_runtime_minutes' => (int) $this->option('max-runtime-minutes'),
            'max_cycles' => $this->option('max-cycles'),
            'max_merges' => $this->option('max-merges'),
            'max_findings' => (int) $this->option('max-findings'),
            'max_blocked_in_row' => (int) $this->option('max-blocked-in-row'),
            'sleep_seconds' => (int) $this->option('sleep-seconds'),
            'lock_lease_seconds' => (int) $this->option('lock-lease-seconds'),
            'execute' => (bool) $this->option('execute'),
            'auto_merge' => (bool) $this->option('auto-merge'),
            'allow_code_auto_merge' => (bool) $this->option('allow-code-auto-merge'),
            'allow_direct_provider_driver' => (bool) $this->option('allow-direct-provider-driver'),
            'continue_on_blocked' => (bool) $this->option('continue-on-blocked'),
            'multi_agent_workcell' => (bool) $this->option('multi-agent-workcell'),
            'pull_main' => (bool) $this->option('pull-main'),
            'cleanup_worktrees' => (bool) $this->option('cleanup-worktrees'),
            'record' => (bool) $this->option('record'),
            'dry_run' => (bool) $this->option('dry-run'),
        ], $forgeAuthority);

        $payload = $runner->run($input);
        $payload['forge_authority'] = [
            'obra_supplied' => array_key_exists('forge_obra', $forgeAuthority),
            'live_topology_supplied' => array_key_exists('forge_live_topology', $forgeAuthority),
            'live_topology_live' => (string) data_get($forgeAuthority, 'forge_live_topology.status', '') === 'live'
                || (bool) data_get($forgeAuthority, 'forge_live_topology.live', false),
            'live_decision_supplied' => array_key_exists('forge_live_decision', $forgeAuthority),
            'dispatch_mode' => (string) ($forgeAuthority['forge_dispatch_mode'] ?? 'forge_runtime_dispatch'),
            'role' => (string) ($forgeAuthority['forge_role'] ?? 'primary_builder'),
            'provider_authorization' => (bool) ($forgeAuthority['forge_provider_authorization'] ?? false),
            'budget_approved' => (bool) ($forgeAuthority['forge_budget_approved'] ?? false),
            'never_uses_direct_provider_router' => true,
        ];
        if ($bootstrapSummary !== null) {
            $payload['forge_authority_bootstrap'] = $bootstrapSummary;
        }

        $this->emit($payload);

        return in_array((string) ($payload['status'] ?? ''), [
            Reliable24hLoopRunnerService::STATUS_LOCK_HELD,
        ], true) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->components->twoColumnDetail('AP-790 reliable 24h loop', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Area / focus', (string) ($payload['area_id'] ?? '').' / '.(string) ($payload['focus'] ?? ''));
        $this->components->twoColumnDetail('Mode', (string) ($payload['mode'] ?? ''));
        $this->components->twoColumnDetail('Stop reason', (string) ($payload['stop_reason'] ?? ''));
        $this->components->twoColumnDetail('Cycles this run', (string) ($payload['cycles_this_run'] ?? 0));
        $this->components->twoColumnDetail('Resumed from', (string) ($payload['resumed_from_cycle_index'] ?? 0));
        $this->components->twoColumnDetail('Merges', (string) ($payload['merges_total'] ?? 0));
        $this->components->twoColumnDetail('Ledger', (string) ($payload['ledger_path'] ?? ''));
        foreach ((array) ($payload['next_actions'] ?? []) as $action) {
            $this->line('  next: '.(string) $action);
        }
    }
}
