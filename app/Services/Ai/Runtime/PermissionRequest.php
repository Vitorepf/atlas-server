<?php

namespace App\Services\Ai\Runtime;

class PermissionRequest
{
    /**
     * @param  array<int,string>  $paths
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tool,
        public readonly string $workspace,
        public readonly string $requiredMode,
        public readonly string $risk,
        public readonly string $reason,
        public readonly array $paths = [],
        public readonly ?string $command = null,
        public readonly array $metadata = [],
    ) {}

    public static function fromInvocation(ToolInvocation $invocation): self
    {
        $tool = $invocation->tool;
        $command = is_string($invocation->argument('command')) ? $invocation->argument('command') : null;
        $paths = collect([
            $invocation->argument('path'),
            ...((array) $invocation->argument('paths', [])),
            ...self::pathsFromPatch(is_string($invocation->argument('patch')) ? $invocation->argument('patch') : ''),
        ])
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->unique()
            ->values()
            ->all();

        [$requiredMode, $risk, $reason] = match ($tool) {
            'workspace.profile', 'package.detect', 'git.status', 'git.diff', 'session.search', 'search.rg', 'file.read', 'programming.git_diff', 'programming.code_search' => [
                'read',
                'low',
                'Leitura/inspecao local sem escrita planejada.',
            ],
            'file.write', 'file.patch', 'git.apply_patch', 'checkpoint.restore' => [
                'write',
                'medium',
                'Alteracao de arquivos dentro do workspace.',
            ],
            'test.run', 'programming.test', 'programming.lint', 'programming.quality_scan', 'programming.visual_smoke' => [
                'write',
                'medium',
                'Comando de validacao pode criar caches, snapshots ou artefatos locais.',
            ],
            'shell.run' => self::classifyShell($command),
            default => [
                'danger',
                'high',
                'Ferramenta desconhecida exige permissao perigosa por seguranca.',
            ],
        };

        return new self(
            id: $invocation->id,
            tool: $tool,
            workspace: $invocation->workspace,
            requiredMode: $requiredMode,
            risk: $risk,
            reason: $reason,
            paths: $paths,
            command: $command,
            metadata: [
                'dry_run' => $invocation->dryRun,
                'source' => $invocation->source,
            ],
        );
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private static function classifyShell(?string $command): array
    {
        $command = trim((string) $command);
        $lower = strtolower($command);

        foreach ([
            '/\brm\s+-rf\b/',
            '/\bsudo\b/',
            '/\bchmod\s+-r\b/',
            '/\bchown\s+-r\b/',
            '/\bdd\s+/',
            '/\bmkfs\b/',
            '/\bdiskutil\b/',
            '/\bgit\s+reset\s+--hard\b/',
            '/\bgit\s+checkout\s+--\b/',
            '/\bgit\s+clean\b.*(-f|--force)/',
            '/curl\b.*\|\s*(sh|bash)/',
            '/wget\b.*\|\s*(sh|bash)/',
        ] as $pattern) {
            if (preg_match($pattern, $lower) === 1) {
                return ['danger', 'high', 'Comando shell classificado como perigoso.'];
            }
        }

        foreach ([
            '/(^|[\s;&|])(?:touch|mkdir|mv|cp|rsync|ln)\b/',
            '/(^|[\s;&|])(?:sed|perl)\b.*\s-i\b/',
            '/(^|[\s;&|])tee\b/',
            '/(^|[^<])>>?\s*[^\s&|]+/',
            '/\b(?:composer)\s+(?:install|update|require|remove|dump-autoload)\b/',
            '/\b(?:npm|pnpm|yarn|bun)\s+(?:install|add|remove|update|run|exec|dlx)\b/',
            '/\bphp\s+artisan\s+(?:migrate|db:seed|cache:|config:|route:|view:|queue:|storage:link)\b/',
            '/\b(?:make|cmake|cargo|go|gradle|mvn)\b/',
            '/\bgit\s+(?:add|commit|merge|rebase|cherry-pick|stash|pull|push|apply)\b/',
            '/\b(?:test|phpunit|pest|pint|eslint|prettier|tsc|vite|jest|vitest)\b/',
        ] as $pattern) {
            if (preg_match($pattern, $lower) === 1) {
                return ['write', 'medium', 'Comando shell pode executar scripts ou alterar estado local.'];
            }
        }

        return ['read', 'low', 'Comando shell classificado como leitura/inspecao.'];
    }

    /**
     * @return array<int,string>
     */
    private static function pathsFromPatch(string $patch): array
    {
        if ($patch === '') {
            return [];
        }

        preg_match_all('/^(?:---|\+\+\+)\s+(?:a|b)\/(.+)$/m', $patch, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $path): string => trim($path))
            ->reject(fn (string $path): bool => $path === '' || $path === '/dev/null')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tool' => $this->tool,
            'workspace' => $this->workspace,
            'required_mode' => $this->requiredMode,
            'risk' => $this->risk,
            'reason' => $this->reason,
            'paths' => $this->paths,
            'command' => $this->command,
            'metadata' => $this->metadata,
        ];
    }
}
