<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeLiveAuthorityBootstrapService;
use Illuminate\Console\Command;
use App\Support\YesNo;

final class AtlasSoftwareCompanyAutonomousEvolutionSessionCommand extends Command
{
    /** Canonical AP-786 owner-flow chain that must be the authority for provider execution. */
    private const OWNER_FLOW_CHAIN = ['AP-747', 'AP-756', 'AP-757', 'AP-749', 'AP-758', 'AP-759', 'AP-750'];

    protected $signature = 'atlas:software-company-stewardship:autonomous-evolution-session
        {--area=agentic_engineering_os : Canonical area_id}
        {--focus=dev_forge : Area focus slice}
        {--cycles=1 : Maximum cycles for this invocation}
        {--provider=cursor_cli : Atlas provider driver}
        {--model= : Provider model; defaults to cursor_cli configured model}
        {--scope-profile=balanced : Selection scope profile: balanced or factory_max}
        {--repo-root= : Git repository root}
        {--actor=operator : Operator/session actor}
        {--execute : Actually materialize sandbox, call provider, commit, emit inbox and evaluate merge}
        {--auto-merge : Ask AP-769/AP-774 to ff-only merge eligible branches}
        {--allow-code-auto-merge : Allow AP-774 bugfix/cleanup code auto-merge when validation passes}
        {--allow-direct-provider-driver : Legacy diagnostic only; bypasses the full owner-flow gate and must not be used to claim full Atlas Forge execution}
        {--continue-on-blocked : Keep selecting the next eligible finding when a cycle is blocked or waiting review}
        {--multi-agent-workcell : AP-801 project each executed cycle through the multi-agent lane workcell (context_scout -> architect -> implementer -> reviewer -> repair -> judge); composes the cycle owner-runtime result and never invokes a provider itself}
        {--pull-main : Pull/update main after a successful merge}
        {--record : Append AP-786 session receipt}
        {--max-findings=40 : Maximum findings to scan before priority ranking}
        {--max-auto-merge-files=5 : AP-769 max changed files for auto-merge}
        {--validation-command=* : Validation command(s) run in sandbox; default git diff --check}
        {--forge-obra= : AP-788 real governed Obra UUID for owner=forge; never fabricated}
        {--forge-live-topology-json= : AP-788 live Forge provider topology JSON object (requires status=live)}
        {--forge-live-decision-json= : AP-788 live Forge decision JSON object (requires decision + operator_actor)}
        {--forge-dispatch-mode= : AP-788 forge_runtime_dispatch (default) | forge_parallel_durable | forge_provider_invoke}
        {--forge-role= : AP-788 Forge role: primary_builder|critical_reviewer|context_scout|repair_agent|local_tool_runner}
        {--forge-provider-authorization : AP-788 explicit provider-execution authorization (only for forge_provider_invoke)}
        {--forge-budget-approved : AP-788 explicit budget approval (only for forge_provider_invoke)}
        {--bootstrap-forge-authority : AP-789 derive REAL forge live topology/decision/AWIS readiness for --forge-obra and inject when ready; blocks honestly otherwise}
        {--forge-operator-actor= : AP-789 operator actor authorizing forge dispatch; defaults to --actor; never fabricated}
        {--json : Emit JSON}';

    protected $description = 'AP-786 · run an Atlas-owned autonomous evolution session with Cursor CLI, Inbox, governed ff-only merge and loop continuation.';

