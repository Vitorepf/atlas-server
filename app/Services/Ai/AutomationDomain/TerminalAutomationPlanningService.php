<?php

namespace App\Services\Ai\AutomationDomain;

use App\Models\AiAutomationPlan;
use App\Models\AiAutomationRun;

/**
 * Plans terminal/shell automation flows. Never runs a command. Produces a
 * structured plan with destructive-flag detection, allowed working_dir
 * (workspace-scoped), sandbox preference and rollback strategy.
 *
 * Conservative policy: ANY command containing destructive patterns is
 * `blocked` until Policy approves. Read-only commands (ls/grep/find/cat
 * inside the workspace) can be `planned` directly.
 */
class TerminalAutomationPlanningService
{
    public const DESTRUCTIVE_PATTERNS = [
        'rm -rf',
        'rm -fr',
        'sudo ',
        'mkfs',
        '> /dev/',
        'dd if=',
        'chmod -R 777',
        'curl ',
        'wget ',
        'kill -9',
        'shutdown',
        'reboot',
        ':(){',
        'git push --force',
        'git reset --hard',
        'git clean -fd',
        'docker rm -f',
        'helm uninstall',
    ];

    public function __construct(private readonly AutomationPlanService $plans) {}

    /**
     * @param  array<string,mixed>  $args
     */
    public function plan(AiAutomationRun $run, array $args): AiAutomationPlan
    {
        $command = (string) ($args['command'] ?? '');
        if ($command === '') {
            throw AutomationDomainException::missingField('command');
        }
        $workingDir = (string) ($args['working_dir'] ?? '');
        if ($workingDir === '') {
            throw AutomationDomainException::missingField('working_dir');
        }

        $destructive = $this->detectDestructive($command);
        $networkIO = $this->detectNetwork($command);
        $sandbox = (string) ($args['sandbox'] ?? 'workspace_jail');
        $readOnly = (bool) ($args['read_only'] ?? (! $destructive && ! $networkIO));

        $safetyFactors = [
            'destructive' => $destructive,
            'network_io' => $networkIO,
            'sandbox' => $sandbox,
            'read_only' => $readOnly,
            'working_dir' => $workingDir,
            'timeout_seconds' => (int) ($args['timeout_seconds'] ?? 60),
        ];

        $status = ($destructive || $networkIO)
            ? AutomationDomainCanon::PLAN_STATUS_BLOCKED
            : AutomationDomainCanon::PLAN_STATUS_PLANNED;

        $payload = [
            'command' => $command,
            'working_dir' => $workingDir,
            'env' => $args['env'] ?? [],
            'expected_exit_code' => (int) ($args['expected_exit_code'] ?? 0),
            'evidence_kinds' => ['command', 'receipt', 'artifact'],
            'tool_capability_id' => $args['tool_capability_id'] ?? 'command.local_readonly',
        ];

        return $this->plans->record($run, [
            'plan_type' => AutomationDomainCanon::PLAN_TERMINAL,
            'title' => (string) ($args['title'] ?? 'Terminal automation'),
            'summary' => $args['summary'] ?? null,
            'payload' => $payload,
            'safety_factors' => $safetyFactors,
            'rollback' => $args['rollback'] ?? ($destructive ? [
                'strategy' => 'workspace_snapshot_restore',
                'snapshot_before' => true,
            ] : ['strategy' => 'noop_read_only']),
            'status' => $status,
        ]);
    }

    public function detectDestructive(string $command): bool
    {
        $normalised = strtolower($command);
        foreach (self::DESTRUCTIVE_PATTERNS as $pattern) {
            if (str_contains($normalised, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    public function detectNetwork(string $command): bool
    {
        $normalised = strtolower($command);
        foreach (['curl ', 'wget ', 'http://', 'https://', 'ssh ', 'scp ', 'rsync '] as $pattern) {
            if (str_contains($normalised, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
