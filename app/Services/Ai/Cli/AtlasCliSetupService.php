<?php

namespace App\Services\Ai\Cli;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;

class AtlasCliSetupService
{
    /**
     * @return array<string, mixed>
     */
    public function diagnose(?string $provider = null, array $overrides = []): array
    {
        $providers = collect($this->providerDefinitions())
            ->when($provider, fn ($items) => $items->only($provider))
            ->map(fn (array $definition, string $key): array => $this->providerStatus($key, $definition, $overrides[$key] ?? null))
            ->values()
            ->all();

        $ready = collect($providers)->where('status', 'ready');
        $missing = collect($providers)->where('status', 'missing');
        $codexReady = $ready->contains(fn (array $item): bool => ($item['provider'] ?? null) === 'codex_cli');
        $claudeReady = $ready->contains(fn (array $item): bool => ($item['provider'] ?? null) === 'claude_cli');

        return [
            'generated_at' => now()->toJSON(),
            'env_path' => base_path('.env'),
            'providers' => $providers,
            'summary' => [
                'ready_count' => $ready->count(),
                'missing_count' => $missing->count(),
                'has_any_ready_provider' => $ready->isNotEmpty(),
                'dev_ready' => $codexReady || $claudeReady,
                'codex_ready' => $codexReady,
                'claude_ready' => $claudeReady,
                'council_ready' => $codexReady && $claudeReady,
                'recommended_next_command' => $ready->isEmpty()
                    ? 'atlas bootstrap'
                    : 'atlas bootstrap --refresh-providers',
            ],
            'requirements' => [
                [
                    'name' => 'chat_basico',
                    'ready' => $ready->isNotEmpty(),
                    'description' => 'Pelo menos um provider local precisa estar resolvido para o Atlas responder pelo terminal.',
                ],
                [
                    'name' => 'dev_pesado',
                    'ready' => $codexReady || $claudeReady,
                    'description' => 'Atlas dev precisa de Codex CLI ou Claude Code CLI resolvido para executar trabalho real.',
                ],
                [
                    'name' => 'conselho_critico',
                    'ready' => $codexReady && $claudeReady,
                    'description' => 'Fluxos críticos ficam mais fortes quando Claude e Codex estão disponíveis para revisão cruzada.',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $diagnosis
     * @return array<string, mixed>
     */
    public function writeEnv(array $diagnosis, ?string $envPath = null, bool $operatorMode = false, ?string $operatorRoot = null): array
    {
        $envPath = $envPath ?: base_path('.env');
        $updates = [];
        $skipped = [];
        $hadExistingEnv = File::exists($envPath);
        $contents = $hadExistingEnv
            ? File::get($envPath)
            : $this->initialEnvContents();
        $originalContents = $contents;

        foreach ((array) ($diagnosis['providers'] ?? []) as $provider) {
            $envKey = (string) ($provider['env_key'] ?? '');
            $resolved = (string) ($provider['resolved_binary'] ?? '');

            if ($envKey === '' || $resolved === '') {
                $skipped[] = [
                    'provider' => $provider['provider'] ?? null,
                    'reason' => 'binary_not_resolved',
                ];

                continue;
            }

            $updates[$envKey] = $resolved;
        }

        $trustedRoot = $this->trustedWorkspaceRoot($operatorRoot);
        if ($trustedRoot !== null) {
            $updates['ATLAS_AI_TOOL_ALLOWED_ROOTS'] = implode(',', $this->mergedAllowedRoots($contents, $trustedRoot));
        }

        if ($operatorMode) {
            $updates['ATLAS_AI_TOOL_PERMISSION_MODE'] = 'danger';
            $updates['ATLAS_AI_TOOL_ALLOW_DANGER'] = 'true';
            $updates['ATLAS_AI_ALLOW_UNSANDBOXED_WRITE'] = 'true';
        }

        if ($updates === []) {
            return [
                'env_path' => $envPath,
                'written' => false,
                'updates' => [],
                'skipped' => $skipped,
                'message' => 'Nenhum binario resolvido para gravar no .env.',
            ];
        }

        foreach ($updates as $key => $value) {
            $line = $key.'='.$this->formatEnvValue($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*/m';
            if (preg_match($pattern, $contents) === 1) {
                $contents = preg_replace($pattern, $line, $contents) ?? $contents;
            } else {
                $contents = rtrim($contents).PHP_EOL.$line.PHP_EOL;
            }
        }

        if ($contents === $originalContents) {
            return [
                'env_path' => $envPath,
                'written' => false,
                'updates' => collect($updates)
                    ->map(fn (string $value, string $key): array => ['key' => $key, 'value' => $value])
                    ->values()
                    ->all(),
                'skipped' => $skipped,
                'backup_path' => null,
                'message' => 'Configuracao do .env ja estava atualizada. O fluxo recomendado continua sendo atlas bootstrap.',
            ];
        }

        $backupPath = null;
        if ($hadExistingEnv) {
            $backupPath = $envPath.'.atlas-backup-'.now()->format('YmdHis');
            File::put($backupPath, $originalContents);
        }

        File::put($envPath, $contents);

        return [
            'env_path' => $envPath,
            'written' => true,
            'updates' => collect($updates)
                ->map(fn (string $value, string $key): array => ['key' => $key, 'value' => $value])
                ->values()
                ->all(),
            'skipped' => $skipped,
            'backup_path' => $backupPath,
            'message' => $operatorMode
                ? 'Configuracao de providers, workspace roots e modo operador gravada no .env.'
                : 'Configuracao de providers e workspace roots gravada no .env. O fluxo recomendado continua sendo atlas bootstrap --refresh-providers.',
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function providerDefinitions(): array
    {
        return [
            'claude_cli' => [
                'label' => 'Claude Code CLI',
                'env_key' => 'ATLAS_AI_CLAUDE_BIN',
                'config_key' => 'atlas.ai.providers.claude_cli.binary',
                'default_binary' => 'claude',
                'preferred_for' => 'planejamento, revisão, conversas longas e crítica de produto',
            ],
            'codex_cli' => [
                'label' => 'Codex CLI',
                'env_key' => 'ATLAS_AI_CODEX_BIN',
                'config_key' => 'atlas.ai.providers.codex_cli.binary',
                'default_binary' => 'codex',
                'preferred_for' => 'desenvolvimento pesado, edição de código e validação técnica',
            ],
        ];
    }

    /**
     * @param  array<string, string>  $definition
     * @return array<string, mixed>
     */
    private function providerStatus(string $provider, array $definition, ?string $override): array
    {
        $configured = trim((string) ($override ?: config($definition['config_key'], $definition['default_binary'])));
        $resolution = $this->resolveBinary($configured);
        $ready = $resolution['path'] !== null;

        return [
            'provider' => $provider,
            'label' => $definition['label'],
            'status' => $ready ? 'ready' : 'missing',
            'configured_binary' => $configured,
            'resolved_binary' => $resolution['path'],
            'resolution_source' => $resolution['source'],
            'env_key' => $definition['env_key'],
            'preferred_for' => $definition['preferred_for'],
            'candidate_paths' => $resolution['candidates'],
            'next_steps' => $this->nextSteps($definition['env_key'], $configured, $resolution['path']),
        ];
    }

    /**
     * @return array{path: ?string, source: string, candidates: array<int, string>}
     */
    private function resolveBinary(string $binary): array
    {
        $expanded = $this->expandHome($binary);
        $candidates = $this->candidatePaths($binary);

        if ($this->looksLikePath($expanded)) {
            return [
                'path' => is_executable($expanded) ? realpath($expanded) ?: $expanded : null,
                'source' => is_executable($expanded) ? 'configured_path' : 'configured_path_missing',
                'candidates' => $candidates,
            ];
        }

        $finder = new ExecutableFinder;
        $found = $finder->find($binary, null, $this->extraSearchDirs());
        if (is_string($found) && $found !== '') {
            return [
                'path' => $found,
                'source' => 'path',
                'candidates' => $candidates,
            ];
        }

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return [
                    'path' => realpath($candidate) ?: $candidate,
                    'source' => 'candidate_path',
                    'candidates' => $candidates,
                ];
            }
        }

        return [
            'path' => null,
            'source' => 'not_found',
            'candidates' => $candidates,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function nextSteps(string $envKey, string $configured, ?string $resolved): array
    {
        if ($resolved) {
            return [
                "{$envKey}={$resolved}",
                'atlas bootstrap',
                'atlas bootstrap --refresh-providers',
            ];
        }

        return [
            "Instale ou localize o binario {$configured}.",
            "Configure {$envKey}=/caminho/absoluto/para/{$configured}.",
            "Rode atlas bootstrap --{$this->optionNameForEnvKey($envKey)}=/caminho/absoluto.",
        ];
    }

    private function optionNameForEnvKey(string $envKey): string
    {
        return match ($envKey) {
            'ATLAS_AI_CLAUDE_BIN' => 'claude-bin',
            'ATLAS_AI_CODEX_BIN' => 'codex-bin',
            default => 'provider-bin',
        };
    }

    private function looksLikePath(string $binary): bool
    {
        return str_contains($binary, '/') || str_contains($binary, '\\');
    }

    private function expandHome(string $path): string
    {
        if (! str_starts_with($path, '~/')) {
            return $path;
        }

        $home = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');
        if ($home === '') {
            return $path;
        }

        return $home.substr($path, 1);
    }

    /**
     * @return array<int, string>
     */
    private function extraSearchDirs(): array
    {
        $home = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');

        return array_values(array_filter(array_unique([
            '/opt/homebrew/bin',
            '/usr/local/bin',
            '/usr/bin',
            '/bin',
            $home !== '' ? $home.'/.local/bin' : null,
            $home !== '' ? $home.'/.npm-global/bin' : null,
            $home !== '' ? $home.'/Library/pnpm' : null,
        ])));
    }

    /**
     * @return array<int, string>
     */
    private function candidatePaths(string $binary): array
    {
        $name = basename($binary);

        return collect($this->extraSearchDirs())
            ->map(fn (string $dir): string => $dir.'/'.$name)
            ->values()
            ->all();
    }

    private function initialEnvContents(): string
    {
        $example = base_path('.env.example');
        if (File::exists($example)) {
            return File::get($example);
        }

        return '';
    }

    private function trustedWorkspaceRoot(?string $operatorRoot = null): ?string
    {
        $candidate = is_string($operatorRoot) && trim($operatorRoot) !== ''
            ? $this->expandHome(trim($operatorRoot))
            : dirname(dirname(dirname(base_path())));

        $resolved = realpath($candidate);

        return $resolved && is_dir($resolved) ? $resolved : null;
    }

    /**
     * @return array<int,string>
     */
    private function mergedAllowedRoots(string $contents, string $trustedRoot): array
    {
        $existing = $this->envValue($contents, 'ATLAS_AI_TOOL_ALLOWED_ROOTS');
        $roots = [$trustedRoot];
        $roots = array_merge($roots, $existing
            ? array_map('trim', explode(',', $existing))
            : []);

        $roots[] = dirname(base_path());
        $roots[] = base_path();

        return collect($roots)
            ->filter(fn (mixed $root): bool => is_string($root) && trim($root) !== '')
            ->map(fn (string $root): string => $this->expandHome(trim($root)))
            ->map(fn (string $root): ?string => ($resolved = realpath($root)) && is_dir($resolved) ? $resolved : null)
            ->filter(fn (?string $root): bool => is_string($root) && $root !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function envValue(string $contents, string $key): ?string
    {
        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $matches) !== 1) {
            return null;
        }

        $value = trim((string) $matches[1]);
        if ($value === '') {
            return '';
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        return stripcslashes($value);
    }

    private function formatEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/\s|#|"|\'/', $value) !== 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\"', '\$'], $value).'"';
    }
}
