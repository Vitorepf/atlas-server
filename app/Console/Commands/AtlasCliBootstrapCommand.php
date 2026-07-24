<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Services\Ai\AiProviderHealthService;
use App\Services\Ai\Instrumentation\AtlasProviderProjectionService;
use App\Services\Ai\Cli\AtlasCliDoctorService;
use App\Services\Ai\Cli\AtlasCliInstallService;
use App\Services\Ai\Cli\AtlasCliSetupService;
use App\Services\Ai\Scheduling\AtlasSchedulerInstallService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

class AtlasCliBootstrapCommand extends Command
{
    use ReadsNonEmptyStringOption;

    protected $signature = 'atlas:cli:bootstrap
        {--target= : Symlink target. Defaults to ~/.local/bin/atlas}
        {--claude-bin= : Absolute path or command name for Claude Code CLI}
        {--codex-bin= : Absolute path or command name for Codex CLI}
        {--gemini-bin= : Absolute path or command name for Gemini CLI}
        {--env-path= : Custom .env path}
        {--workspace= : Workspace path for the automatic final doctor. Defaults to current directory}
        {--force : Replace an existing launcher target}
        {--dry-run : Plan every step without writing}
        {--no-write-env : Do not persist provider binary resolution into .env}
        {--no-doctor : Skip the automatic final atlas doctor --strict check}
        {--doctor-run-tests : Run tests inside the automatic final doctor}
        {--write-shell-profile : Add Atlas target directory to the detected shell profile}
        {--shell-profile= : Shell profile path used with --write-shell-profile}
        {--install-scheduler-cron : Install the Laravel scheduler crontab entry used by atlas schedule}
        {--no-scheduler-cron-check : Skip scheduler crontab inspection}
        {--provider-projection=skip : Explicit provider projection action: skip, status, review, apply, write or adopt}
        {--provider-projection-target=all : Projection target: claude, agents or all}
        {--provider-projection-max-lines= : Maximum lines per generated projection file}
        {--provider-projection-memory-limit= : Maximum provider-safe memories to project}
        {--provider-projection-force : Allow provider projection write/adopt to overwrite unsafe files}
        {--provider-projection-yes : Confirm provider projection apply without interactive prompt}
        {--refresh-providers : Run provider health checks after setup}
        {--operator-mode : Enable explicit local operator mode for Atlas dev sessions}
        {--enable-operator-mode : Alias for --operator-mode}
        {--operator-root= : Trusted root for Atlas workspaces. Defaults to the current macOS user home}
        {--strict : Return failure when bootstrap readiness is incomplete}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run the professional Atlas CLI configuration and installation bootstrap.';

