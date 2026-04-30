<?php

namespace App\Console\Commands;

use App\Models\AiThread;
use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AiSessionStateService;
use App\Services\Ai\AiWorker;
use App\Services\Ai\Cli\AtlasCliQualityService;
use App\Services\Ai\Cli\AtlasCliSessionService;
use App\Services\Ai\Skills\SkillBundleStore;
use App\Services\Ai\Skills\SkillDiscoveryService;
use App\Support\AtlasSecurity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class AiChatCommand extends Command
{
    protected $signature = 'atlas:ai:chat
        {input? : One-shot input. Omit it to open the interactive Atlas CLI loop}
        {--provider= : claude, codex, conselho, claude_cli, codex_cli or claude_codex}
        {--agent= : Force a specific Atlas agent/skill slug}
        {--thread= : Continue a specific Atlas AI thread}
        {--new-thread : Start a fresh Atlas AI thread}
        {--workspace= : Workspace path. Defaults to the current directory}
        {--mode=direct : direct, plan, review, dev, debug or research}
        {--dev : Shortcut for --mode=dev --provider=codex}
        {--conselho : Shortcut for --provider=conselho}
        {--stream : Stream provider output while the inline worker runs}
        {--permission=auto : auto, read, write or danger}
        {--allow-write : Confirm scoped workspace writes for this run}
        {--dangerously-allow-all : Confirm danger-full-access for this run}
        {--allow-unsandboxed : Allow write/danger mode with providers that Atlas cannot sandbox directly}
        {--auto-test : Run detected tests after dev responses}
        {--no-quality-gate : Skip automatic Atlas quality gate in dev mode}
        {--dev-plan= : JSON encoded Atlas dev execution plan}
        {--skill=* : Activate one or more agentskills bundle names}
        {--list-threads : List recent Atlas CLI threads and exit}
        {--no-run : Enqueue only; do not run the local worker inline}
        {--timeout=900 : Seconds to wait when running inline}
        {--json : Print machine-readable JSON}';

    protected $description = 'Use Atlas AI directly from the Mac while preserving Atlas threads, sessions, memory and provider handoffs.';

    private string $streamedAssistantContent = '';

    public function handle(
        AiGatewayService $gateway,
        AiWorker $worker,
        AtlasCliSessionService $cliSessions,
        AtlasCliQualityService $quality,
        AiSessionStateService $states,
        SkillDiscoveryService $skillDiscovery,
        SkillBundleStore $skillBundles,
    ): int
    {
        $workspace = $this->workspace();
        $provider = $this->providerKey($this->option('conselho') ? 'conselho' : ($this->option('provider') ?: null));
        $mode = $this->workflowMode($this->option('dev') ? 'dev' : (string) $this->option('mode'));
        if ($this->option('dev') && ! $provider) {
            $provider = 'codex_cli';
        }
        $permissionMode = $this->permissionMode((string) $this->option('permission'), $mode);
        $stream = (bool) $this->option('stream') && ! (bool) $this->option('json');
        $threadId = $this->option('thread') ?: ($this->option('new-thread') ? null : $this->latestThreadId($workspace));
        $busyMode = $this->busyInputMode();
        $queuedMessages = [];
        $input = $this->argument('input');
        $activatedSkills = $this->skillOptions();

        if ((bool) $this->option('list-threads')) {
            $this->printThreads($workspace);

            return self::SUCCESS;
        }

        $this->prepareSkillBundles($skillDiscovery, $skillBundles, $workspace);

        if (is_string($input) && trim($input) !== '') {
            $trace = $this->send($gateway, $worker, trim($input), $workspace, $provider, $mode, $permissionMode, $stream, $threadId, (bool) $this->option('new-thread'), $activatedSkills);
            $this->maybeRunDevQualityGate($quality, $workspace, $mode);

            return $trace->status === 'succeeded' || (bool) $this->option('no-run')
                ? self::SUCCESS
                : self::FAILURE;
        }

        if (! $this->option('json')) {
            $this->line('Atlas CLI iniciado. Use /help para comandos, /new para nova thread, /exit para sair.');
            $this->printStatus($workspace, $provider, $mode, $permissionMode, $stream, $busyMode, $threadId, $activatedSkills);
            if ($threadId) {
                $this->line("Thread ativa: {$threadId}");
            }
        }

        while (true) {
            $drainedTrace = $this->drainQueuedMessages($queuedMessages, $gateway, $worker, $quality, $workspace, $provider, $mode, $permissionMode, $stream, $threadId, $activatedSkills);
            if ($drainedTrace) {
                $threadId = $drainedTrace->thread_id ?: $threadId;
            }

            $line = $this->ask($threadId ? "atlas {$this->shortId($threadId)}" : 'atlas');
            if (! is_string($line)) {
                continue;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (in_array($line, ['/exit', '/quit', '/sair'], true)) {
                return self::SUCCESS;
            }

            if ($line === '/help') {
                $this->printHelp();

                continue;
            }

            if ($line === '/status') {
                $this->printStatus($workspace, $provider, $mode, $permissionMode, $stream, $busyMode, $threadId, $activatedSkills);

                continue;
            }

            if ($line === '/state') {
                $this->printCliState($cliSessions->snapshot($workspace, $threadId));

                continue;
            }

            if ($line === '/quality') {
                $this->printQuality($quality->compact($quality->evaluate($workspace)));

                continue;
            }

            if ($line === '/doctor') {
                $this->runLocalAtlasCommand(['atlas:cli:doctor', '--workspace='.$workspace]);

                continue;
            }

            if ($line === '/providers') {
                $this->runLocalAtlasCommand(['atlas:cli:providers', '--mode='.$mode]);

                continue;
            }

            if ($line === '/skills' || str_starts_with($line, '/skills ')) {
                $this->runLocalAtlasCommand(array_merge(
                    ['atlas:cli:skills', '--workspace='.$workspace],
                    $this->simpleArguments(trim(Str::after($line, '/skills'))),
                ));

                continue;
            }

            if (in_array($line, ['/checkpoint', '/checkpoints'], true)) {
                $this->runLocalAtlasCommand(['atlas:cli:checkpoint', '--workspace='.$workspace]);

                continue;
            }

            if ($line === '/threads') {
                $this->printThreads($workspace);

                continue;
            }

            if ($line === '/new') {
                $threadId = null;
                $this->line('Nova thread será criada na próxima mensagem.');

                continue;
            }

            if (str_starts_with($line, '/thread ')) {
                $threadId = trim(Str::after($line, '/thread '));
                $this->line("Thread ativa: {$threadId}");

                continue;
            }

            if (str_starts_with($line, '/workspace ')) {
                $workspace = $this->resolveWorkspace(trim(Str::after($line, '/workspace ')));
                $threadId = null;
                $queuedMessages = [];
                $this->prepareSkillBundles($skillDiscovery, $skillBundles, $workspace);
                $this->line("Workspace ativo: {$workspace}");

                continue;
            }

            if (str_starts_with($line, '/provider ')) {
                $provider = $this->providerKey(trim(Str::after($line, '/provider ')));
                $this->line('Provider ativo: '.($provider ?: 'padrao'));

                continue;
            }

            if (str_starts_with($line, '/mode ')) {
                $mode = $this->workflowMode(trim(Str::after($line, '/mode ')));
                if ($mode === 'dev' && ! $provider) {
                    $provider = 'codex_cli';
                }
                $permissionMode = $this->permissionMode($permissionMode, $mode);
                $this->line("Modo ativo: {$mode}");

                continue;
            }

            if (str_starts_with($line, '/permission ')) {
                $permissionMode = $this->permissionMode(trim(Str::after($line, '/permission ')), $mode);
                $this->line("Permissao ativa: {$permissionMode}");

                continue;
            }

            if (str_starts_with($line, '/objective ')) {
                $this->printCliStateResult(fn (): array => $cliSessions->update($workspace, $threadId, [
                    'objective' => trim(Str::after($line, '/objective ')),
                ]));

                continue;
            }

            if (str_starts_with($line, '/phase ')) {
                $this->printCliStateResult(fn (): array => $cliSessions->update($workspace, $threadId, [
                    'phase' => trim(Str::after($line, '/phase ')),
                ]));

                continue;
            }

            if (str_starts_with($line, '/topic ')) {
                $this->printCliStateResult(fn (): array => $cliSessions->update($workspace, $threadId, [
                    'topic' => trim(Str::after($line, '/topic ')),
                ]));

                continue;
            }

            if (str_starts_with($line, '/note ')) {
                $this->printCliStateResult(fn (): array => $cliSessions->update($workspace, $threadId, [
                    'notes' => [trim(Str::after($line, '/note '))],
                ]));

                continue;
            }

            if (str_starts_with($line, '/next ')) {
                $this->printCliStateResult(fn (): array => $cliSessions->update($workspace, $threadId, [
                    'next_steps' => [trim(Str::after($line, '/next '))],
                ]));

                continue;
            }

            if ($line === '/compact') {
                $this->printCliStateResult(fn (): array => $cliSessions->compact($workspace, $threadId));

                continue;
            }

            if (str_starts_with($line, '/handoff ')) {
                $this->printCliStateResult(fn (): array => $cliSessions->handoff(
                    $workspace,
                    $threadId,
                    $this->providerKey(trim(Str::after($line, '/handoff '))) ?: trim(Str::after($line, '/handoff ')),
                ));

                continue;
            }

            if (str_starts_with($line, '/stream ')) {
                $value = Str::of(Str::after($line, '/stream '))->lower()->trim()->value();
                $stream = in_array($value, ['on', 'sim', 'true', '1'], true);
                $this->line('Streaming: '.($stream ? 'on' : 'off'));

                continue;
            }

            if (str_starts_with($line, '/busy')) {
                $busyMode = $this->handleBusyCommand($line, $busyMode);

                continue;
            }

            if (str_starts_with($line, '/steer')) {
                $this->handleSteerCommand($states, $workspace, $threadId, trim(Str::after($line, '/steer')));

                continue;
            }

            $messageSkills = $activatedSkills;
            $skillSlash = $this->skillSlash($line, $skillBundles);
            if ($skillSlash) {
                $messageSkills[] = $skillSlash['skill'];
                $messageSkills = array_values(array_unique($messageSkills));
                $line = $skillSlash['input'];
            }

            $activeTrace = $this->activeTrace($threadId, $workspace);
            if ($activeTrace) {
                $threadId = $activeTrace->thread_id ?: $threadId;
                $this->showBusyHintOnce();

                if ($busyMode === 'queue') {
                    $queuedMessages[] = [
                        'input' => $line,
                        'skills' => $messageSkills,
                    ];
                    $this->line('(queued - will send next turn)');

                    continue;
                }

                if ($busyMode === 'steer') {
                    $this->setPendingSteer($states, $activeTrace, $line);
                    $this->line('(steering - will reach agent before the next provider call)');

                    continue;
                }

                $this->cancelTrace($activeTrace);
                $this->warn('(interrupted - previous trace cancelled; sending new message)');
            }

            $trace = $this->send($gateway, $worker, $line, $workspace, $provider, $mode, $permissionMode, $stream, $threadId, $threadId === null, $messageSkills);
            $threadId = $trace->thread_id ?: $threadId;
            $this->maybeRunDevQualityGate($quality, $workspace, $mode);
        }
    }

    private function send(
        AiGatewayService $gateway,
        AiWorker $worker,
        string $input,
        string $workspace,
        ?string $provider,
        string $mode,
        string $permissionMode,
        bool $stream,
        ?string $threadId,
        bool $newThread,
        array $activatedSkills = [],
    ): AiTrace {
        $this->streamedAssistantContent = '';

        $activatedSkills = $this->normalizeSkillNames($activatedSkills);
        $payload = [
            'app_surface' => 'atlas_cli',
            'atlas_workflow_mode' => $mode,
            'workspace' => $workspace,
            'requested_provider' => $provider,
            'requested_agent' => $this->agentSlug($mode),
            'workspace_context' => $this->workspaceContext($workspace),
            'tool_permissions' => $this->toolPermissions($workspace, $mode, $provider, $permissionMode),
        ];
        if ($activatedSkills !== []) {
            $payload['activated_skills'] = $activatedSkills;
        }

        $devPlan = $this->devExecutionPlanOption();
        if ($devPlan !== null) {
            $payload['dev_execution_plan'] = $devPlan;
        }

        if ($provider === 'claude_codex') {
            $payload['execution_policy'] = 'dual_review';
            $payload['council_providers'] = ['claude_cli', 'codex_cli'];
        }

        $trace = $gateway->enqueueInteraction($input, [
            'source_type' => 'manual',
            'agent_slug' => $this->agentSlug($mode),
            'provider' => $provider,
            'thread_id' => $threadId,
            'new_thread' => $newThread,
            'priority' => 10,
            'include_semantic_context' => true,
            'timeout_seconds' => min(max((int) $this->option('timeout'), 15), 1800),
            'payload' => $payload,
        ]);

        if ((bool) $this->option('no-run')) {
            $this->printTrace($trace->load($this->traceRelations()));

            return $trace;
        }

        $trace = $this->runInline($worker, $trace, $stream);
        $this->printTrace($trace);

        return $trace;
    }

    /**
     * @param  array<int,array{input:string,skills:array<int,string>}|string>  $queuedMessages
     */
    private function drainQueuedMessages(
        array &$queuedMessages,
        AiGatewayService $gateway,
        AiWorker $worker,
        AtlasCliQualityService $quality,
        string $workspace,
        ?string $provider,
        string $mode,
        string $permissionMode,
        bool $stream,
        ?string $threadId,
        array $activatedSkills = [],
    ): ?AiTrace {
        if ($queuedMessages === [] || $this->activeTrace($threadId, $workspace)) {
            return null;
        }

        $items = array_splice($queuedMessages, 0);
        $batch = collect($items)
            ->map(fn (mixed $item): string => is_array($item) ? (string) ($item['input'] ?? '') : (string) $item)
            ->filter(fn (string $message): bool => trim($message) !== '')
            ->implode("\n\n");
        $batchSkills = collect($items)
            ->flatMap(fn (mixed $item): array => is_array($item) ? (array) ($item['skills'] ?? []) : [])
            ->merge($activatedSkills)
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (mixed $name): string => Str::of((string) $name)->lower()->trim()->value())
            ->unique()
            ->values()
            ->all();
        $messageCount = count($items);
        $this->line("(queued batch - sending {$messageCount} message(s))");

        $trace = $this->send($gateway, $worker, $batch, $workspace, $provider, $mode, $permissionMode, $stream, $threadId, $threadId === null, $batchSkills);
        $this->maybeRunDevQualityGate($quality, $workspace, $mode);

        return $trace;
    }

    private function handleBusyCommand(string $line, string $currentMode): string
    {
        $value = Str::of(Str::after($line, '/busy'))->lower()->trim()->value();
        if ($value === '' || $value === 'status') {
            $this->line("busy input mode: {$currentMode}");

            return $currentMode;
        }

        if (! in_array($value, ['interrupt', 'queue', 'steer'], true)) {
            $this->warn('Modo invalido. Use /busy interrupt, /busy queue, /busy steer ou /busy status.');

            return $currentMode;
        }

        $this->line("busy input mode: {$value}");

        return $value;
    }

    private function handleSteerCommand(AiSessionStateService $states, string $workspace, ?string $threadId, string $message): void
    {
        $message = trim($message);
        if ($message === '') {
            $this->warn('Uso: /steer <mensagem>');

            return;
        }

        $activeTrace = $this->activeTrace($threadId, $workspace);
        if (! $activeTrace) {
            $this->error('no agent running');

            return;
        }

        $this->setPendingSteer($states, $activeTrace, $message);
        $this->line('(steering - will reach agent before the next provider call)');
    }

    private function setPendingSteer(AiSessionStateService $states, AiTrace $trace, string $message): void
    {
        $states->setPendingSteer((string) $trace->thread_id, $message, $trace->session_id);
    }

    private function activeTrace(?string $threadId, string $workspace): ?AiTrace
    {
        if (! Schema::hasTable('ai_traces') || ! Schema::hasTable('ai_threads')) {
            return null;
        }

        $query = AiTrace::query()
            ->whereIn('status', ['queued', 'processing'])
            ->with('thread')
            ->latest('updated_at');

        if ($threadId) {
            $query->where('thread_id', $threadId);
        } else {
            $query->whereHas('thread', function ($threadQuery) use ($workspace): void {
                $threadQuery
                    ->where('surface', 'atlas_cli')
                    ->where('workspace', $workspace)
                    ->where('status', 'active');
            });
        }

        return $query->first();
    }

    private function cancelTrace(AiTrace $trace): void
    {
        $trace->update([
            'status' => 'cancelled',
            'completed_at' => now(),
            'metadata' => array_merge($trace->metadata ?? [], [
                'cancelled_by' => 'atlas_cli_busy_interrupt',
                'cancelled_at' => now()->toJSON(),
            ]),
        ]);

        if (Schema::hasTable('ai_jobs')) {
            $trace->jobs()->whereIn('status', ['queued', 'processing'])->update([
                'status' => 'cancelled',
                'finished_at' => now(),
                'error_code' => 'cancelled_by_operator',
                'error_message' => 'Interrompido pelo operador via Atlas CLI busy interrupt.',
            ]);
        }
    }

    private function runInline(AiWorker $worker, AiTrace $trace, bool $stream): AiTrace
    {
        $deadline = now()->addSeconds(max(15, (int) $this->option('timeout')));
        $workerId = 'atlas-cli-'.getmypid();

        while (now()->lessThanOrEqualTo($deadline)) {
            $trace = $trace->fresh($this->traceRelations()) ?: $trace;
            if (in_array($trace->status, ['succeeded', 'failed'], true)) {
                $remediationTrace = $this->nextRemediationTrace($trace);
                if ($remediationTrace) {
                    $trace = $remediationTrace;

                    continue;
                }

                return $trace;
            }

            $providerOverride = $trace->provider === 'claude_codex' ? null : $trace->provider;
            $job = $worker->runNextForTrace($trace->id, $providerOverride, $workerId, $stream ? function (array $event): void {
                $this->printStreamEvent($event);
            } : null);
            $trace = $trace->fresh($this->traceRelations()) ?: $trace;

            if (in_array($trace->status, ['succeeded', 'failed'], true)) {
                $remediationTrace = $this->nextRemediationTrace($trace);
                if ($remediationTrace) {
                    $trace = $remediationTrace;

                    continue;
                }

                return $trace;
            }

            if (! $job && $this->hasDelayedRetry($trace)) {
                return $trace;
            }

            if (! $job) {
                sleep(1);
            }
        }

        $this->warn('Tempo limite atingido; a interação continua registrada no Atlas.');

        return $trace->fresh($this->traceRelations()) ?: $trace;
    }

    private function printTrace(AiTrace $trace): void
    {
        $trace = $trace->loadMissing($this->traceRelations());
        $quality = $trace->relationLoaded('qualityEvaluation') ? $trace->qualityEvaluation : null;

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'trace_id' => $trace->id,
                'thread_id' => $trace->thread_id,
                'session_id' => $trace->session_id,
                'status' => $trace->status,
                'provider' => $trace->provider,
                'agent' => $trace->agent_slug,
                'skills_activated' => (array) data_get($trace->metadata, 'skills_activated', []),
                'response_text' => $trace->response_text,
                'quality' => $quality ? [
                    'score' => $quality->score,
                    'status' => $quality->status,
                    'flags' => collect($quality->flags)->pluck('code')->values()->all(),
                ] : null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->line('');
        $this->line("trace: {$trace->id}");
        $this->line("thread: {$trace->thread_id}");
        $this->line("status: {$trace->status} | provider: {$trace->provider}");
        $activatedSkills = collect((array) data_get($trace->metadata, 'skills_activated', []))
            ->pluck('name')
            ->filter()
            ->implode(', ');
        if ($activatedSkills !== '') {
            $this->line("skills: {$activatedSkills}");
        }

        if ($quality) {
            $flags = collect($quality->flags)->pluck('code')->implode(', ') ?: 'none';
            $this->line("quality: {$quality->score}/100 {$quality->status} | flags: {$flags}");
        }

        if ($trace->response_text && $this->streamedAssistantContent === '') {
            $this->line('');
            $this->line($trace->response_text);
        } elseif ($trace->job?->error_message) {
            $this->line('');
            $this->error($trace->job->error_message);
        }
    }

    private function printStreamEvent(array $event): void
    {
        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        $content = is_string($event['content'] ?? null) ? $event['content'] : '';

        if ($type === 'token' && $content !== '') {
            $this->output->write($content);
            $this->streamedAssistantContent .= $content;

            return;
        }

        if ($type === 'error' && $content !== '') {
            if ($this->streamedAssistantContent !== '') {
                $this->newLine();
            }
            $this->error($content);

            return;
        }

        if ($type === 'permission' && $this->output->isVerbose()) {
            $allowed = (bool) data_get($event, 'metadata.permission.allowed', false);
            $mode = data_get($event, 'metadata.permission.mode', 'read');
            $workspace = data_get($event, 'metadata.permission.workspace', '');
            $this->line(($allowed ? '<fg=gray>permission allowed</>' : '<fg=red>permission denied</>')." mode={$mode} workspace={$workspace}");
        }

        if ($type === 'stderr' && $content !== '' && $this->output->isVerbose()) {
            fwrite(STDERR, $content);
        }
    }

    private function printHelp(): void
    {
        $this->line('Comandos Atlas CLI:');
        $this->line('/new                         cria uma nova thread');
        $this->line('/thread <id>                 continua uma thread especifica');
        $this->line('/threads                     lista threads recentes deste workspace');
        $this->line('/provider <claude|codex|conselho|padrao>');
        $this->line('/mode <direct|plan|review|dev|debug|research>');
        $this->line('/permission <auto|read|write|danger>');
        $this->line('/stream <on|off>');
        $this->line('/busy <interrupt|queue|steer|status>');
        $this->line('/steer <texto>              envia nudge para trace ativo sem abrir nova conversa');
        $this->line('/workspace <path>            troca workspace e inicia nova thread');
        $this->line('/status                      mostra runtime atual');
        $this->line('/state                       mostra estado longo da sessao');
        $this->line('/quality                     mostra quality gate compacto');
        $this->line('/doctor                      roda diagnostico terminal');
        $this->line('/providers                   mostra estrategia de providers');
        $this->line('/skills [list|show|doctor]   lista, inspeciona e valida bundles de skills');
        $this->line('/checkpoint                  lista checkpoints do workspace');
        $this->line('/objective <texto>           fixa objetivo operacional');
        $this->line('/phase <texto>               fixa fase atual');
        $this->line('/topic <texto>               fixa topico atual');
        $this->line('/note <texto>                adiciona nota operacional');
        $this->line('/next <texto>                adiciona proximo passo');
        $this->line('/compact                     compacta a thread atual');
        $this->line('/handoff <claude|codex>      cria handoff para outro provider');
        $this->line('/exit                        encerra');
    }

    private function prepareSkillBundles(SkillDiscoveryService $discovery, SkillBundleStore $bundles, string $workspace): void
    {
        if (
            ! (bool) $this->option('json')
            && $discovery->workspaceHasLocalSkills($workspace)
            && ! $discovery->isWorkspaceTrusted($workspace)
        ) {
            $this->warn('Este workspace contem skills locais em .atlas/skills ou .agents/skills.');
            $this->warn('Skills locais podem influenciar o comportamento do Atlas. Confie apenas em repositorios que voce controla.');
            if ($this->confirm('Confiar nas skills locais deste workspace?', false)) {
                $discovery->trustWorkspace($workspace);
                $this->line('Workspace marcado como confiavel para skills locais.');
            } else {
                $this->line('Skills locais ignoradas nesta sessao. Skills builtin, user e Vault continuam disponiveis.');
            }
        }

        $bundles->clear();
        $bundles->registerAll($discovery->discoverAll($workspace));
    }

    /**
     * @return array{skill:string,input:string}|null
     */
    private function skillSlash(string $line, SkillBundleStore $bundles): ?array
    {
        if (! str_starts_with($line, '/') || str_starts_with($line, '//')) {
            return null;
        }

        $withoutSlash = trim(substr($line, 1));
        if ($withoutSlash === '') {
            return null;
        }

        [$command, $message] = array_pad(preg_split('/\s+/', $withoutSlash, 2) ?: [], 2, '');
        $manifest = $bundles->find((string) $command);
        if (! $manifest) {
            return null;
        }

        $message = trim((string) $message);

        return [
            'skill' => $manifest->name,
            'input' => $message !== '' ? $message : "Use a skill {$manifest->name} para orientar esta resposta.",
        ];
    }

    /**
     * @return array<int,string>
     */
    private function skillOptions(): array
    {
        return $this->normalizeSkillNames((array) $this->option('skill'));
    }

    /**
     * @param  array<int,mixed>  $skills
     * @return array<int,string>
     */
    private function normalizeSkillNames(array $skills): array
    {
        return collect($skills)
            ->flatMap(fn (mixed $value): array => is_string($value) ? preg_split('/\s*,\s*/', $value) ?: [] : [])
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (mixed $name): string => Str::of((string) $name)->lower()->trim()->value())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function simpleArguments(string $input): array
    {
        if ($input === '') {
            return [];
        }

        return collect(preg_split('/\s+/', $input) ?: [])
            ->filter(fn (string $part): bool => $part !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $quality
     */
    private function printQuality(array $quality): void
    {
        $this->line('');
        $this->line('Atlas quality: '.($quality['status'] ?? 'unknown'));
        $this->line('summary: '.data_get($quality, 'completion_packet.summary', '-'));

        $gates = (array) ($quality['quality_gates'] ?? []);
        if ($gates !== []) {
            $this->table(
                ['gate', 'status', 'detail'],
                collect($gates)->map(fn (array $gate): array => [
                    $gate['name'] ?? '-',
                    $gate['status'] ?? '-',
                    $gate['detail'] ?? '-',
                ])->all(),
            );
        }
    }

    /**
     * @param  array<int,string>  $arguments
     */
    private function runLocalAtlasCommand(array $arguments): void
    {
        $process = new Process(array_merge([PHP_BINARY, 'artisan'], $arguments), base_path(), AtlasSecurity::processEnv(profile: 'internal'));
        $process->setTimeout(120);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write(AtlasSecurity::redactString($buffer));
        });

        if (! $process->isSuccessful()) {
            $this->warn('Comando local Atlas terminou com exit code '.($process->getExitCode() ?? 'n/a').'.');
        }
    }

    private function printCliState(array $snapshot): void
    {
        $thread = is_array($snapshot['thread'] ?? null) ? $snapshot['thread'] : null;
        if (! $thread) {
            $this->warn('Nenhuma thread Atlas CLI ativa neste workspace.');

            return;
        }

        $session = is_array($snapshot['session'] ?? null) ? $snapshot['session'] : null;
        $state = is_array($snapshot['state'] ?? null) ? $snapshot['state'] : [];

        $this->line('');
        $this->line('Atlas session state:');
        $this->line('thread: '.$this->shortId((string) $thread['id']).' | '.($thread['title'] ?? 'sem titulo'));
        $this->line('session: '.($session ? $this->shortId((string) $session['id']).' '.$session['status'] : '-'));
        $this->line('objective: '.($state['objective'] ?? '-'));
        $this->line('phase: '.($state['current_phase'] ?? '-'));
        $this->line('topic: '.($state['current_topic'] ?? '-'));

        foreach ([
            'decisions' => 'decisions',
            'open_loops' => 'open loops',
            'next_steps' => 'next',
            'operator_notes' => 'notes',
        ] as $key => $label) {
            $items = collect((array) ($state[$key] ?? []))
                ->map(fn (mixed $item): string => is_array($item) ? (string) ($item['text'] ?? '') : '')
                ->filter()
                ->take(5);
            if ($items->isNotEmpty()) {
                $this->line($label.':');
                foreach ($items as $item) {
                    $this->line('  - '.Str::limit($item, 140));
                }
            }
        }

        if (is_array($snapshot['created_compaction'] ?? null)) {
            $this->line('compaction: '.$this->shortId((string) data_get($snapshot, 'created_compaction.id')));
        }

        if (is_array($snapshot['created_provider_handoff'] ?? null)) {
            $this->line('handoff: '.$this->shortId((string) data_get($snapshot, 'created_provider_handoff.id')).' -> '.data_get($snapshot, 'created_provider_handoff.to_provider'));
        }
    }

    private function printCliStateResult(callable $callback): void
    {
        try {
            $this->printCliState($callback());
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
        }
    }

    private function maybeRunDevQualityGate(AtlasCliQualityService $quality, string $workspace, string $mode): void
    {
        if ($mode !== 'dev' || (bool) $this->option('no-quality-gate') || (bool) $this->option('json') || (bool) $this->option('no-run')) {
            return;
        }

        $payload = $quality->evaluate(
            workspace: $workspace,
            runTests: (bool) $this->option('auto-test'),
            approved: (bool) $this->option('allow-write') || $mode === 'dev',
        );

        $this->newLine();
        $this->line('<fg=bright-blue;options=bold>Atlas quality gate</> '.$payload['status']);
        $this->line((string) data_get($payload, 'completion_packet.summary'));

        $risks = (array) data_get($payload, 'completion_packet.risks', []);
        if ($risks !== []) {
            $this->line('Riscos:');
            foreach ($risks as $risk) {
                $this->line('  - '.$risk);
            }
        }

        $gates = collect((array) ($payload['quality_gates'] ?? []))
            ->map(fn (array $gate): string => "{$gate['name']}: {$gate['status']}")
            ->implode(' | ');
        if ($gates !== '') {
            $this->line($gates);
        }
    }

    private function printStatus(string $workspace, ?string $provider, string $mode, string $permissionMode, bool $stream, string $busyMode, ?string $threadId, array $activatedSkills = []): void
    {
        $this->line('Atlas runtime:');
        $this->line('workspace: '.$workspace);
        $this->line('provider: '.($provider ?: 'padrao'));
        $this->line("mode: {$mode}");
        $this->line("permission: {$permissionMode}");
        $this->line('stream: '.($stream ? 'on' : 'off'));
        $this->line("busy input: {$busyMode}");
        $this->line('thread: '.($threadId ?: 'nova/proxima'));
        $this->line('skills: '.($activatedSkills === [] ? 'auto' : implode(', ', $activatedSkills)));
    }

    private function printThreads(string $workspace): void
    {
        if (! Schema::hasTable('ai_threads')) {
            if ((bool) $this->option('json')) {
                $this->line(json_encode([
                    'ok' => false,
                    'error' => 'Tabela ai_threads indisponivel.',
                    'threads' => [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return;
            }

            $this->warn('Tabela ai_threads indisponivel.');

            return;
        }

        $threads = AiThread::query()
            ->where('surface', 'atlas_cli')
            ->where('workspace', $workspace)
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'ok' => true,
                'workspace' => $workspace,
                'threads' => $threads->map(fn (AiThread $thread): array => [
                    'id' => $thread->id,
                    'title' => $thread->title ?: 'sem titulo',
                    'provider' => $thread->last_provider ?: null,
                    'message_count' => $thread->message_count,
                    'last_message_at' => $thread->last_message_at?->toJSON(),
                ])->values()->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        if ($threads->isEmpty()) {
            $this->line('Nenhuma thread Atlas CLI neste workspace.');

            return;
        }

        $this->table(
            ['id', 'title', 'provider', 'messages', 'last_message_at'],
            $threads->map(fn (AiThread $thread): array => [
                $thread->id,
                Str::limit((string) ($thread->title ?: 'sem titulo'), 40),
                $thread->last_provider ?: '-',
                $thread->message_count,
                $thread->last_message_at?->toDateTimeString() ?: '-',
            ])->all(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function toolPermissions(string $workspace, string $workflowMode, ?string $provider, string $permissionMode): array
    {
        $mode = $this->permissionMode($permissionMode, $workflowMode);
        $sandboxes = config('atlas.ai.tool_permissions.codex_sandboxes', []);
        $sandboxes = is_array($sandboxes) ? $sandboxes : [];

        return [
            'schema_version' => 1,
            'source' => 'atlas_cli',
            'mode' => $mode,
            'workspace' => $workspace,
            'confirmed' => $mode === 'danger' || (bool) $this->option('allow-write') || $workflowMode === 'dev',
            'allow_unsandboxed_provider' => (bool) $this->option('allow-unsandboxed'),
            'requested_provider' => $provider,
            'codex_sandbox' => (string) ($sandboxes[$mode] ?? match ($mode) {
                'write' => 'workspace-write',
                'danger' => 'danger-full-access',
                default => 'read-only',
            }),
            'capabilities' => $this->capabilitiesForPermissionMode($mode),
        ];
    }

    private function permissionMode(string $requested, string $workflowMode): string
    {
        $requested = Str::of($requested)->lower()->trim()->value();

        if (in_array($requested, ['read', 'write', 'danger'], true)) {
            return $requested;
        }

        if ((bool) $this->option('dangerously-allow-all')) {
            return 'danger';
        }

        if ((bool) $this->option('allow-write') || $workflowMode === 'dev') {
            return 'write';
        }

        return 'read';
    }

    private function busyInputMode(): string
    {
        $mode = Str::of((string) config('atlas.display.busy_input_mode', 'interrupt'))->lower()->trim()->value();

        return in_array($mode, ['interrupt', 'queue', 'steer'], true) ? $mode : 'interrupt';
    }

    private function showBusyHintOnce(): void
    {
        $data = $this->onboardingData();
        if ((bool) data_get($data, 'seen.busy_input_prompt', false)) {
            return;
        }

        $this->line('(tip) Existe uma execucao ativa. Use /busy queue ou /busy steer para nao interromper.');
        data_set($data, 'seen.busy_input_prompt', true);
        $this->writeOnboardingData($data);
    }

    /**
     * @return array<string,mixed>
     */
    private function onboardingData(): array
    {
        $path = $this->onboardingPath();
        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function writeOnboardingData(array $data): void
    {
        $path = $this->onboardingPath();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    private function onboardingPath(): string
    {
        $home = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: dirname(base_path()));

        return rtrim($home, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.atlas'.DIRECTORY_SEPARATOR.'onboarding.json';
    }

    /**
     * @return array<int,string>
     */
    private function capabilitiesForPermissionMode(string $mode): array
    {
        return match ($mode) {
            'danger' => ['read_files', 'inspect_git', 'write_workspace', 'run_tests', 'run_package_scripts', 'danger_full_access'],
            'write' => ['read_files', 'inspect_git', 'write_workspace', 'run_tests', 'run_package_scripts'],
            default => ['read_files', 'inspect_git', 'read_only_shell'],
        };
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));

        return $this->resolveWorkspace($workspace);
    }

    private function resolveWorkspace(string $workspace): string
    {
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

    private function latestThreadId(string $workspace): ?string
    {
        return AiThread::query()
            ->where('status', 'active')
            ->where('surface', 'atlas_cli')
            ->where('workspace', $workspace)
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->value('id');
    }

    private function workspaceContext(string $workspace): array
    {
        return [
            'workspace' => $workspace,
            'repo_root' => $this->runProcess(['git', 'rev-parse', '--show-toplevel'], $workspace),
            'branch' => $this->runProcess(['git', 'branch', '--show-current'], $workspace),
            'head' => $this->runProcess(['git', 'rev-parse', '--short', 'HEAD'], $workspace),
            'dirty_files' => $this->dirtyFiles($workspace),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function dirtyFiles(string $workspace): array
    {
        $status = $this->runProcess(['git', 'status', '--short'], $workspace);
        if (! $status) {
            return [];
        }

        return collect(explode("\n", $status))
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->take(80)
            ->values()
            ->all();
    }

    private function runProcess(array $command, string $cwd): ?string
    {
        if (! is_dir($cwd)) {
            return null;
        }

        try {
            $process = new Process($command, $cwd, AtlasSecurity::processEnv(profile: 'tool'));
            $process->setTimeout(3);
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = trim(AtlasSecurity::redactString($process->getOutput()));

        return $output !== '' ? $output : null;
    }

    private function providerKey(?string $provider): ?string
    {
        if ($provider === null || trim($provider) === '') {
            return null;
        }

        return match (Str::of($provider)->lower()->trim()->value()) {
            'claude', 'claude_cli' => 'claude_cli',
            'codex', 'codex_cli' => 'codex_cli',
            'conselho', 'council', 'ambos', 'claude_codex' => 'claude_codex',
            default => throw new \InvalidArgumentException("Provider invalido: {$provider}"),
        };
    }

    private function workflowMode(string $mode): string
    {
        $mode = Str::of($mode)->lower()->trim()->value();

        return in_array($mode, ['direct', 'plan', 'review', 'dev', 'debug', 'research'], true) ? $mode : 'direct';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function devExecutionPlanOption(): ?array
    {
        $raw = $this->option('dev-plan');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function agentSlug(string $mode): ?string
    {
        if ($this->option('agent')) {
            return (string) $this->option('agent');
        }

        return match ($mode) {
            'dev', 'debug' => 'desenvolvedor',
            'research' => 'researcher-quick',
            default => null,
        };
    }

    private function shortId(string $id): string
    {
        return substr($id, 0, 8);
    }

    private function hasDelayedRetry(AiTrace $trace): bool
    {
        return $trace->jobs
            ->where('status', 'queued')
            ->contains(fn ($job): bool => $job->available_at?->isFuture() === true);
    }

    /**
     * @return array<int,string>
     */
    private function traceRelations(): array
    {
        $relations = ['thread', 'session', 'job', 'jobs'];

        if (Schema::hasTable('ai_quality_evaluations')) {
            $relations[] = 'qualityEvaluation';
        }

        if (Schema::hasTable('ai_quality_actions')) {
            $relations[] = 'qualityActions.remediationTrace';
        }

        return $relations;
    }

    private function nextRemediationTrace(AiTrace $trace): ?AiTrace
    {
        if (! $trace->relationLoaded('qualityActions')) {
            return null;
        }

        $action = $trace->qualityActions
            ->first(fn ($action): bool => is_string($action->remediation_trace_id) && $action->remediation_trace_id !== '');

        return $action?->remediationTrace;
    }
}
