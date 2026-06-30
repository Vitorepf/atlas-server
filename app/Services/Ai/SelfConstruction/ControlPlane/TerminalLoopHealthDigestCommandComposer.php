<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

/**
 * CLI command-synthesis and token-sanitization for the Agent Control Plane
 * terminal-loop health digest.
 *
 * Extracted from AgentControlPlaneTerminalLoopHealthDigestService to reduce
 * the god-class. All methods are stateless.
 */
final class TerminalLoopHealthDigestCommandComposer
{
    public const PHP_BIN = '/opt/homebrew/bin/php';

    /**
     * @param  list<string>  $queueTags
     * @return array<string, string>
     */
    public function commands(string $actor, int $targetMinClaimable, int $maxNewTasks, array $queueTags): array
    {
        $php = self::PHP_BIN;
        $actor = $this->safeCommandToken($actor, 'operator');
        $tagArgs = $this->queueTagArgs($queueTags);

        return [
            'preview_bootstrap' => $php.' artisan atlas:ai:self-construction --agent-control-plane-terminal-worker-bootstrap-status --terminal-worker-bootstrap-preview --actor='.$actor.' --target-min-claimable-tasks='.$targetMinClaimable.' --max-new-tasks='.$maxNewTasks.$tagArgs.' --json',
            'execute_bootstrap' => $php.' artisan atlas:ai:self-construction --agent-control-plane-terminal-worker-bootstrap-status --actor='.$actor.' --target-min-claimable-tasks='.$targetMinClaimable.' --max-new-tasks='.$maxNewTasks.$tagArgs.' --json',
            'replenish_tasks' => $php.' artisan atlas:ai:self-construction --agent-control-plane-task-auto-replenishment-status --actor='.$actor.' --target-min-claimable-tasks='.$targetMinClaimable.' --max-new-tasks='.$maxNewTasks.$tagArgs.' --json',
            'inspect_or_recover_leases' => $php.' artisan atlas:ai:self-construction --agent-control-plane-task-lease-recovery-status --actor='.$actor.' --reason=terminal_loop_health_digest'.$tagArgs.' --json',
            'inspect_queue' => $php.' artisan atlas:ai:self-construction --agent-control-plane-task-packet-queue-status --json',
            'inspect_leases' => $php.' artisan atlas:ai:self-construction --agent-control-plane-claim-lease-runtime-status --json',
            'terminal_loop_health_digest' => $php.' artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-health-digest-status --actor='.$actor.' --target-min-claimable-tasks='.$targetMinClaimable.' --max-new-tasks='.$maxNewTasks.$tagArgs.' --json',
            'worker_task_eligibility_certification' => $php.' artisan atlas:ai:self-construction --agent-control-plane-worker-task-eligibility-certification-status --actor='.$actor.' --target-min-claimable-tasks='.$targetMinClaimable.$tagArgs.' --json',
            'multi_agent_certification' => $php.' artisan atlas:ai:self-construction --agent-control-plane-multi-agent-loop-certification-status --agent-count=6 --cycles=2 --target-min-claimable-tasks=6 --json',
            'sweep_malformed' => $php.' artisan atlas:ai:self-construction --agent-control-plane-malformed-packet-sweep-status --json',
            'queued_targets' => $php.' artisan atlas:ai:self-construction --agent-control-plane-queued-targets-status'.$tagArgs.' --json',
        ];
    }

    /**
     * @param  list<string>  $queueTags
     */
    public function queueTagArgs(array $queueTags): string
    {
        if ($queueTags === []) {
            return '';
        }

        return ' '.implode(' ', array_map(
            fn (string $tag): string => '--queue-tag='.$this->safeCommandToken($tag, 'queue'),
            $queueTags,
        ));
    }

    public function safeCommandToken(string $value, string $default): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.:@\/-]/', '-', trim($value)) ?: '';
        $safe = trim($safe, '-');

        return $safe === '' ? $default : $safe;
    }
}