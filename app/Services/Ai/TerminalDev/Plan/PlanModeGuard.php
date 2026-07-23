<?php

namespace App\Services\Ai\TerminalDev\Plan;

use App\Services\Ai\Runtime\ToolInvocation;
use App\Services\Ai\Runtime\ToolResult;
use Illuminate\Support\Facades\File;

final class PlanModeGuard
{
    /**
     * In plan mode only session plan.md may be written; mutating tools otherwise fail.
     *
     * @param  array<string,mixed>  $session
     * @param  array<string,mixed>  $arguments
     */
    public function blockIfDisallowed(array $session, string $tool, array $arguments): ?ToolResult
    {
        $mutating = in_array($tool, ['file.write', 'file.patch', 'git.apply_patch', 'shell.run'], true);
        if (! $mutating) {
            return null;
        }

        // shell.run always blocked in plan mode (can mutate)
        if ($tool === 'shell.run') {
            return $this->deny($session, $tool, 'plan_mode_blocks_shell', 'Plan mode blocks shell.run. Write plan.md only, then exit plan mode.');
        }

        if (in_array($tool, ['file.write', 'file.patch'], true)) {
            $path = (string) ($arguments['path'] ?? '');
            $planPath = (string) ($session['plan_path'] ?? '');
            $workspace = (string) $session['workspace'];
            $resolved = $this->resolve($workspace, $path);
            $planResolved = realpath($planPath) ?: $planPath;

            // Allow writing the session plan file (absolute or basename plan.md under session dir)
            $sessionDir = (string) ($session['dir'] ?? '');
            $allowed = [
                $planResolved,
                $sessionDir.'/'.(string) config('atlas_terminal.plan.filename', 'plan.md'),
            ];
            foreach ($allowed as $ok) {
                if ($ok !== '' && $resolved !== '' && realpath($resolved) === realpath($ok)) {
                    return null;
                }
                if ($ok !== '' && $resolved === $ok) {
                    return null;
                }
            }

            // Allow relative path that equals plan filename only inside session dir — not workspace plan.md unless same file
            if ($path === (string) config('atlas_terminal.plan.filename', 'plan.md') && $sessionDir !== '') {
                // rewrite handled by caller ideally; still block workspace root plan unless it is the session plan
                if ($resolved === ($sessionDir.'/plan.md') || str_ends_with($resolved, '/.atlas/sessions') === false && str_contains($resolved, '/.atlas/sessions/')) {
                    return null;
                }
            }

            return $this->deny($session, $tool, 'plan_mode_blocks_write', 'Plan mode only allows writing the session plan.md. Path blocked: '.$path);
        }

        return $this->deny($session, $tool, 'plan_mode_blocks_mutate', "Plan mode blocks tool {$tool}.");
    }

    public function ensurePlanFile(array $session): string
    {
        $path = (string) ($session['plan_path'] ?? '');
        if ($path === '') {
            $path = rtrim((string) $session['dir'], '/').'/plan.md';
        }
        if (! File::exists($path)) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, "# Plan\n\n_Empty — agent will write the approach here._\n");
        }

        return $path;
    }

    /**
     * @param  array<string,mixed>  $session
     */
    private function deny(array $session, string $tool, string $code, string $message): ToolResult
    {
        $invocation = ToolInvocation::make($tool, (string) $session['workspace'], [], [
            'source' => 'atlas_terminal',
            'session_id' => (string) ($session['id'] ?? null),
        ]);

        return ToolResult::failure($invocation, $code, $message);
    }

    private function resolve(string $workspace, string $path): string
    {
        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($workspace, '/').'/'.$path;
    }
}