    public function handle(
        AtlasCliSetupService $setup,
        AtlasCliInstallService $installer,
        AiProviderHealthService $health,
        AtlasCliDoctorService $doctor,
        AtlasSchedulerInstallService $schedulerInstall,
        AtlasProviderProjectionService $providerProjection,
    ): int {
        $projectionAction = $this->providerProjectionAction();
        if ($projectionAction === null) {
            $this->error('Valor invalido para --provider-projection. Use skip, status, review, apply, write ou adopt.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $diagnosis = $setup->diagnose(null, $this->overrides());
        $envWrite = null;

        if (! (bool) $this->option('no-write-env')) {
            $envWrite = $dryRun
                ? $this->plannedEnvWrite($diagnosis)
                : $setup->writeEnv(
                    $diagnosis,
                    is_string($this->option('env-path')) ? $this->option('env-path') : null,
                    $this->operatorMode(),
                    $this->operatorRoot(),
                );
        }
        $this->applyProviderConfig($diagnosis);

        $install = $installer->install(
            target: is_string($this->option('target')) ? $this->option('target') : null,
            force: (bool) $this->option('force'),
            dryRun: $dryRun,
        );

        $shellProfile = null;
        if ((bool) $this->option('write-shell-profile')) {
            $shellProfile = $installer->writeShellProfile(
                targetDir: (string) $install['target_dir'],
                profilePath: is_string($this->option('shell-profile')) ? $this->option('shell-profile') : null,
                dryRun: $dryRun,
            );
        }

        $schedulerCron = $this->schedulerCron($schedulerInstall, $dryRun);
        $projection = $this->providerProjection($providerProjection, $projectionAction, $dryRun);

        $providerRefresh = null;
        if ((bool) $this->option('refresh-providers') && ! $dryRun) {
            $providerRefresh = $health->checkAll()
                ->map(fn ($snapshot): array => [
                    'provider' => $snapshot->provider,
                    'status' => $snapshot->status,
                    'pain' => $snapshot->operational_pain_score,
                    'message' => $snapshot->message,
                ])
                ->values()
                ->all();
        }

        $finalDoctor = $this->finalDoctor($doctor, $dryRun);
        $payload = $this->payload($diagnosis, $envWrite, $install, $shellProfile, $schedulerCron, $projection, $providerRefresh, $finalDoctor, $dryRun);

        if ((bool) $this->option('json')) {
            $this->getOutput()->writeln(
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                OutputInterface::OUTPUT_RAW,
            );

            return $payload['ok'] || ! (bool) $this->option('strict')
                ? self::SUCCESS
                : self::FAILURE;
        }

        $this->render($payload);

        return $payload['ok'] || ! (bool) $this->option('strict')
            ? self::SUCCESS
            : self::FAILURE;
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
     * @return array<string, mixed>
     */
    private function plannedEnvWrite(array $diagnosis): array
    {
        return [
            'env_path' => is_string($this->option('env-path')) && $this->option('env-path') !== ''
                ? $this->option('env-path')
                : base_path('.env'),
            'written' => false,
            'dry_run' => true,
            'updates' => collect((array) ($diagnosis['providers'] ?? []))
                ->filter(fn (array $provider): bool => is_string($provider['resolved_binary'] ?? null) && $provider['resolved_binary'] !== '')
                ->map(fn (array $provider): array => [
                    'key' => $provider['env_key'],
                    'value' => $provider['resolved_binary'],
                ])
                ->merge($this->plannedWorkspaceUpdates())
                ->values()
                ->all(),
            'skipped' => collect((array) ($diagnosis['providers'] ?? []))
                ->filter(fn (array $provider): bool => ! is_string($provider['resolved_binary'] ?? null) || $provider['resolved_binary'] === '')
                ->map(fn (array $provider): array => [
                    'provider' => $provider['provider'],
                    'reason' => 'binary_not_resolved',
                ])
                ->values()
                ->all(),
            'backup_path' => null,
            'message' => $this->operatorMode()
                ? 'Dry-run: .env seria atualizado com binarios resolvidos, workspace roots e modo operador.'
                : 'Dry-run: .env seria atualizado com binarios resolvidos e workspace roots.',
        ];
    }

    /**
     * @return array<int,array{key:string,value:string}>
     */
    private function plannedWorkspaceUpdates(): array
    {
        $root = $this->operatorRoot() ?: dirname(dirname(dirname(base_path())));
        $updates = [
            [
                'key' => 'ATLAS_AI_TOOL_ALLOWED_ROOTS',
                'value' => implode(',', array_values(array_unique(array_filter([
                    realpath($root) ?: $root,
                    dirname(base_path()),
                    base_path(),
                ])))),
            ],
        ];

        if ($this->operatorMode()) {
            $updates[] = ['key' => 'ATLAS_AI_TOOL_ALLOW_DANGER', 'value' => 'true'];
            $updates[] = ['key' => 'ATLAS_AI_ALLOW_UNSANDBOXED_WRITE', 'value' => 'true'];
        }

        return $updates;
    }

    private function operatorMode(): bool
    {
        return (bool) $this->option('operator-mode') || (bool) $this->option('enable-operator-mode');
    }

    private function operatorRoot(): ?string
    {
        $root = $this->option('operator-root');

        return is_string($root) && trim($root) !== '' ? trim($root) : null;
    }

    /**
     * @param  array<string, mixed>  $diagnosis
     */
    private function applyProviderConfig(array $diagnosis): void
    {
        foreach ((array) ($diagnosis['providers'] ?? []) as $provider) {
            $key = $provider['provider'] ?? null;
            $resolved = $provider['resolved_binary'] ?? null;
            if (! is_string($key) || ! is_string($resolved) || $resolved === '') {
                continue;
            }

            config(["atlas.ai.providers.{$key}.binary" => $resolved]);
        }
    }

    /**
     * @param  array<string, mixed>  $diagnosis
     * @param  array<string, mixed>|null  $envWrite
     * @param  array<string, mixed>  $install
     * @param  array<string, mixed>|null  $shellProfile
     * @param  array<string, mixed>|null  $schedulerCron
     * @param  array<string, mixed>|null  $providerProjection
     * @param  array<int, array<string, mixed>>|null  $providerRefresh
     * @param  array<string, mixed>|null  $finalDoctor
     * @return array<string, mixed>
     */
    private function payload(array $diagnosis, ?array $envWrite, array $install, ?array $shellProfile, ?array $schedulerCron, ?array $providerProjection, ?array $providerRefresh, ?array $finalDoctor, bool $dryRun): array
    {
        $pathReady = (bool) ($install['path_ready'] ?? false) || (bool) ($shellProfile['written'] ?? false) || (bool) ($shellProfile['already_configured'] ?? false);
        $gates = [
            [
                'name' => 'provider_resolution',
                'status' => data_get($diagnosis, 'summary.has_any_ready_provider') ? 'passed' : 'failed',
                'detail' => data_get($diagnosis, 'summary.ready_count', 0).' provider(s) resolvido(s).',
            ],
            [
                'name' => 'env_configuration',
                'status' => $envWrite === null ? 'skipped' : ((array) ($envWrite['updates'] ?? []) !== [] ? 'passed' : 'needs_review'),
                'detail' => $envWrite['message'] ?? 'Escrita de .env desativada.',
            ],
            [
                'name' => 'launcher_install',
                'status' => (bool) ($install['ok'] ?? false) ? 'passed' : 'failed',
                'detail' => (bool) ($install['can_install'] ?? false) ? 'Launcher pronto ou instalavel.' : 'Target bloqueado; use --force.',
            ],
            [
                'name' => 'shell_path',
                'status' => $pathReady ? 'passed' : 'needs_review',
                'detail' => $pathReady ? 'Diretorio do launcher disponivel no PATH.' : 'PATH precisa incluir '.$install['target_dir'].'.',
            ],
            [
                'name' => 'scheduler_cron',
                'status' => $this->schedulerCronGateStatus($schedulerCron),
                'detail' => $this->schedulerCronGateDetail($schedulerCron),
            ],
            [
                'name' => 'provider_projection',
                'status' => $this->providerProjectionGateStatus($providerProjection),
                'detail' => $this->providerProjectionGateDetail($providerProjection),
            ],
            [
                'name' => 'final_doctor',
                'status' => $this->finalDoctorGateStatus($finalDoctor),
                'detail' => $this->finalDoctorGateDetail($finalDoctor),
            ],
        ];

        $statuses = collect($gates)->pluck('status');
        $status = match (true) {
            $statuses->contains('failed') => 'failed',
            $statuses->contains('needs_review') => 'needs_review',
            default => 'passed',
        };

        return [
            'ok' => $status !== 'failed' && (! (bool) $this->option('strict') || $status === 'passed'),
            'status' => $status,
            'dry_run' => $dryRun,
            'generated_at' => now()->toJSON(),
            'setup' => $diagnosis,
            'env_write' => $envWrite,
            'install' => $install,
            'shell_profile' => $shellProfile,
            'scheduler_cron' => $schedulerCron,
            'provider_projection' => $providerProjection,
            'provider_refresh' => $providerRefresh,
            'final_doctor' => $finalDoctor,
            'gates' => $gates,
            'next_commands' => $this->nextCommands($status, $dryRun, $pathReady, $schedulerCron, $providerProjection),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function schedulerCron(AtlasSchedulerInstallService $schedulerInstall, bool $dryRun): ?array
    {
        if ((bool) $this->option('no-scheduler-cron-check')) {
            return [
                'skipped' => true,
                'message' => 'Checagem de crontab do scheduler pulada por --no-scheduler-cron-check.',
            ];
        }

        if ((bool) $this->option('install-scheduler-cron')) {
            return $schedulerInstall->install($dryRun);
        }

        return $schedulerInstall->inspect();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function providerProjection(AtlasProviderProjectionService $projection, string $action, bool $dryRun): ?array
    {
        if ($action === 'skip') {
            return [
                'skipped' => true,
                'action' => 'skip',
                'target' => $this->providerProjectionTarget(),
                'status' => 'skipped',
                'message' => 'Provider projection pulada. Use --provider-projection=status|review|apply|write|adopt para inspecionar ou aplicar.',
            ];
        }

        $target = $this->providerProjectionTarget();
        $context = ['workspace' => $this->workspace()];
        $options = $this->providerProjectionOptions();
        $command = $this->providerProjectionCommand($action);

        if ($action === 'review') {
            return $projection->review($target, $context, $options) + [
                'action' => 'review',
                'command' => $command,
            ];
        }

        if ($action === 'apply') {
            if ($dryRun) {
                return $projection->review($target, $context, $options) + [
                    'planned' => true,
                    'action' => 'apply',
                    'command' => $command,
                    'message' => 'Dry-run: provider projection apply revisaria diff e exigiria confirmacao antes de escrever.',
                ];
            }

            if (! (bool) $this->option('provider-projection-yes')) {
                return array_merge($projection->review($target, $context, $options), [
                    'action' => 'apply',
                    'command' => $command,
                    'status' => 'confirmation_required',
                    'error' => 'confirmation_required',
                    'detail' => 'Use --provider-projection-yes para confirmar apply via bootstrap.',
                    'next_actions' => [$command.' --provider-projection-yes'],
                ]);
            }

            return $projection->applyReviewed($target, $context, $options) + [
                'action' => 'apply',
                'command' => $command,
                'confirmed' => true,
                'confirmation_mode' => 'flag',
            ];
        }

        if ($dryRun) {
            return [
                'planned' => true,
                'action' => $action,
                'target' => $target,
                'workspace' => $context['workspace'],
                'status' => 'planned',
                'command' => $command,
                'inspection' => $projection->status($target, $context, $options),
                'message' => 'Dry-run: provider projection '.$action.' seria executado explicitamente.',
            ];
        }

        if ($action === 'status') {
            return $projection->status($target, $context, $options) + [
                'action' => 'status',
                'command' => $command,
            ];
        }

        $results = collect($projection->targets($target))
            ->map(fn (string $projectionTarget): array => $action === 'adopt'
                ? $projection->adopt($projectionTarget, $context, $options)
                : $projection->write($projectionTarget, $context, $options))
            ->values()
            ->all();
        $ok = collect($results)->every(fn (array $result): bool => (bool) ($result['written'] ?? false));
        $postStatus = $projection->status($target, $context, $options);

        return [
            'action' => $action,
            'target' => $target,
            'workspace' => $context['workspace'],
            'status' => $ok ? (string) ($postStatus['status'] ?? 'passed') : 'needs_review',
            'command' => $command,
            'detail' => $ok
                ? 'Provider projection '.$action.' executado para '.$target.'.'
                : 'Provider projection '.$action.' precisa de revisao antes de concluir.',
            'projections' => $results,
            'post_status' => $postStatus,
            'next_actions' => $ok ? [] : (array) ($postStatus['next_actions'] ?? []),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function finalDoctor(AtlasCliDoctorService $doctor, bool $dryRun): ?array
    {
        if ((bool) $this->option('no-doctor')) {
            return [
                'skipped' => true,
                'command' => 'atlas doctor --strict',
                'message' => 'Doctor final pulado por --no-doctor.',
            ];
        }

        $command = 'atlas doctor --strict';
        if ((bool) $this->option('refresh-providers')) {
            $command .= ' --refresh-providers';
        }
        if ((bool) $this->option('doctor-run-tests')) {
            $command .= ' --run-tests';
        }

        if ($dryRun) {
            return [
                'planned' => true,
                'command' => $command,
                'message' => 'Dry-run: doctor final seria executado no fim do bootstrap.',
            ];
        }

        $payload = $doctor->diagnose(
            workspace: $this->workspace(),
            refreshProviders: false,
            runTests: (bool) $this->option('doctor-run-tests'),
        );

        return [
            'command' => $command,
            'strict' => true,
            'exit_code' => data_get($payload, 'readiness.status') === 'passed' ? self::SUCCESS : self::FAILURE,
            'payload' => $payload,
        ];
    }

    private function finalDoctorGateStatus(?array $finalDoctor): string
    {
        if ($finalDoctor === null || (bool) ($finalDoctor['skipped'] ?? false)) {
            return 'skipped';
        }
        if ((bool) ($finalDoctor['planned'] ?? false)) {
            return 'planned';
        }

        return data_get($finalDoctor, 'payload.readiness.status', 'failed');
    }

    private function finalDoctorGateDetail(?array $finalDoctor): string
    {
        if ($finalDoctor === null) {
            return 'Doctor final indisponivel.';
        }
        if ((bool) ($finalDoctor['skipped'] ?? false) || (bool) ($finalDoctor['planned'] ?? false)) {
            return (string) ($finalDoctor['message'] ?? 'atlas doctor --strict');
        }

        $status = (string) data_get($finalDoctor, 'payload.readiness.status', 'unknown');
        if ($status === 'passed') {
            return 'Doctor final executado: passed.';
        }

        $failed = collect((array) data_get($finalDoctor, 'payload.readiness.gates', []))
            ->reject(fn (array $gate): bool => ($gate['status'] ?? null) === 'passed')
            ->map(fn (array $gate): string => ($gate['name'] ?? 'gate').': '.($gate['detail'] ?? ($gate['status'] ?? 'unknown')))
            ->values()
            ->all();

        if ($failed === []) {
            return 'Doctor final executado: '.$status.'. Rode atlas doctor --refresh-providers --run-tests --strict para detalhes.';
        }

        return 'Doctor final executado: '.$status.'. '.implode(' | ', array_slice($failed, 0, 2));
    }

    private function providerProjectionGateStatus(?array $providerProjection): string
    {
        if ($providerProjection === null || (bool) ($providerProjection['skipped'] ?? false)) {
            return 'skipped';
        }
        if ((bool) ($providerProjection['planned'] ?? false)) {
            return 'planned';
        }

        return ($providerProjection['status'] ?? null) === 'passed' ? 'passed' : 'needs_review';
    }

    private function providerProjectionGateDetail(?array $providerProjection): string
    {
        if ($providerProjection === null) {
            return 'Provider projection nao executada.';
        }
        if ((bool) ($providerProjection['skipped'] ?? false) || (bool) ($providerProjection['planned'] ?? false)) {
            return (string) ($providerProjection['message'] ?? 'Provider projection sem alteracoes.');
        }

        return (string) ($providerProjection['detail'] ?? data_get($providerProjection, 'post_status.detail', 'Provider projection precisa de revisao.'));
    }

    private function schedulerCronGateStatus(?array $schedulerCron): string
    {
        if ($schedulerCron === null || (bool) ($schedulerCron['skipped'] ?? false)) {
            return 'skipped';
        }

        if (! (bool) ($schedulerCron['ok'] ?? false)) {
            return 'needs_review';
        }

        return (bool) ($schedulerCron['installed'] ?? false) || (bool) ($schedulerCron['written'] ?? false) || (bool) ($schedulerCron['already_installed'] ?? false)
            ? 'passed'
            : 'needs_review';
    }

    private function schedulerCronGateDetail(?array $schedulerCron): string
    {
        if ($schedulerCron === null) {
            return 'Scheduler cron nao inspecionado.';
        }

        if ((bool) ($schedulerCron['skipped'] ?? false)) {
            return (string) ($schedulerCron['message'] ?? 'Checagem pulada.');
        }

        if ((bool) ($schedulerCron['installed'] ?? false) || (bool) ($schedulerCron['written'] ?? false) || (bool) ($schedulerCron['already_installed'] ?? false)) {
            return 'Laravel scheduler instalado para atlas schedule.';
        }

        return 'Instale com atlas bootstrap --install-scheduler-cron --strict.';
    }

    /**
     * @return array<int, string>
     */
    private function nextCommands(string $status, bool $dryRun, bool $pathReady, ?array $schedulerCron, ?array $providerProjection): array
    {
        if ($dryRun) {
            return [$pathReady ? 'atlas bootstrap --refresh-providers' : 'atlas bootstrap --write-shell-profile --refresh-providers'];
        }

        if ($this->providerProjectionGateStatus($providerProjection) === 'needs_review') {
            $actions = (array) ($providerProjection['next_actions'] ?? data_get($providerProjection, 'post_status.next_actions', []));
            if ($actions !== []) {
                return array_values($actions);
            }

            return ['atlas memory projection status --target=all --workspace="'.$this->workspace().'"'];
        }

        $schedulerReady = (bool) ($schedulerCron['installed'] ?? false)
            || (bool) ($schedulerCron['written'] ?? false)
            || (bool) ($schedulerCron['already_installed'] ?? false)
            || (bool) ($schedulerCron['skipped'] ?? false);

        if (! $schedulerReady) {
            return ['atlas bootstrap --install-scheduler-cron --strict'];
        }

        if (! $pathReady) {
            return [
                $status === 'passed'
                    ? 'atlas bootstrap --write-shell-profile --strict'
                    : 'atlas bootstrap --write-shell-profile --refresh-providers --strict',
            ];
        }

        if ($status !== 'passed') {
            return ['atlas bootstrap --refresh-providers --doctor-run-tests --strict'];
        }

        return ['atlas ask "teste rapido: confirme que o Atlas CLI esta pronto"'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function render(array $payload): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas CLI Bootstrap</>', (string) $payload['status']);
        $this->components->twoColumnDetail('Dry-run', $payload['dry_run'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Launcher', (string) data_get($payload, 'install.target'));
        $this->components->twoColumnDetail('Env', (string) data_get($payload, 'env_write.env_path', 'skipped'));
        $this->components->twoColumnDetail('Scheduler cron', (string) data_get($payload, 'scheduler_cron.command', 'skipped'));
        $this->components->twoColumnDetail('Provider projection', (string) data_get($payload, 'provider_projection.command', data_get($payload, 'provider_projection.status', 'skipped')));
        $this->components->twoColumnDetail('Final doctor', (string) data_get($payload, 'final_doctor.command', 'atlas doctor --strict'));

        $this->newLine();
        $this->table(
            ['gate', 'status', 'detail'],
            collect($payload['gates'])->map(fn (array $gate): array => [
                $gate['name'],
                $gate['status'],
                $gate['detail'],
            ])->all(),
        );

        $commands = (array) ($payload['next_commands'] ?? []);
        if ($commands !== []) {
            $this->newLine();
            $this->line('Proximos comandos:');
            foreach ($commands as $command) {
                $this->line('  '.$command);
            }
        }
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

    private function providerProjectionAction(): ?string
    {
        $action = strtolower(trim((string) ($this->option('provider-projection') ?: 'skip')));
        $action = match ($action) {
            'inspect' => 'status',
            'diff' => 'review',
            'none', 'off', 'false', '0' => 'skip',
            default => $action,
        };

        return in_array($action, ['skip', 'status', 'review', 'apply', 'write', 'adopt'], true) ? $action : null;
    }

    private function providerProjectionTarget(): string
    {
        $target = strtolower(trim((string) ($this->option('provider-projection-target') ?: 'all')));

        return in_array($target, ['claude', 'agents', 'all'], true) ? $target : 'all';
    }

    /**
     * @return array<string,mixed>
     */
    private function providerProjectionOptions(): array
    {
        return array_filter([
            'workspace' => $this->workspace(),
            'max_lines' => $this->stringOption('provider-projection-max-lines'),
            'memory_limit' => $this->stringOption('provider-projection-memory-limit'),
            'force' => (bool) $this->option('provider-projection-force'),
        ], fn (mixed $value): bool => $value !== null);
    }

    private function providerProjectionCommand(string $action): string
    {
        $parts = [
            'atlas bootstrap',
            '--provider-projection='.$action,
            '--provider-projection-target='.$this->providerProjectionTarget(),
            '--workspace="'.$this->workspace().'"',
        ];

        if (($maxLines = $this->stringOption('provider-projection-max-lines')) !== null) {
            $parts[] = '--provider-projection-max-lines='.$maxLines;
        }
        if (($memoryLimit = $this->stringOption('provider-projection-memory-limit')) !== null) {
            $parts[] = '--provider-projection-memory-limit='.$memoryLimit;
        }
        if ((bool) $this->option('provider-projection-force')) {
            $parts[] = '--provider-projection-force';
        }
        if ((bool) $this->option('provider-projection-yes')) {
            $parts[] = '--provider-projection-yes';
        }

        return implode(' ', $parts);
    }

}
