<?php

namespace App\Console\Commands;

use App\Services\Ai\Cli\AtlasCliSetupService;
use Illuminate\Console\Command;

class AtlasCliSetupCommand extends Command
{
    protected $signature = 'atlas:cli:setup
        {--provider= : claude_cli, codex_cli or gemini_cli}
        {--claude-bin= : Absolute path or command name for Claude Code CLI}
        {--codex-bin= : Absolute path or command name for Codex CLI}
        {--gemini-bin= : Absolute path or command name for Gemini CLI}
        {--env-path= : Custom .env path for tests or controlled setup}
        {--write-env : Persist resolved provider binaries into .env}
        {--strict : Return failure when no provider is ready}
        {--json : Print machine-readable JSON}';

    protected $description = 'Diagnose and configure Atlas CLI provider binaries for terminal use.';

    public function handle(AtlasCliSetupService $setup): int
    {
        $provider = $this->normalizeProvider($this->option('provider'));
        $overrides = $this->overrides();

        $diagnosis = $setup->diagnose($provider, $overrides);
        $write = null;

        if ((bool) $this->option('write-env')) {
            $write = $setup->writeEnv($diagnosis, $this->option('env-path') ? (string) $this->option('env-path') : null);
            $diagnosis['write_env'] = $write;
        }

        $ok = (bool) data_get($diagnosis, 'summary.has_any_ready_provider', false);

        if ((bool) $this->option('json')) {
            $this->line(json_encode(array_merge(['ok' => $ok], $diagnosis), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $ok || ! (bool) $this->option('strict') ? self::SUCCESS : self::FAILURE;
        }

        $this->renderDiagnosis($diagnosis);

        if ($write) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>.env</>', (string) $write['env_path']);
            $this->line((string) $write['message']);
            foreach ((array) ($write['updates'] ?? []) as $update) {
                $this->line('  '.$update['key'].'='.$update['value']);
            }
        }

        return $ok || ! (bool) $this->option('strict') ? self::SUCCESS : self::FAILURE;
    }

    private function normalizeProvider(mixed $provider): ?string
    {
        $provider = is_string($provider) ? trim($provider) : '';
        if ($provider === '') {
            return null;
        }

        return match ($provider) {
            'claude' => 'claude_cli',
            'codex' => 'codex_cli',
            'gemini' => 'gemini_cli',
            default => $provider,
        };
    }

    /**
     * @return array<string, string>
     */
    private function overrides(): array
    {
        return array_filter([
            'claude_cli' => is_string($this->option('claude-bin')) ? trim((string) $this->option('claude-bin')) : null,
            'codex_cli' => is_string($this->option('codex-bin')) ? trim((string) $this->option('codex-bin')) : null,
            'gemini_cli' => is_string($this->option('gemini-bin')) ? trim((string) $this->option('gemini-bin')) : null,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $diagnosis
     */
    private function renderDiagnosis(array $diagnosis): void
    {
        $summary = (array) ($diagnosis['summary'] ?? []);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas CLI Setup</>', now()->toJSON());
        $this->components->twoColumnDetail('Providers prontos', (string) ($summary['ready_count'] ?? 0));
        $this->components->twoColumnDetail('Dev pronto', ((bool) ($summary['dev_ready'] ?? false)) ? 'sim' : 'nao');
        $this->components->twoColumnDetail('Conselho pronto', ((bool) ($summary['council_ready'] ?? false)) ? 'sim' : 'nao');
        $this->components->twoColumnDetail('Proximo comando', (string) ($summary['recommended_next_command'] ?? '-'));

        $providers = (array) ($diagnosis['providers'] ?? []);
        if ($providers !== []) {
            $this->newLine();
            $this->table(
                ['provider', 'status', 'configurado', 'resolvido', 'env'],
                collect($providers)->map(fn (array $provider): array => [
                    $provider['provider'] ?? '-',
                    $provider['status'] ?? '-',
                    $provider['configured_binary'] ?? '-',
                    $provider['resolved_binary'] ?? '-',
                    $provider['env_key'] ?? '-',
                ])->all(),
            );
        }

        foreach ($providers as $provider) {
            if (($provider['status'] ?? null) === 'ready') {
                continue;
            }

            $this->newLine();
            $this->warn(($provider['label'] ?? $provider['provider'] ?? 'Provider').' nao resolvido.');
            foreach ((array) ($provider['next_steps'] ?? []) as $step) {
                $this->line('  - '.$step);
            }
        }
    }
}
