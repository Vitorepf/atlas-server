<?php

namespace App\Services\Ai\TerminalDev\Tools;

use App\Services\Ai\Runtime\AiToolRuntime;
use App\Services\Ai\Runtime\ToolInvocation;
use App\Services\Ai\Runtime\ToolResult;
use App\Services\Ai\TerminalDev\Plan\PlanModeGuard;

/**
 * Thin host over AiToolRuntime for Terminal Dev sessions.
 */
final class TerminalToolHost
{
    /** @var list<string> */
    public const CORE_TOOLS = [
        'file.read',
        'file.write',
        'file.patch',
        'search.rg',
        'shell.run',
        'git.status',
        'git.diff',
        'workspace.profile',
    ];

    public function __construct(
        private readonly AiToolRuntime $runtime,
        private readonly PlanModeGuard $planGuard,
    ) {}

    /**
     * @return list<string>
     */
    public function available(): array
    {
        return self::CORE_TOOLS;
    }

    /**
     * @param  array<string,mixed>  $session
     * @param  array<string,mixed>  $arguments
     * @param  array<string,mixed>  $options
     */
    public function execute(array $session, string $tool, array $arguments = [], array $options = []): ToolResult
    {
        $workspace = (string) $session['workspace'];
        $mode = (string) ($session['mode'] ?? 'normal');

        if ($mode === 'plan') {
            $blocked = $this->planGuard->blockIfDisallowed($session, $tool, $arguments);
            if ($blocked !== null) {
                return $blocked;
            }
        }

        $permissionMode = (string) ($session['permission_mode'] ?? 'write');
        if ((bool) ($session['yolo'] ?? false)) {
            $permissionMode = 'danger';
        }

        $invocation = ToolInvocation::make($tool, $workspace, $arguments, [
            'permission_mode' => $permissionMode,
            'source' => 'atlas_terminal',
            'session_id' => (string) ($session['id'] ?? null),
            'dry_run' => (bool) ($options['dry_run'] ?? false),
            'metadata' => array_merge([
                // Only pass trace_id when it is a real ai_traces row; session ids are NOT traces.
                'trace_id' => is_string($options['trace_id'] ?? null) ? $options['trace_id'] : null,
                'approved' => (bool) ($session['yolo'] ?? false) || (bool) ($options['approved'] ?? false),
                'approval_source' => ($session['yolo'] ?? false) ? 'yolo' : ($options['approval_source'] ?? null),
            ], is_array($options['metadata'] ?? null) ? $options['metadata'] : []),
        ]);

        return $this->runtime->execute($invocation);
    }
}
