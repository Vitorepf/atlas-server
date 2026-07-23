<?php

declare(strict_types=1);

namespace App\Services\Ai\AobgWorkspaceOnboarding;

use App\Services\Ai\AtlasAobgWorkspaceOnboardingService as Facade;
use App\Services\Ai\Instrumentation\AtlasProviderProjectionService;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Provider-bootstrap family for the AOBG workspace-onboarding façade: writes/updates the
 * `.mcp.json`, `.claude/settings.json` and AGENTS.md / CLAUDE.md provider files and keeps
 * the managed projection in sync. Depends only on the provider-projection service + the
 * façade static constants; never on another section.
 */
class ProviderBootstrapSection
{
    public function __construct(
        private readonly AtlasProviderProjectionService $providerProjection,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function writeProviderBootstrap(string $workspacePath, string $workspaceId, bool $force = false): array
    {
        $files = [
            'mcp' => $this->upsertMcpJson($workspacePath, $force),
            'claude_settings' => $this->upsertClaudeSettings($workspacePath),
            'agents' => $this->upsertProviderDoc($workspacePath, 'AGENTS.md', $workspaceId),
            'claude' => $this->upsertProviderDoc($workspacePath, 'CLAUDE.md', $workspaceId),
        ];

        $ok = collect($files)->every(fn (array $result): bool => ($result['ok'] ?? false) === true);

        return [
            'ok' => $ok,
            'reason' => $ok ? 'provider_bootstrap_ready' : 'provider_bootstrap_incomplete',
            'atlas_server_dir' => base_path(),
            'files' => $files,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function upsertMcpJson(string $workspacePath, bool $force): array
    {
        $path = $workspacePath.DIRECTORY_SEPARATOR.'.mcp.json';
        $json = $this->readJsonObject($path);
        if ($json === null) {
            return ['ok' => false, 'path' => $path, 'action' => 'skipped_invalid_json'];
        }

        $before = $json;
        if (! is_array($json['mcpServers'] ?? null)) {
            $json['mcpServers'] = [];
        }
        $server = [
            'command' => base_path('bin/atlas'),
            'args' => ['open-brain', 'mcp'],
        ];
        if ($force || (($json['mcpServers']['atlas-open-brain'] ?? null) !== $server)) {
            $json['mcpServers']['atlas-open-brain'] = $server;
        }

        return $this->writeJsonIfChanged($path, $before, $json);
    }

    /**
     * @return array<string,mixed>
     */
    private function upsertClaudeSettings(string $workspacePath): array
    {
        $path = $workspacePath.DIRECTORY_SEPARATOR.'.claude'.DIRECTORY_SEPARATOR.'settings.json';
        $json = $this->readJsonObject($path);
        if ($json === null) {
            return ['ok' => false, 'path' => $path, 'action' => 'skipped_invalid_json'];
        }

        $before = $json;
        $json['$comment'] = is_string($json['$comment'] ?? null)
            ? $json['$comment']
            : 'Atlas Open Brain Gateway hooks installed by workspace activation. Hooks live in atlas-server and scope calls to the opened workspace.';
        if (! is_array($json['hooks'] ?? null)) {
            $json['hooks'] = [];
        }

        $hookBase = base_path('.claude/hooks');
        $json = $this->ensureClaudeHook($json, 'UserPromptSubmit', $hookBase.'/atlas-ctx.sh');
        $json = $this->ensureClaudeHook($json, 'PreToolUse', $hookBase.'/atlas-pretooluse-guard.sh', 'Edit|Write|MultiEdit|NotebookEdit');
        $json = $this->ensureClaudeHook($json, 'PostToolUse', $hookBase.'/atlas-postedit-context.sh', 'Read|Edit|Write|MultiEdit|NotebookEdit');
        $json = $this->ensureClaudeHook($json, 'Stop', $hookBase.'/atlas-session-capture.sh');

        return $this->writeJsonIfChanged($path, $before, $json);
    }

    /**
     * @param  array<string,mixed>  $settings
     * @return array<string,mixed>
     */
    private function ensureClaudeHook(array $settings, string $event, string $command, ?string $matcher = null): array
    {
        $groups = is_array($settings['hooks'][$event] ?? null) ? $settings['hooks'][$event] : [];
        foreach ($groups as $group) {
            if (! is_array($group) || ! is_array($group['hooks'] ?? null)) {
                continue;
            }
            foreach ($group['hooks'] as $hook) {
                if (is_array($hook) && ($hook['command'] ?? null) === $command) {
                    $settings['hooks'][$event] = $groups;

                    return $settings;
                }
            }
        }

        $group = ['hooks' => [['type' => 'command', 'command' => $command]]];
        if ($matcher !== null) {
            $group = ['matcher' => $matcher] + $group;
        }
        $groups[] = $group;
        $settings['hooks'][$event] = $groups;

        return $settings;
    }

    /**
     * @return array<string,mixed>|null null means invalid JSON; [] means missing file.
     */
    private function readJsonObject(string $path): ?array
    {
        if (! is_file($path)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     * @return array<string,mixed>
     */
    private function writeJsonIfChanged(string $path, array $before, array $after): array
    {
        $action = is_file($path) ? 'unchanged' : 'created';
        if ($before !== $after || ! is_file($path)) {
            $this->writeTextFile($path, (string) json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
            $action = is_file($path) && $before !== [] ? 'updated' : 'created';
        }

        return ['ok' => true, 'path' => $path, 'action' => $action];
    }

    /**
     * @return array<string,mixed>
     */
    private function upsertProviderDoc(string $workspacePath, string $filename, string $workspaceId): array
    {
        $path = $workspacePath.DIRECTORY_SEPARATOR.$filename;
        $block = $this->providerBootstrapBlock($workspaceId, $workspacePath, $filename);
        $contents = is_file($path) ? (string) file_get_contents($path) : '';
        $action = 'unchanged';

        if ($contents === '') {
            $contents = '# '.$filename.' generated by Atlas AOBG activation'.PHP_EOL.PHP_EOL.$block.PHP_EOL;
            $action = 'created';
        } elseif (str_contains($contents, Facade::MANAGED_BLOCK_START) && str_contains($contents, Facade::MANAGED_BLOCK_END)) {
            $updated = preg_replace(
                '/'.preg_quote(Facade::MANAGED_BLOCK_START, '/').'.*?'.preg_quote(Facade::MANAGED_BLOCK_END, '/').'/s',
                $block,
                $contents,
            ) ?? $contents;
            if ($updated !== $contents) {
                $contents = $updated;
                $action = 'updated';
            }
        } else {
            $contents = rtrim($contents).PHP_EOL.PHP_EOL.$block.PHP_EOL;
            $action = 'appended';
        }

        if ($action !== 'unchanged') {
            $this->writeTextFile($path, $contents);
        }

        $projection = $this->ensureProviderProjection($workspacePath, $filename);

        return [
            'ok' => ($projection['ok'] ?? false) === true,
            'path' => $path,
            'action' => $action,
            'projection' => $projection,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ensureProviderProjection(string $workspacePath, string $filename): array
    {
        $target = $filename === 'AGENTS.md' ? 'agents' : 'claude';
        $context = ['workspace' => $workspacePath];
        $options = ['workspace' => $workspacePath];

        try {
            $inspection = $this->providerProjection->inspect($target, $context, $options);
            if (($inspection['managed'] ?? false) !== true) {
                $result = $this->providerProjection->adopt($target, $context, $options);

                return [
                    'ok' => ($result['written'] ?? false) === true,
                    'action' => 'adopted',
                    'target' => $target,
                    'path' => $result['path'] ?? $workspacePath.DIRECTORY_SEPARATOR.$filename,
                    'written' => (bool) ($result['written'] ?? false),
                    'reason' => $result['error'] ?? null,
                ];
            }

            if (($inspection['manual_drift'] ?? false) === true) {
                return [
                    'ok' => false,
                    'action' => 'blocked_manual_drift',
                    'target' => $target,
                    'path' => $inspection['path'] ?? $workspacePath.DIRECTORY_SEPARATOR.$filename,
                    'reason' => $inspection['reason'] ?? 'checksum_mismatch',
                ];
            }

            if (($inspection['stale'] ?? false) === true) {
                $result = $this->providerProjection->write($target, $context, $options);

                return [
                    'ok' => ($result['written'] ?? false) === true,
                    'action' => 'updated',
                    'target' => $target,
                    'path' => $result['path'] ?? $workspacePath.DIRECTORY_SEPARATOR.$filename,
                    'written' => (bool) ($result['written'] ?? false),
                    'reason' => $result['error'] ?? null,
                ];
            }

            return [
                'ok' => true,
                'action' => 'ready',
                'target' => $target,
                'path' => $inspection['path'] ?? $workspacePath.DIRECTORY_SEPARATOR.$filename,
                'written' => false,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'action' => 'projection_exception',
                'target' => $target,
                'path' => $workspacePath.DIRECTORY_SEPARATOR.$filename,
                'exception' => class_basename($e),
            ];
        }
    }

    private function providerBootstrapBlock(string $workspaceId, string $workspacePath, string $filename): string
    {
        $atlas = base_path('bin/atlas');

        return Facade::MANAGED_BLOCK_START.PHP_EOL
            .'## Atlas Open Brain Gateway'.PHP_EOL
            .'- This workspace is activated as `'.$workspaceId.'` at `'.$workspacePath.'`.'.PHP_EOL
            .'- Atlas memory/context is canonical; this provider file is only a compact bootstrap.'.PHP_EOL
            .'- At session start or before context-sensitive implementation, run `'.$atlas.' aobg workspace activate --json` from this workspace.'.PHP_EOL
            .'- Before architecture or implementation work, request context with `'.$atlas.' open-brain context "<task>" --json` or MCP `atlas_context_pack`.'.PHP_EOL
            .'- If MCP native transport fails, use the CLI fallback above; it scopes to the current directory automatically.'.PHP_EOL
            .'- Treat AOBG output as provider-safe curated top-K context, then verify with direct file reads, `rg`, tests, and Atlas gates.'.PHP_EOL
            .'- Do not expose Atlas internal ids, traces, prompts, or provider details unless the operator asks for audit.'.PHP_EOL
            .PHP_EOL
            .'Provider target: `'.$filename.'`.'.PHP_EOL
            .Facade::MANAGED_BLOCK_END;
    }

    private function writeTextFile(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            File::ensureDirectoryExists($directory);
        }
        File::put($path, $contents);
    }
}