    public function handle(AutonomousEvolutionSessionService $service, ForgeLiveAuthorityBootstrapService $forgeBootstrap): int
    {
        // AP-788: parse the governed Forge execution authority BEFORE running, so
        // malformed JSON fails fast and clearly and no cycle is ever attempted.
        try {
            $forgeAuthority = self::parseForgeAuthority([
                'obra' => $this->option('forge-obra'),
                'topology_json' => $this->option('forge-live-topology-json'),
                'decision_json' => $this->option('forge-live-decision-json'),
                'dispatch_mode' => $this->option('forge-dispatch-mode'),
                'role' => $this->option('forge-role'),
                'provider_authorization' => (bool) $this->option('forge-provider-authorization'),
                'budget_approved' => (bool) $this->option('forge-budget-approved'),
            ]);
        } catch (\JsonException $e) {
            $this->line(json_encode([
                'schema_version' => 'atlas.software_company_stewardship.command_error.v1',
                'status' => 'blocked',
                'reason' => 'forge_authority_json_invalid',
                'detail' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        // AP-789: derive REAL forge live authority and inject only what is real.
        // Synthetic shape never yields readiness; partial/blocked is surfaced.
        $bootstrapSummary = null;
        if ((bool) $this->option('bootstrap-forge-authority')) {
            $report = $forgeBootstrap->bootstrap([
                'forge_obra' => (string) ($this->option('forge-obra') ?: ''),
                'forge_operator_actor' => (string) ($this->option('forge-operator-actor') ?: ''),
                'forge_role' => (string) ($this->option('forge-role') ?: ''),
                'actor' => (string) $this->option('actor'),
                'workspace' => (string) ($this->option('repo-root') ?: ''),
            ]);
            // Inject ONLY the real authority the bootstrap actually derived.
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

        $payload = $service->run(array_merge([
            'area_id' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'cycles' => (int) $this->option('cycles'),
            'provider' => (string) $this->option('provider'),
            'model' => (string) ($this->option('model') ?: ''),
            'scope_profile' => (string) $this->option('scope-profile'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'actor' => (string) $this->option('actor'),
            'execute' => (bool) $this->option('execute'),
            'auto_merge' => (bool) $this->option('auto-merge'),
            'allow_code_auto_merge' => (bool) $this->option('allow-code-auto-merge'),
            'allow_direct_provider_driver' => (bool) $this->option('allow-direct-provider-driver'),
            'continue_on_blocked' => (bool) $this->option('continue-on-blocked'),
            'multi_agent_workcell' => (bool) $this->option('multi-agent-workcell'),
            'pull_main' => (bool) $this->option('pull-main'),
            'record' => (bool) $this->option('record'),
            'max_findings' => (int) $this->option('max-findings'),
            'max_auto_merge_files' => (int) $this->option('max-auto-merge-files'),
            'validation_commands' => array_values(array_filter((array) $this->option('validation-command'), 'is_string')),
        ], $forgeAuthority));

        // AP-788: surface what Forge authority was injected (presence only, never
        // the decision contents) and prove the path never uses the provider router.
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

        // AP-789: surface the live authority bootstrap outcome (status/blockers only,
        // never the decision contents) so a synthetic shape can never look ready.
        if ($bootstrapSummary !== null) {
            $payload['forge_authority_bootstrap'] = $bootstrapSummary;
        }

        // Anti-fake proof surfaced at the top of every report (JSON + human) so a
        // cycle can never look "done" without proving the full owner-flow.
        $antiFake = [
            'direct_provider_driver_allowed' => (bool) data_get($payload, 'claim_policy.direct_provider_driver_allowed', false),
            'requires_full_atlas_forge_owner_flow' => (bool) data_get($payload, 'claim_policy.requires_full_atlas_forge_owner_flow', true),
            'robust_contract_required' => (bool) data_get($payload, 'claim_policy.requires_robust_obra_forge_quality_flow', true),
            'owner_flow_chain' => self::OWNER_FLOW_CHAIN,
        ];
        $payload['anti_fake_proof'] = $antiFake;

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['status'] ?? '') === AutonomousEvolutionSessionService::STATUS_BLOCKED
                ? self::FAILURE
                : self::SUCCESS;
        }

        $this->components->twoColumnDetail('AP-786 session', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Session', (string) ($payload['session_id'] ?? ''));
        $this->components->twoColumnDetail('Provider', (string) ($payload['provider'] ?? '').' / '.(string) ($payload['model'] ?? ''));
        $this->components->twoColumnDetail('Cycles', (string) ($payload['cycles_completed'] ?? 0).' completed / '.(string) ($payload['cycles_attempted'] ?? 0).' attempted');
        $this->components->twoColumnDetail(
            'Anti-fake proof',
            sprintf(
                'direct_provider_driver_allowed=%s · full_owner_flow_required=%s · robust_contract_required=%s',
                YesNo::trueFalse($antiFake['direct_provider_driver_allowed']),
                YesNo::trueFalse($antiFake['requires_full_atlas_forge_owner_flow']),
                YesNo::trueFalse($antiFake['robust_contract_required']),
            ),
        );

        foreach ((array) ($payload['cycles'] ?? []) as $cycle) {
            $finalStatus = (string) ($cycle['final_status'] ?? '');
            $this->line(sprintf(
                '  #%d %s · %s · branch=%s · inbox=%s · merged=%s',
                (int) ($cycle['cycle_index'] ?? 0),
                $finalStatus,
                (string) data_get($cycle, 'selected_finding.title', ''),
                (string) ($cycle['branch_ref'] ?? ''),
                (string) ($cycle['inbox_item_id'] ?? ''),
                YesNo::format((bool) ($cycle['merge_performed'] ?? false)),
            ));

            // When a cycle blocks because the full Atlas Forge owner-flow is not
            // the execution authority, make it explicit and DO NOT suggest the
            // legacy direct provider driver as the way forward.
            $gateReason = (string) data_get($cycle, 'flow_integrity_gate.blocked_reason', '');
            $blockedByOwnerFlow = $gateReason === 'full_atlas_forge_flow_required'
                || in_array('full_atlas_forge_flow_required', (array) ($cycle['blockers'] ?? []), true);
            if ($blockedByOwnerFlow) {
                $chain = (array) data_get($cycle, 'flow_integrity_gate.required_chain', self::OWNER_FLOW_CHAIN);
                $this->warn('     blocked: full Atlas Forge owner-flow required before any provider execution.');
                $this->warn('     required owner-flow chain: '.implode(' -> ', array_map('strval', $chain)));
                $this->line('     fix: wire the AP-747 -> AP-750 owner-flow as the execution authority, then re-run.');
                $this->line('     note: --allow-direct-provider-driver is legacy diagnostic only; it does NOT satisfy this gate or prove autonomous Atlas Forge.');
            }
        }
        foreach ((array) ($payload['blockers'] ?? []) as $blocker) {
            $this->warn('  blocker: '.(string) $blocker);
        }

        return ($payload['status'] ?? '') === AutonomousEvolutionSessionService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * AP-788: build the governed Forge execution authority that gets forwarded,
     * unchanged, into AutonomousEvolutionSessionService::run() (and from there to
     * AP-787). Only keys that were actually supplied are returned, so a missing
     * Obra still blocks honestly downstream — nothing is fabricated here.
     *
     * @param  array{obra?:mixed,topology_json?:mixed,decision_json?:mixed,dispatch_mode?:mixed,role?:mixed,provider_authorization?:bool,budget_approved?:bool}  $raw
     * @return array<string,mixed>
     *
     * @throws \JsonException when a --forge-*-json flag is not a valid JSON object.
     */
    public static function parseForgeAuthority(array $raw): array
    {
        $forge = [];

        $obra = trim((string) ($raw['obra'] ?? ''));
        if ($obra !== '') {
            $forge['forge_obra'] = $obra;
        }

        $topology = self::decodeJsonObjectFlag($raw['topology_json'] ?? null, '--forge-live-topology-json');
        if ($topology !== null) {
            $forge['forge_live_topology'] = $topology;
        }

        $decision = self::decodeJsonObjectFlag($raw['decision_json'] ?? null, '--forge-live-decision-json');
        if ($decision !== null) {
            $forge['forge_live_decision'] = $decision;
        }

        $mode = trim((string) ($raw['dispatch_mode'] ?? ''));
        if ($mode !== '') {
            $forge['forge_dispatch_mode'] = $mode;
        }

        $role = trim((string) ($raw['role'] ?? ''));
        if ($role !== '') {
            $forge['forge_role'] = $role;
        }

        if (! empty($raw['provider_authorization'])) {
            $forge['forge_provider_authorization'] = true;
        }
        if (! empty($raw['budget_approved'])) {
            $forge['forge_budget_approved'] = true;
        }

        return $forge;
    }

    /**
     * @return array<string,mixed>|null
     *
     * @throws \JsonException with a clear, flag-named message on invalid JSON.
     */
    private static function decodeJsonObjectFlag(mixed $value, string $flag): ?array
    {
        $json = is_string($value) ? trim($value) : '';
        if ($json === '') {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \JsonException($flag.' is not valid JSON: '.$e->getMessage(), (int) $e->getCode());
        }

        // Must be a JSON object, not a list/scalar — AP-787 reads keyed fields
        // (status, decision, operator_actor) from it.
        if (! is_array($decoded) || ! str_starts_with(ltrim($json), '{')) {
            throw new \JsonException($flag.' must be a JSON object.');
        }

        return $decoded;
    }
}
