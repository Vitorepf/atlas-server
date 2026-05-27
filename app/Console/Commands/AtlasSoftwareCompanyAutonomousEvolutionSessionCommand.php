<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use Illuminate\Console\Command;

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
        {--pull-main : Pull/update main after a successful merge}
        {--record : Append AP-786 session receipt}
        {--max-findings=40 : Maximum findings to scan before priority ranking}
        {--max-auto-merge-files=5 : AP-769 max changed files for auto-merge}
        {--validation-command=* : Validation command(s) run in sandbox; default git diff --check}
        {--json : Emit JSON}';

    protected $description = 'AP-786 · run an Atlas-owned autonomous evolution session with Cursor CLI, Inbox, governed ff-only merge and loop continuation.';

    public function handle(AutonomousEvolutionSessionService $service): int
    {
        $payload = $service->run([
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
            'pull_main' => (bool) $this->option('pull-main'),
            'record' => (bool) $this->option('record'),
            'max_findings' => (int) $this->option('max-findings'),
            'max_auto_merge_files' => (int) $this->option('max-auto-merge-files'),
            'validation_commands' => array_values(array_filter((array) $this->option('validation-command'), 'is_string')),
        ]);

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
                $antiFake['direct_provider_driver_allowed'] ? 'true' : 'false',
                $antiFake['requires_full_atlas_forge_owner_flow'] ? 'true' : 'false',
                $antiFake['robust_contract_required'] ? 'true' : 'false',
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
                ((bool) ($cycle['merge_performed'] ?? false)) ? 'yes' : 'no',
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
}
