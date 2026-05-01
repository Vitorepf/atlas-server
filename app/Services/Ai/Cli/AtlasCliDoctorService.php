<?php

namespace App\Services\Ai\Cli;

use App\Services\Ai\AiProviderHealthService;
use App\Services\Ai\Scheduling\AtlasSchedulerInstallService;
use App\Services\Ai\Skills\SkillDiscoveryService;
use Illuminate\Support\Facades\Schema;

class AtlasCliDoctorService
{
    public function __construct(
        private readonly AtlasCliSetupService $setup,
        private readonly AiProviderHealthService $health,
        private readonly AtlasCliProviderStrategyService $providers,
        private readonly AtlasCliQualityService $quality,
        private readonly SkillDiscoveryService $skills,
        private readonly AtlasSchedulerInstallService $schedulerInstall,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function diagnose(string $workspace, bool $refreshProviders = false, bool $runTests = false): array
    {
        $workspace = $this->resolveWorkspace($workspace);

        if ($refreshProviders) {
            $this->health->checkAll();
        }

        return $this->payload(
            workspace: $workspace,
            setup: $this->setup->diagnose(),
            providerStrategy: $this->providers->recommend('dev'),
            quality: $this->quality->compact($this->quality->evaluate($workspace, $runTests, approved: true)),
            skills: $this->skills->health($workspace),
            scheduler: $this->schedulerGate(),
        );
    }

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, mixed>  $providerStrategy
     * @param  array<string, mixed>  $quality
     * @param  array<string, mixed>  $skills
     * @param  array<string, mixed>  $scheduler
     * @return array<string, mixed>
     */
    private function payload(string $workspace, array $setup, array $providerStrategy, array $quality, array $skills, array $scheduler): array
    {
        $permission = $this->permissionGate($workspace);
        $gates = [
            [
                'name' => 'provider_binaries',
                'status' => data_get($setup, 'summary.has_any_ready_provider') ? 'passed' : 'failed',
                'detail' => data_get($setup, 'summary.ready_count', 0).' provider(s) resolvido(s).',
            ],
            [
                'name' => 'provider_health',
                'status' => data_get($providerStrategy, 'has_online_provider') ? 'passed' : 'failed',
                'detail' => (string) data_get($providerStrategy, 'reason', 'Sem health de provider.'),
            ],
            [
                'name' => 'council_capacity',
                'status' => data_get($setup, 'summary.council_ready') ? 'passed' : 'needs_review',
                'detail' => data_get($setup, 'summary.council_ready')
                    ? 'Claude e Codex disponiveis para revisao cruzada.'
                    : 'Conselho critico precisa de Claude e Codex resolvidos.',
            ],
            $permission,
            [
                'name' => 'skills_health',
                'status' => (string) data_get($skills, 'status', 'failed'),
                'detail' => data_get($skills, 'count', 0).' skill bundle(s) carregado(s).',
                'warnings' => data_get($skills, 'warnings', []),
                'errors' => data_get($skills, 'errors', []),
            ],
            $scheduler,
            [
                'name' => 'workspace_quality',
                'status' => $this->workspaceQualityStatus($quality),
                'detail' => $this->workspaceQualityDetail($quality),
            ],
        ];

        $readiness = $this->readiness($gates);

        return [
            'ok' => $readiness['status'] !== 'failed',
            'generated_at' => now()->toJSON(),
            'workspace' => $workspace,
            'setup' => $setup,
            'provider_strategy' => $providerStrategy,
            'permission' => $permission,
            'skills' => $skills,
            'scheduler' => $scheduler,
            'quality' => $quality,
            'readiness' => $readiness,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function schedulerGate(): array
    {
        $cron = $this->schedulerInstall->inspect();
        $hasTable = Schema::hasTable('ai_scheduled_tasks');
        $status = match (true) {
            ! $hasTable => 'failed',
            (bool) ($cron['installed'] ?? false) => 'passed',
            default => 'needs_review',
        };

        return [
            'name' => 'scheduler_cron',
            'status' => $status,
            'detail' => match ($status) {
                'passed' => 'Scheduler local instalado para executar atlas schedule a cada minuto.',
                'failed' => 'Tabela ai_scheduled_tasks ausente; rode migrations.',
                default => 'Instale o crontab com atlas bootstrap --install-scheduler-cron --strict.',
            },
            'cron' => $cron,
            'has_table' => $hasTable,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function permissionGate(string $workspace): array
    {
        $roots = array_values(array_filter((array) config('atlas.ai.tool_permissions.allowed_roots', [])));
        $resolvedWorkspace = realpath($workspace) ?: $workspace;
        $allowed = collect($roots)->contains(function (string $root) use ($resolvedWorkspace): bool {
            $resolvedRoot = realpath($root) ?: $root;

            return $resolvedWorkspace === $resolvedRoot || str_starts_with($resolvedWorkspace, rtrim($resolvedRoot, '/').'/');
        });

        return [
            'name' => 'permission_scope',
            'status' => $allowed ? 'passed' : 'failed',
            'detail' => $allowed
                ? 'Workspace dentro dos roots permitidos.'
                : 'Workspace fora de ATLAS_AI_TOOL_ALLOWED_ROOTS.',
            'workspace' => $resolvedWorkspace,
            'allowed_roots' => $roots,
            'default_mode' => config('atlas.ai.tool_permissions.default_mode', 'read'),
            'allow_danger' => (bool) config('atlas.ai.tool_permissions.allow_danger', false),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $gates
     * @return array<string, mixed>
     */
    private function readiness(array $gates): array
    {
        $score = collect($gates)->sum(fn (array $gate): int => match ($gate['status'] ?? null) {
            'passed' => 20,
            'needs_review' => 10,
            default => 0,
        });

        $statuses = collect($gates)->pluck('status');
        $status = match (true) {
            $statuses->contains('failed') => 'failed',
            $statuses->contains('needs_review') => 'needs_review',
            default => 'passed',
        };

        return [
            'status' => $status,
            'score' => $score,
            'gates' => $gates,
            'next_actions' => $this->nextActions($gates),
        ];
    }

    /**
     * @param  array<string, mixed>  $quality
     */
    private function workspaceQualityStatus(array $quality): string
    {
        $gateStatuses = collect((array) ($quality['quality_gates'] ?? []))->pluck('status');
        if ($gateStatuses->contains('failed')) {
            return 'failed';
        }

        if ((bool) data_get($quality, 'test_result.ok', false)) {
            return 'passed';
        }

        return $quality['status'] === 'passed' ? 'passed' : (string) $quality['status'];
    }

    /**
     * @param  array<string, mixed>  $quality
     */
    private function workspaceQualityDetail(array $quality): string
    {
        $summary = (string) data_get($quality, 'completion_packet.summary', 'Workspace quality indisponivel.');
        if ((bool) data_get($quality, 'test_result.ok', false)) {
            return $summary;
        }

        $command = data_get($quality, 'test_result.command');
        $error = data_get($quality, 'test_result.error');
        if (! is_string($command) && ! is_string($error)) {
            return $summary;
        }

        $parts = [$summary];
        if (is_string($command) && trim($command) !== '') {
            $parts[] = 'comando: '.$command;
        }
        if (is_string($error) && trim($error) !== '') {
            $parts[] = 'erro: '.str($this->failureHeadline($error))->limit(220);
        }
        $artifactPath = data_get($quality, 'test_result.artifact_path');
        if (is_string($artifactPath) && trim($artifactPath) !== '') {
            $parts[] = 'log: '.$artifactPath;
        }

        return implode(' ', $parts);
    }

    private function failureHeadline(string $error): string
    {
        $plain = preg_replace('/\x1B(?:[@-Z\\\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $error) ?? $error;
        $lines = array_values(array_filter(
            array_map(fn (string $line): string => trim($line), explode("\n", str_replace("\r", '', $plain))),
            fn (string $line): bool => $line !== ''
        ));

        $patterns = [
            '/^\s*(FAIL|FAILED|ERROR|ERRORS)\b/i',
            '/\bTests:\s+.*\b(failed|errored|errors?)\b/i',
            '/\b(failed|errored|failure|error):\b/i',
            '/\b(Fatal error|Parse error|TypeError|RuntimeException|Exception)\b/i',
            '/Failed asserting\b/i',
        ];

        foreach (array_reverse($lines) as $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    return $line;
                }
            }
        }

        return $lines !== [] ? (string) end($lines) : 'Erro de teste sem detalhes.';
    }

    /**
     * @param  array<int, array<string, mixed>>  $gates
     * @return array<int, string>
     */
    private function nextActions(array $gates): array
    {
        $actions = [];
        foreach ($gates as $gate) {
            if (($gate['status'] ?? null) === 'passed') {
                continue;
            }

            $actions[] = match ($gate['name'] ?? null) {
                'provider_binaries', 'provider_health', 'council_capacity' => 'Rode atlas bootstrap --refresh-providers --strict.',
                'permission_scope' => 'Ajuste ATLAS_AI_TOOL_ALLOWED_ROOTS e rode atlas bootstrap --strict.',
                'skills_health' => 'Rode atlas skills doctor e corrija bundles com erro ou quarentena.',
                'scheduler_cron' => 'Rode php artisan migrate e depois atlas bootstrap --install-scheduler-cron --strict.',
                'workspace_quality' => 'Rode atlas bootstrap --doctor-run-tests --strict antes de declarar final.',
                default => 'Revise o gate '.$gate['name'].' e rode atlas bootstrap --strict.',
            };
        }

        return array_values(array_unique($actions));
    }

    private function resolveWorkspace(string $workspace): string
    {
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }
}
