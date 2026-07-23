<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSession;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Support\AtlasSecurity;
use Symfony\Component\Process\Process;

/**
 * AP-786 execution + summary projection section, extracted VERBATIM from
 * AutonomousEvolutionSessionService by the GOD-DEBULK split. Shapes the owner-flow
 * summary, the legacy diagnostic provider invocation, deterministic validation,
 * scoped sandbox commit, product/changed-file classification, validation-command
 * and forge-input normalization, and the selection scope claim. Provider-router
 * and git back-references are reached through {@see AutonomousEvolutionSessionService};
 * taxonomy classes are referenced qualified. productChangedFiles is internal to this
 * section (only changedFiles calls it).
 */
final class ExecutionSummarySection
{
    public function __construct(
        private readonly AutonomousEvolutionSessionService $parent,
    ) {}

    /**
     * @param  array<string,mixed>  $ownerFlow
     * @return array<string,mixed>
     */
    public function ownerFlowSummary(array $ownerFlow): array
    {
        $executionResult = is_array($ownerFlow['execution_result'] ?? null) ? $ownerFlow['execution_result'] : [];
        $repairAttempt = is_array($ownerFlow['repair_attempt'] ?? null) ? $ownerFlow['repair_attempt'] : [];
        $ownerProviderCalls = max(0, (int) data_get(
            $ownerFlow,
            'owner_result.runtime_invocation.command_result.owner_cli_provider_calls',
            (int) ($executionResult['owner_cli_provider_calls'] ?? 0)
        ));
        $repairProviderCalls = max(
            max(0, (int) ($repairAttempt['provider_calls_total'] ?? 0)),
            max(0, (int) ($repairAttempt['first_provider_calls'] ?? 0)) + max(0, (int) ($repairAttempt['repair_provider_calls'] ?? 0)),
        );
        $providerCalls = max($ownerProviderCalls, $repairProviderCalls, max(0, (int) ($ownerFlow['provider_calls_total'] ?? 0)));
        $providerInvoked = (bool) ($ownerFlow['provider_invoked'] ?? false)
            || (bool) ($executionResult['provider_invoked'] ?? false)
            || (bool) data_get($ownerFlow, 'owner_result.provider_invoked', false)
            || (bool) ($repairAttempt['provider_invoked_any_attempt'] ?? false)
            || $providerCalls > 0;

        return [
            'status' => (string) ($ownerFlow['status'] ?? ''),
            'uses_full_owner_runtime_chain' => (bool) ($ownerFlow['uses_full_owner_runtime_chain'] ?? false),
            'provider_router_used' => (bool) ($ownerFlow['provider_router_used'] ?? false),
            'provider_invoked' => $providerInvoked,
            'merge_allowed' => (bool) ($ownerFlow['merge_allowed'] ?? false),
            'consumption_id' => (string) ($ownerFlow['consumption_id'] ?? ''),
            'release_id' => (string) ($ownerFlow['release_id'] ?? ''),
            'queue_item_id' => (string) ($ownerFlow['queue_item_id'] ?? ''),
            'owner_execution_id' => (string) ($ownerFlow['owner_execution_id'] ?? ''),
            'owner_sandbox_run_id' => (string) ($ownerFlow['owner_sandbox_run_id'] ?? ''),
            'owner_result_status' => (string) data_get($ownerFlow, 'owner_result.result_status', ''),
            'ap750_result_bridge_status' => (string) data_get($ownerFlow, 'result_bridge.status', ''),
            'execution_result' => [
                'result_status' => (string) ($executionResult['result_status'] ?? ''),
                'provider_invoked' => $providerInvoked,
                'changed_files' => AreaFocusStringListNormalizer::coercedStringValues($executionResult['changed_files'] ?? []),
                'tests' => AreaFocusStringListNormalizer::coercedStringValues($executionResult['tests'] ?? []),
                // RSI Part B: real per-cycle provider-call telemetry (token-spend
                // proxy) surfaced so the ComponentValueLedger can attribute cost to
                // the live owner-flow component. Honest 0 when no provider ran.
                'owner_cli_provider_calls' => $providerCalls,
            ],
            'repair_attempt' => $repairAttempt,
            'steps' => array_values((array) ($ownerFlow['steps'] ?? [])),
            'blockers' => array_values((array) ($ownerFlow['blockers'] ?? [])),
            'senior_loop_exit_code' => data_get($ownerFlow, 'owner_result.runtime_invocation.command_result.exit_code'),
            'senior_loop_stderr_excerpt' => (function () use ($ownerFlow): ?string {
                $v = trim((string) data_get($ownerFlow, 'owner_result.runtime_invocation.command_result.stderr_excerpt', ''));

                return $v !== '' ? mb_substr($v, 0, 500) : null;
            })(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $decision
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    public function invokeProvider(array $input, array $decision, array $finding, array $allowedFiles, string $worktree): array
    {
        $prompt = [
            'schema_version' => 'atlas.software_company_stewardship.ap786_cursor_task.v1',
            'decision_receipt_id' => (string) $decision['decision_receipt_id'],
            'decision_receipt_hash' => (string) $decision['decision_receipt_hash'],
            'task' => [
                'title' => (string) ($finding['title'] ?? ''),
                'detail' => (string) ($finding['detail'] ?? ''),
                'why_it_matters' => (string) ($finding['why_it_matters'] ?? ''),
                'requested_outcome' => 'Implement the smallest correct fix inside allowed_files only. Prefer tests/docs when sufficient. Do not touch forbidden files. Do not merge, push, deploy or change secrets.',
            ],
            'scope_contract' => [
                'allowed_files' => $allowedFiles,
                'forbidden_files' => AutonomousEvolutionSessionService::FORBIDDEN_PATHS,
            ],
            'validation_commands' => (array) $input['validation_commands'],
        ];

        return $this->parent->providerRouter->driverInvoke((string) $input['provider'], [
            'provider' => (string) $input['provider'],
            'model' => (string) $input['model'],
            'prompt' => $prompt,
            'cwd' => $worktree,
            'workspace' => ['path' => $worktree],
            'timeout_seconds' => 900,
            'max_output_chars' => 24000,
            'decision_receipt_id' => (string) $decision['decision_receipt_id'],
            'decision_receipt_hash' => (string) $decision['decision_receipt_hash'],
        ]);
    }

    /**
     * @param  list<string>  $commands
     * @return array<string,mixed>
     */
    public function runValidation(array $commands, string $worktree): array
    {
        $results = [];
        $passed = true;
        foreach ($commands as $command) {
            $process = Process::fromShellCommandline($command, $worktree, AtlasSecurity::processEnv(profile: 'tool'));
            $process->setTimeout(180);
            $process->run();
            $ok = $process->isSuccessful();
            $passed = $passed && $ok;
            $results[] = [
                'command' => $command,
                'ok' => $ok,
                'exit_code' => $process->getExitCode(),
                'output_excerpt' => substr(AtlasSecurity::redactString(trim($process->getOutput()."\n".$process->getErrorOutput())), 0, 2000),
            ];
        }

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap786_validation.v1',
            'passed' => $commands === [] ? null : $passed,
            'commands' => $commands,
            'results' => $results,
        ];
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    public function commitSandbox(string $worktree, array $allowedFiles, array $finding): array
    {
        $changed = $this->changedFiles($worktree);
        if ($changed === []) {
            return ['status' => 'no_changes', 'changed_files' => []];
        }

        $unsafe = array_values(array_filter($changed, fn (string $file): bool => ! in_array($file, $allowedFiles, true)));
        if ($unsafe !== []) {
            return ['status' => 'blocked_scope_violation', 'changed_files' => $changed, 'unsafe_files' => $unsafe];
        }

        $add = $this->parent->git($worktree, array_merge(['add', '--'], $changed));
        if (! $add['ok']) {
            return ['status' => 'git_add_failed', 'git' => $add, 'changed_files' => $changed];
        }

        $title = trim((string) ($finding['title'] ?? 'Autonomous stewardship cycle'));
        $message = 'Atlas autonomous evolution: '.$title;
        $commit = $this->parent->git($worktree, ['commit', '-m', substr($message, 0, 180)]);

        return [
            'status' => $commit['ok'] ? 'committed' : 'git_commit_failed',
            'changed_files' => $changed,
            'git' => $commit,
            'commit_hash' => $commit['ok'] ? trim((string) $this->parent->git($worktree, ['rev-parse', 'HEAD'])['out']) : '',
        ];
    }

    /**
     * @return list<string>
     */
    public function changedFiles(string $worktree): array
    {
        $status = $this->parent->git($worktree, ['status', '--porcelain', '--untracked-files=all']);
        if (! $status['ok']) {
            return [];
        }
        $files = [];
        foreach (preg_split('/\R/', rtrim((string) $status['out'], "\r\n")) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $path = strlen($line) >= 4 && ctype_space($line[2])
                ? substr($line, 3)
                : preg_replace('/\A[ MADRCU?!]{1,2}\s+/', '', $line);
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            if (str_contains($path, ' -> ')) {
                $parts = explode(' -> ', $path);
                $path = trim((string) end($parts));
            }
            $files[] = $path;
        }

        return $this->productChangedFiles(array_values(array_filter($files)));
    }

    /**
     * Atlas control-plane files may be generated inside a sandbox to pass
     * provider contracts and receipts. They are not product changes and must
     * not be staged, committed, merged or counted as loop progress.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    private function productChangedFiles(array $files): array
    {
        return AreaFocusStringListNormalizer::uniqueStringValues(array_filter(
            $files,
            static fn (string $file): bool => ! str_starts_with($file, '.atlas/')
                && $file !== '.atlas'
        ));
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<string>
     */
    public function validationCommands(array $input): array
    {
        $commands = AreaFocusStringListNormalizer::preserveNonBlankStrings(array_map(
            fn (mixed $command): string => is_string($command) ? $this->parent->worktreeSafeValidationCommand($command) : '',
            (array) ($input['validation_commands'] ?? []),
        ));
        if ($commands === []) {
            $commands[] = 'git diff --check';
        }

        return $commands;
    }

    /**
     * Forge owner-runtime inputs forwarded to the AP-787 dispatch bridge. In
     * autonomous mode these are usually absent, so owner=forge blocks honestly
     * with a precise reason (forge_obra_required etc.) instead of being faked.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function forgeInputs(array $input): array
    {
        $forge = is_array($input['forge_inputs'] ?? null) ? $input['forge_inputs'] : [];
        foreach ([
            'forge_obra', 'obra_id', 'forge_live_topology', 'forge_live_decision',
            'forge_awis_ready', 'forge_dispatch_mode', 'forge_role',
            'forge_provider_authorization', 'forge_budget_approved',
            'forge_tickets', 'forge_agents',
        ] as $key) {
            if (array_key_exists($key, $input)) {
                $forge[$key] = $input[$key];
            }
        }

        return $forge;
    }

    /** @return array<string,mixed> */
    public function selectionScopeClaim(string $scopeProfile): array
    {
        if ($scopeProfile !== AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX) {
            return [
                'profile' => AutonomousEvolutionSessionService::SCOPE_BALANCED,
                'objective' => 'balanced autonomous area improvement',
            ];
        }

        return [
            'profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'objective' => 'maximize Atlas software factory power per cycle',
            'rejects' => [
                'docs_only',
                'missing_evidence_only',
                'cosmetic_or_surface_only',
                'work_without_direct_dev_forge_or_factory_runtime_impact',
            ],
            'requires' => [
                'direct runtime/test impact on AAEOS, Atlas Dev, Forge, provider routing, sandbox, merge, evidence, replay, priority, or scheduler',
                'isolated branch/worktree and merge governance',
            ],
        ];
    }

    /**
     * AP-786 session report projection: the deterministic base payload (status,
     * cycle rollup, claim policy) assembled from the run loop's locals. Pure — the
     * session_hash / generated_at stamping and the AP-795/AP-801 workcell attach
     * stay on the parent's run(). Threaded through a context array so the moved
     * body stays byte-behaviour-identical to the inline original.
     *
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed>
     */
    public function buildSessionReport(array $ctx): array
    {
        $status = $ctx['status'];
        $sessionId = $ctx['sessionId'];
        $areaId = $ctx['areaId'];
        $focus = $ctx['focus'];
        $provider = $ctx['provider'];
        $model = $ctx['model'];
        $scopeProfile = $ctx['scopeProfile'];
        $execute = $ctx['execute'];
        $record = $ctx['record'];
        $cyclesRequested = $ctx['cyclesRequested'];
        $cycles = $ctx['cycles'];
        $blockers = $ctx['blockers'];
        $continueOnBlocked = $ctx['continueOnBlocked'];
        $input = $ctx['input'];

        return [
            'schema_version' => AutonomousEvolutionSessionService::REPORT_SCHEMA,
            'ap_contract' => 'AP-786',
            'status' => $status,
            'session_id' => $sessionId,
            'area_id' => $areaId,
            'focus' => $focus,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-747', 'AP-748', 'AP-749', 'AP-750', 'AP-756', 'AP-757', 'AP-758', 'AP-759', 'AP-765', 'AP-769', 'AP-774', 'AP-785', 'AP-786'],
            'provider' => $provider,
            'model' => $model,
            'scope_profile' => $scopeProfile,
            'execute_requested' => $execute,
            'record_requested' => $record,
            'cycles_requested' => $cyclesRequested,
            'cycles_completed' => count(array_filter($cycles, static fn (array $c): bool => (string) ($c['final_status'] ?? '') === 'cycle_completed')),
            'cycles_waiting_review' => count(array_filter($cycles, static fn (array $c): bool => (string) ($c['final_status'] ?? '') === 'cycle_completed_waiting_review_or_merge')),
            'cycles_attempted' => count($cycles),
            'cycles' => $cycles,
            'blockers' => AreaFocusStringListNormalizer::uniqueStringValues($blockers),
            'next_actions' => $this->parent->nextActions($status, $blockers),
            'claim_policy' => [
                'atlas_owned_flow' => true,
                'uses_cursor_cli_account_driver' => $provider === 'cursor_cli',
                'requires_full_atlas_forge_owner_flow' => true,
                'requires_robust_obra_forge_quality_flow' => true,
                'direct_provider_driver_allowed' => (bool) ($input['allow_direct_provider_driver'] ?? false),
                'required_robust_flow_capabilities' => AutonomousEvolutionSessionService::REQUIRED_ROBUST_FLOW_CAPABILITIES,
                'provider_called' => $this->parent->anyCycleFlag($cycles, 'provider_called'),
                'branch_created' => $this->parent->anyCycleFlag($cycles, 'branch_created'),
                'worktree_created' => $this->parent->anyCycleFlag($cycles, 'worktree_created'),
                'inbox_emitted_before_merge_attempt' => true,
                'merge_performed' => $this->parent->anyCycleFlag($cycles, 'merge_performed'),
                'merge_policy' => 'AP-769/AP-774 ff-only only',
                'blocked_cycle_policy' => $continueOnBlocked ? 'record_inbox_keep_branch_isolated_and_continue' : 'stop_session_on_first_blocker',
                'selection_scope' => $this->selectionScopeClaim($scopeProfile),
                'deploy_performed' => false,
                'external_push_performed' => false,
                'secret_access' => false,
            ],
        ];
    }
}
