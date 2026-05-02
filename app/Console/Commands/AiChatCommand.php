<?php

namespace App\Console\Commands;

use App\Console\Concerns\RendersProviderChoiceMenu;
use App\Models\AiJob;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AiProviderChoiceException;
use App\Services\Ai\AiProviderChoiceResolver;
use App\Services\Ai\AiSessionStateService;
use App\Services\Ai\AiWorker;
use App\Services\Ai\AtlasAiRuntimeSettings;
use App\Services\Ai\Cli\AtlasCliPanel;
use App\Services\Ai\Cli\AtlasCliQualityService;
use App\Services\Ai\Cli\AtlasCliSessionService;
use App\Services\Ai\Cli\AtlasCliTelemetry;
use App\Services\Ai\Cli\AtlasImageAttachmentService;
use App\Services\Ai\Cli\AtlasReplHistory;
use App\Services\Ai\Cli\AtlasTerminalTheme;
use App\Services\Ai\Cli\IntentPermissionResolver;
use App\Services\Ai\Cli\IntentResolution;
use App\Services\Ai\Skills\SkillBundleStore;
use App\Services\Ai\Skills\SkillDiscoveryService;
use App\Support\AtlasSecurity;
use App\Support\TerminalMarkdownRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Process\Process;

class AiChatCommand extends Command
{
    use RendersProviderChoiceMenu;

    protected $signature = 'atlas:ai:chat
        {input? : One-shot input. Omit it to open the interactive Atlas CLI loop}
        {--provider= : claude, codex, gemini, conselho, claude_cli, codex_cli, gemini_cli or claude_codex}
        {--model= : Model alias/id for this run, for example sonnet, opus, spark, codex-premium, claude-opus-4-7 or gpt-5.5}
        {--agent= : Force a specific Atlas agent/skill slug}
        {--thread= : Continue a specific Atlas thread}
        {--new-thread : Start a fresh Atlas thread (default unless --thread or --resume-latest is used)}
        {--resume-latest : Continue the latest Atlas CLI thread for this workspace}
        {--workspace= : Workspace path. Defaults to the current directory}
        {--mode=direct : direct, plan, review, dev, debug or research}
        {--dev : Shortcut for --mode=dev --provider=codex}
        {--conselho : Shortcut for --provider=conselho}
        {--stream : Stream provider output while the inline worker runs}
        {--cockpit : Render an operator cockpit header for long terminal work}
        {--permission=auto : auto, read, write or danger}
        {--allow-write : Confirm scoped workspace writes for this run}
        {--dangerously-allow-all : Confirm danger-full-access for this run}
        {--allow-unsandboxed : Allow write/danger mode with providers that Atlas cannot sandbox directly}
        {--trust-workspace-skills : Trust local .atlas/skills and .agents/skills before loading them}
        {--no-skill-prompt : Do not prompt for local skill trust; ignore untrusted workspace skills}
        {--image=* : Attach image file(s) to this prompt}
        {--clipboard-image : Attach the current macOS clipboard image to this prompt}
        {--no-auto-image : Do not auto-attach clipboard images when the prompt mentions screenshots/images}
        {--auto-test : Run detected tests after dev responses}
        {--no-quality-gate : Skip automatic Atlas quality gate in dev mode}
        {--dev-plan= : JSON encoded Atlas dev execution plan}
        {--skill=* : Activate one or more agentskills bundle names}
        {--list-threads : List recent Atlas CLI threads and exit}
        {--no-run : Enqueue only; do not run the local worker inline}
        {--timeout=900 : Seconds to wait when running inline}
        {--compact : Render in compact mode (hide code blocks, keep prose)}
        {--no-intent : Disable intent-based permission elevation; respect --permission verbatim}
        {--json : Print machine-readable JSON}';

    protected $description = 'Use Atlas directly from the Mac while preserving Atlas threads, sessions, memory and provider handoffs.';

    private string $streamedAssistantContent = '';

    private string $markdownStreamBuffer = '';

    private bool $streamOutputStarted = false;

    private string $renderMode = 'full';

    private bool $streamInCodeBlock = false;

    private string $streamCodeLabel = '';

    private int $streamCodeLineCount = 0;

    private bool $intentEnabled = true;

    /** @var array<string,bool> */
    private array $intentSessionAcks = [];

    public function handle(
        AiGatewayService $gateway,
        AiWorker $worker,
        AtlasCliSessionService $cliSessions,
        AtlasCliQualityService $quality,
        AiSessionStateService $states,
        SkillDiscoveryService $skillDiscovery,
        SkillBundleStore $skillBundles,
        AtlasImageAttachmentService $imageAttachments,
        IntentPermissionResolver $intent,
        AtlasReplHistory $history,
    ): int
    {
        $workspace = $this->workspace();
        $provider = $this->providerKey($this->option('conselho') ? 'conselho' : ($this->option('provider') ?: null));
        $modelSelection = $this->modelSelection($this->option('model') ?: null, $provider);
        if ($modelSelection !== null && ! $provider && is_string($modelSelection['provider'] ?? null)) {
            $provider = $modelSelection['provider'];
        }
        $mode = $this->workflowMode($this->option('dev') ? 'dev' : (string) $this->option('mode'));
        if ($this->option('dev') && ! $provider) {
            $provider = $this->defaultProviderKey();
        }
        if ($modelSelection !== null && ! $this->modelSelectionMatchesProvider($modelSelection, $provider)) {
            $this->error('Modelo '.$this->modelSelectionLabel($modelSelection).' nao combina com provider '.($provider ? $this->providerDisplayName($provider) : 'padrao').'. Use --provider correto ou remova --model.');

            return self::FAILURE;
        }
        $permissionMode = $this->permissionMode((string) $this->option('permission'), $mode);
        $stream = (bool) $this->option('stream') && ! (bool) $this->option('json');
        $this->renderMode = (bool) $this->option('compact') ? 'compact' : 'full';
        $this->intentEnabled = ! (bool) $this->option('no-intent');
        $threadId = $this->option('thread') ?: ((bool) $this->option('resume-latest') && ! (bool) $this->option('new-thread') ? $this->latestThreadId($workspace) : null);
        $busyMode = $this->busyInputMode();
        $queuedMessages = [];
        $input = $this->argument('input');
        $activatedSkills = $this->skillOptions();
        $pendingImages = [];

        try {
            $pendingImages = $this->initialImageAttachments($imageAttachments, $workspace);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('list-threads')) {
            $this->printThreads($workspace);

            return self::SUCCESS;
        }

        $skillTrust = $this->prepareSkillBundles($skillDiscovery, $skillBundles, $workspace);

        if (is_string($input) && trim($input) !== '') {
            $pendingImages = $this->maybeAutoAttachClipboardImage($imageAttachments, $workspace, trim($input), $pendingImages);
            $effectivePermission = $this->resolveEffectivePermission($intent, trim($input), $permissionMode);
            $trace = $this->send($gateway, $worker, trim($input), $workspace, $provider, $mode, $effectivePermission, $stream, $threadId, $threadId === null || (bool) $this->option('new-thread'), $activatedSkills, $pendingImages, $modelSelection);
            $this->maybeRunDevQualityGate($quality, $workspace, $mode);

            return $trace->status === 'succeeded' || (bool) $this->option('no-run')
                ? self::SUCCESS
                : self::FAILURE;
        }

        if (! $this->option('json')) {
            $this->printWelcome($workspace, $provider, $mode, $permissionMode, $stream, $busyMode, $threadId, $activatedSkills, $skillTrust, $modelSelection);
            $history->load($workspace);
        }

        while (true) {
            $drainedTrace = $this->drainQueuedMessages($queuedMessages, $gateway, $worker, $quality, $workspace, $provider, $mode, $permissionMode, $stream, $threadId, $activatedSkills, $intent, $modelSelection);
            if ($drainedTrace) {
                $threadId = $drainedTrace->thread_id ?: $threadId;
            }

            try {
                $line = $this->ask($threadId ? "atlas {$this->shortId($threadId)}" : 'atlas');
            } catch (\Symfony\Component\Console\Exception\RuntimeException) {
                $history->save();

                return self::SUCCESS;
            }
            if ($line === null) {
                $history->save();

                return self::SUCCESS;
            }
            if (! is_string($line)) {
                continue;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (in_array($line, ['/exit', '/quit', '/sair'], true)) {
                $history->save();

                return self::SUCCESS;
            }

            if ($line === '/help') {
                $this->printHelp();

                continue;
            }

            if ($line === '/status') {
                $this->printRuntimeStatus($workspace, $provider, $mode, $permissionMode, $stream, $busyMode, $threadId, $activatedSkills, $skillTrust, $modelSelection);

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

            if ($line === '/models') {
                $this->printModelCatalog($provider, $modelSelection);

                continue;
            }

            if ($line === '/model' || str_starts_with($line, '/model ')) {
                $this->handleModelCommand(trim(Str::after($line, '/model')), $provider, $modelSelection);

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
                $history->save();
                $workspace = $this->resolveWorkspace(trim(Str::after($line, '/workspace ')));
                $threadId = null;
                $queuedMessages = [];
                $skillTrust = $this->prepareSkillBundles($skillDiscovery, $skillBundles, $workspace);
                $pendingImages = [];
                $this->line("Workspace ativo: {$workspace}");
                $this->printRuntimeStatus($workspace, $provider, $mode, $permissionMode, $stream, $busyMode, $threadId, $activatedSkills, $skillTrust, $modelSelection);
                $history->load($workspace);

                continue;
            }

            if (str_starts_with($line, '/provider ')) {
                $provider = $this->providerKey(trim(Str::after($line, '/provider ')));
                if ($modelSelection !== null && ! $this->modelSelectionMatchesProvider($modelSelection, $provider)) {
                    $this->warn('Modelo fixado nao combina com esse provider; override de modelo limpo.');
                    $modelSelection = null;
                }
                $this->line('Provider ativo: '.($provider ?: 'padrao'));

                continue;
            }

            if ($line === '/paste-image' || $line === '/clipboard-image') {
                try {
                    $pendingImages = $this->mergeImageAttachments($pendingImages, [$imageAttachments->fromClipboard($workspace)], $imageAttachments);
                    $provider = $provider ?: $this->defaultProviderKey();
                    $this->line('Imagem do clipboard anexada para a proxima mensagem.');
                    $this->printPendingImages($pendingImages);
                } catch (\Throwable $exception) {
                    $this->error($exception->getMessage());
                }

                continue;
            }

            if (str_starts_with($line, '/image ')) {
                try {
                    $paths = $this->imageCommandPaths(trim(Str::after($line, '/image ')));
                    $pendingImages = $this->mergeImageAttachments($pendingImages, $imageAttachments->fromPaths($paths, $workspace), $imageAttachments);
                    $provider = $provider ?: $this->defaultProviderKey();
                    $this->printPendingImages($pendingImages);
                } catch (\Throwable $exception) {
                    $this->error($exception->getMessage());
                }

                continue;
            }

            if ($line === '/images') {
                $this->printPendingImages($pendingImages);

                continue;
            }

            if ($line === '/clear-images') {
                $pendingImages = [];
                $this->line('Imagens pendentes limpas.');

                continue;
            }

            if (str_starts_with($line, '/mode ')) {
                $mode = $this->workflowMode(trim(Str::after($line, '/mode ')));
                if ($mode === 'dev' && ! $provider) {
                    $provider = $this->defaultProviderKey();
                }
                $permissionMode = $this->permissionMode($permissionMode, $mode);
                $this->line("Modo ativo: {$mode}");

                continue;
            }

            $modeShortcut = $this->modeShortcut($line);
            if ($modeShortcut !== null) {
                $mode = $this->workflowMode($modeShortcut);
                if ($mode === 'dev' && ! $provider) {
                    $provider = $this->defaultProviderKey();
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

            if ($line === '/render' || str_starts_with($line, '/render ')) {
                $value = Str::of(Str::after($line, '/render'))->lower()->trim()->value();
                if ($value === '') {
                    $this->line('Render: '.$this->renderMode);

                    continue;
                }
                if (in_array($value, ['compact', 'curto', 'caveman'], true)) {
                    $this->renderMode = 'compact';
                    $this->line('Render: compact (codigo oculto, prosa direta)');

                    continue;
                }
                if (in_array($value, ['full', 'completo', 'tecnico'], true)) {
                    $this->renderMode = 'full';
                    $this->line('Render: full (markdown completo, blocos de codigo visiveis)');

                    continue;
                }
                $this->warn('Use /render compact ou /render full.');

                continue;
            }

            if ($line === '/intent' || str_starts_with($line, '/intent ')) {
                $value = Str::of(Str::after($line, '/intent'))->lower()->trim()->value();
                if ($value === '') {
                    $this->line('Intent: '.($this->intentEnabled ? 'on' : 'off'));

                    continue;
                }
                if (in_array($value, ['on', 'sim', 'true', '1', 'ligado'], true)) {
                    $this->intentEnabled = true;
                    $this->line('Intent: on (sobe permissao por pedido, nunca por padrao)');

                    continue;
                }
                if (in_array($value, ['off', 'nao', 'não', 'false', '0', 'desligado'], true)) {
                    $this->intentEnabled = false;
                    $this->intentSessionAcks = [];
                    $this->line('Intent: off (respeitando --permission verbatim)');

                    continue;
                }
                if (in_array($value, ['reset', 'limpar', 'clear'], true)) {
                    $this->intentSessionAcks = [];
                    $this->line('Intent: acks de sessao limpos');

                    continue;
                }
                $this->warn('Use /intent on, /intent off, ou /intent reset.');

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

            $pendingImages = $this->maybeAutoAttachClipboardImage($imageAttachments, $workspace, $line, $pendingImages);
            if ($pendingImages !== [] && ! $provider) {
                $provider = $this->defaultProviderKey();
            }

            $activeTrace = $this->activeTrace($threadId, $workspace);
            if ($activeTrace) {
                $threadId = $activeTrace->thread_id ?: $threadId;
                $this->showBusyHintOnce();

                if ($busyMode === 'queue') {
                    $queuedMessages[] = [
                        'input' => $line,
                        'skills' => $messageSkills,
                        'images' => $pendingImages,
                        'model' => $modelSelection,
                    ];
                    $pendingImages = [];
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

            $effectivePermission = $this->resolveEffectivePermission($intent, $line, $permissionMode);
            $trace = $this->send($gateway, $worker, $line, $workspace, $provider, $mode, $effectivePermission, $stream, $threadId, $threadId === null, $messageSkills, $pendingImages, $modelSelection);
            $pendingImages = [];
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
        array $imageAttachments = [],
        ?array $modelSelection = null,
    ): AiTrace {
        $this->streamedAssistantContent = '';
        $this->markdownStreamBuffer = '';
        $this->streamOutputStarted = false;
        $this->streamInCodeBlock = false;
        $this->streamCodeLabel = '';
        $this->streamCodeLineCount = 0;

        $activatedSkills = $this->normalizeSkillNames($activatedSkills);
        $imageAttachments = $this->normalizeImageAttachments($imageAttachments);
        $inputForPrompt = $imageAttachments === [] ? $input : $this->inputWithImageSummary($input, $imageAttachments);
        $agentSlug = $this->agentSlug($mode);
        $modelOverride = $this->modelOverrideFromSelection($modelSelection);
        if ($provider === 'gemini_cli' && $mode === 'dev') {
            throw new \RuntimeException('Gemini CLI é restrito a análise read-only; use Claude/Codex para modo dev.');
        }
        $telemetry = app(AtlasCliTelemetry::class);
        $correlationId = $telemetry->correlationId();
        $interactionStartedAt = microtime(true);
        $telemetry->interactionSubmitted(
            $correlationId,
            $input,
            $workspace,
            $threadId,
            $provider,
            $agentSlug,
            $mode,
            $permissionMode,
            $stream,
            $newThread,
            [
                'activated_skills_count' => count($activatedSkills),
                'image_attachments_count' => count($imageAttachments),
                'model' => $modelOverride,
                'model_label' => $modelSelection['label'] ?? null,
                'model_tier' => $modelSelection['tier'] ?? null,
            ],
        );
        $payload = [
            'app_surface' => 'atlas_cli',
            'atlas_workflow_mode' => $mode,
            'workspace' => $workspace,
            'decision_mode' => $provider ? 'manual_override' : 'atlas_decide',
            'operator_requested_provider' => $provider ?: 'auto',
            'requested_provider' => $provider,
            'requested_model' => $modelOverride,
            'requested_model_label' => $modelSelection['label'] ?? null,
            'requested_model_tier' => $modelSelection['tier'] ?? null,
            'requested_model_source' => $modelSelection['source'] ?? null,
            'requested_agent' => $agentSlug,
            'workspace_context' => $this->workspaceContext($workspace),
            'tool_permissions' => $this->toolPermissions($workspace, $mode, $provider, $permissionMode),
        ];
        if ($activatedSkills !== []) {
            $payload['activated_skills'] = $activatedSkills;
        }
        if ($imageAttachments !== []) {
            $payload['attachments'] = [
                'images' => $imageAttachments,
            ];
        }

        $devPlan = $this->devExecutionPlanOption();
        if ($devPlan !== null) {
            $payload['dev_execution_plan'] = $devPlan;
        }

        if ($provider === 'claude_codex') {
            $payload['execution_policy'] = 'dual_review';
            $payload['council_providers'] = ['claude_cli', 'codex_cli'];
        }

        try {
            $options = [
                'source_type' => 'manual',
                'agent_slug' => $agentSlug,
                'provider' => $provider,
                'thread_id' => $threadId,
                'new_thread' => $newThread,
                'priority' => 10,
                'include_semantic_context' => true,
                'timeout_seconds' => min(max((int) $this->option('timeout'), 15), 1800),
                'payload' => $payload,
            ];
            if ($modelOverride !== null) {
                $options['model'] = $modelOverride;
            }

            $trace = $gateway->enqueueInteraction($inputForPrompt, $options);
        } catch (\Throwable $exception) {
            $telemetry->interactionFailed($correlationId, $exception, $this->elapsedMs($interactionStartedAt), [
                'phase' => 'enqueue',
                'mode' => $mode,
                'provider' => $provider,
            ]);

            throw $exception;
        }

        $telemetry->interactionEnqueued($correlationId, $trace, $this->elapsedMs($interactionStartedAt), [
            'mode' => $mode,
            'permission_mode' => $permissionMode,
            'stream' => $stream,
            'no_run' => (bool) $this->option('no-run'),
        ]);

        if ((bool) $this->option('no-run')) {
            $this->printTrace($trace->load($this->traceRelations()));

            return $trace;
        }

        try {
            $trace = $this->runInline($worker, $trace, $stream);
        } catch (\Throwable $exception) {
            $telemetry->interactionFailed($correlationId, $exception, $this->elapsedMs($interactionStartedAt), [
                'phase' => 'inline_worker',
                'trace_id' => $trace->id,
                'thread_id' => $trace->thread_id,
                'mode' => $mode,
                'provider' => $provider,
            ]);

            throw $exception;
        }
        $telemetry->interactionCompleted($correlationId, $trace, $this->elapsedMs($interactionStartedAt), [
            'mode' => $mode,
            'permission_mode' => $permissionMode,
            'stream' => $stream,
        ]);
        $this->printTrace($trace);

        return $trace;
    }

    private function elapsedMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    /**
     * @param  array<int,array{input:string,skills:array<int,string>,images?:array<int,array<string,mixed>>,model?:array<string,mixed>|null}|string>  $queuedMessages
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
        ?IntentPermissionResolver $intent = null,
        ?array $modelSelection = null,
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
        $batchImages = collect($items)
            ->flatMap(fn (mixed $item): array => is_array($item) ? (array) ($item['images'] ?? []) : [])
            ->values()
            ->all();
        $batchModelSelection = collect($items)
            ->map(fn (mixed $item): mixed => is_array($item) ? ($item['model'] ?? null) : null)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->last() ?: $modelSelection;
        $messageCount = count($items);
        $this->line("(queued batch - sending {$messageCount} message(s))");

        $effectivePermission = $intent
            ? $this->resolveEffectivePermission($intent, $batch, $permissionMode)
            : $permissionMode;
        $trace = $this->send($gateway, $worker, $batch, $workspace, $provider ?: ($batchImages !== [] ? $this->defaultProviderKey() : null), $mode, $effectivePermission, $stream, $threadId, $threadId === null, $batchSkills, $batchImages, $batchModelSelection);
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

        if (! $threadId) {
            return null;
        }

        return AiTrace::query()
            ->whereIn('status', ['queued', 'processing'])
            ->with('thread')
            ->where('thread_id', $threadId)
            ->latest('updated_at')
            ->first();
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

            $pausedJob = AiJob::query()
                ->where('trace_id', $trace->id)
                ->where('status', 'awaiting_user_choice')
                ->orderByDesc('created_at')
                ->first();

            if ($pausedJob) {
                $optionId = $this->promptProviderChoice($pausedJob);
                try {
                    $outcome = app(AiProviderChoiceResolver::class)->resolve($pausedJob, $optionId);
                } catch (AiProviderChoiceException $exception) {
                    $this->error('Erro ao resolver escolha: '.$exception->getMessage());
                    sleep(1);
                    continue;
                }

                $this->announceChoiceOutcome($outcome['action'], $outcome['option']);

                if (in_array($outcome['action'], ['wait', 'fail', 'cancel'], true)) {
                    return $trace->fresh($this->traceRelations()) ?: $trace;
                }

                continue;
            }

            if (in_array($trace->status, ['succeeded', 'failed', 'cancelled'], true)) {
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

            if (in_array($trace->status, ['succeeded', 'failed', 'cancelled'], true)) {
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
                'model' => $trace->model,
                'model_label' => data_get($trace->metadata, 'model_label'),
                'model_tier' => data_get($trace->metadata, 'model_tier'),
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

        $this->flushMarkdownStream();

        $this->line('');
        $statusStyle = match ($trace->status) {
            'succeeded' => 'fg=green;options=bold',
            'failed' => 'fg=red;options=bold',
            default => 'fg=yellow;options=bold',
        };
        $runtime = ['provider '.$trace->provider];
        $modelLabel = data_get($trace->metadata, 'model_label') ?: $trace->model;
        if (is_string($modelLabel) && trim($modelLabel) !== '') {
            $runtime[] = 'model '.$modelLabel;
        }
        $this->line('<fg=bright-blue;options=bold>Atlas</> <fg=gray>trace '.$this->shortId((string) $trace->id).'</> <'.$statusStyle.'>'.$trace->status.'</> <fg=gray>'.OutputFormatter::escape(implode(' · ', $runtime)).'</>');
        $this->line('<fg=gray>thread '.$this->shortId((string) $trace->thread_id).'</>');
        $activatedSkills = collect((array) data_get($trace->metadata, 'skills_activated', []))
            ->pluck('name')
            ->filter()
            ->implode(', ');
        if ($activatedSkills !== '') {
            $this->line('<fg=gray>skills '.$activatedSkills.'</>');
        }

        if ($quality) {
            $flags = collect($quality->flags)->pluck('code')->implode(', ') ?: 'none';
            $qualityStyle = $quality->status === 'passed' ? 'fg=green' : ($quality->status === 'failed' ? 'fg=red' : 'fg=yellow');
            $this->line('<'.$qualityStyle.'>quality '.$quality->score.'/100 '.$quality->status.'</> <fg=gray>flags '.$flags.'</>');
        }

        if ($trace->response_text && $this->streamedAssistantContent === '') {
            $this->line('');
            $this->line($this->renderMarkdown($trace->response_text));
        } elseif ($trace->job?->error_message) {
            $this->line('');
            $summary = $this->summarizeErrorContent((string) $trace->job->error_message);
            $this->line('<fg=red;options=bold>✗ '.$summary.'</>');
            if ($this->output->isVerbose()) {
                $this->line('<fg=gray>'.OutputFormatter::escape((string) $trace->job->error_message).'</>');
            }
        }
    }

    private function printStreamEvent(array $event): void
    {
        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        $content = is_string($event['content'] ?? null) ? $event['content'] : '';

        if ($type === 'token' && $content !== '') {
            $this->printMarkdownStreamChunk($content);
            $this->streamedAssistantContent .= $content;

            return;
        }

        if ($type === 'error' && $content !== '') {
            if ($this->streamedAssistantContent !== '') {
                $this->flushMarkdownStream();
            }
            $summary = $this->summarizeErrorContent($content);
            $this->line('<fg=red;options=bold>✗ '.$summary.'</>');
            if ($this->output->isVerbose()) {
                $this->line('<fg=gray>'.OutputFormatter::escape($content).'</>');
            }

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

    private function summarizeErrorContent(string $content): string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($content)) ?: [];
        $clean = array_values(array_filter($lines, fn (string $line): bool => trim($line) !== ''));

        if ($clean === []) {
            return 'erro sem mensagem';
        }

        foreach ($clean as $line) {
            if (preg_match('/^\s*(error|erro|fatal|exception):/i', $line) === 1) {
                return $this->truncateForSingleLine(trim($line));
            }
        }

        return $this->truncateForSingleLine(trim((string) end($clean)));
    }

    private function truncateForSingleLine(string $line): string
    {
        $line = OutputFormatter::escape($line);
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;

        return mb_strlen($line) > 200 ? mb_substr($line, 0, 197).'...' : $line;
    }

    private function printMarkdownStreamChunk(string $content): void
    {
        if (! $this->streamOutputStarted) {
            $this->newLine();
            $this->line('<fg=bright-blue;options=bold>Atlas</>');
            $this->streamOutputStarted = true;
        }

        $this->markdownStreamBuffer .= str_replace("\r\n", "\n", str_replace("\r", "\n", $content));
        $lines = explode("\n", $this->markdownStreamBuffer);
        $this->markdownStreamBuffer = (string) array_pop($lines);

        foreach ($lines as $line) {
            $this->emitStreamLine($line);
        }
    }

    private function emitStreamLine(string $line): void
    {
        if (preg_match('/^\s*```([^`]*)\s*$/', $line, $match) === 1) {
            if (! $this->streamInCodeBlock) {
                $this->streamInCodeBlock = true;
                $this->streamCodeLabel = trim((string) ($match[1] ?? ''));
                $this->streamCodeLineCount = 0;
                if ($this->renderMode === 'compact') {
                    return;
                }
            } else {
                $count = $this->streamCodeLineCount;
                $label = $this->streamCodeLabel !== '' ? $this->streamCodeLabel : 'codigo';
                $this->streamInCodeBlock = false;
                $this->streamCodeLabel = '';
                $this->streamCodeLineCount = 0;
                if ($this->renderMode === 'compact') {
                    $word = $count === 1 ? 'linha oculta' : 'linhas ocultas';
                    $summary = '['.$label.' - '.$count.' '.$word.']';
                    $this->output->write($this->renderMarkdown($summary).PHP_EOL);

                    return;
                }
            }

            $this->output->write($this->renderMarkdown($line).PHP_EOL);

            return;
        }

        if ($this->streamInCodeBlock) {
            $this->streamCodeLineCount++;
            if ($this->renderMode === 'compact') {
                return;
            }
        }

        $this->output->write($this->renderMarkdown($line).PHP_EOL);
    }

    private function flushMarkdownStream(): void
    {
        if ($this->markdownStreamBuffer === '') {
            return;
        }

        if ($this->streamInCodeBlock && $this->renderMode === 'compact') {
            $this->markdownStreamBuffer = '';

            return;
        }

        $this->output->write($this->renderMarkdown($this->markdownStreamBuffer));
        $this->markdownStreamBuffer = '';
        $this->newLine();
    }

    private function renderMarkdown(string $markdown): string
    {
        return app(TerminalMarkdownRenderer::class)->render(
            $markdown,
            $this->output->isDecorated(),
            $this->renderMode === 'compact',
        );
    }

    private function printHelp(): void
    {
        $decorated = $this->output->isDecorated();
        $sections = $this->helpSections();

        $this->newLine();
        $this->line(AtlasTerminalTheme::bold('  comandos atlas cli', $decorated));
        $this->line('  '.AtlasTerminalTheme::muted(str_repeat('═', 18), $decorated));

        foreach ($sections as $section) {
            $this->newLine();
            $title = AtlasTerminalTheme::accent('· '.$section['title'], $decorated);
            $hint = AtlasTerminalTheme::dimItalic('· '.$section['when'], $decorated);
            $this->line('  '.$title.' '.$hint);

            $maxCmd = 0;
            foreach ($section['commands'] as [$cmd, $desc]) {
                $maxCmd = max($maxCmd, mb_strlen($cmd, 'UTF-8'));
            }
            $maxCmd = min(28, $maxCmd);

            foreach ($section['commands'] as [$cmd, $desc]) {
                $cmdPadded = str_pad($cmd, $maxCmd, ' ', STR_PAD_RIGHT);
                $this->line('    '.AtlasTerminalTheme::accent($cmdPadded, $decorated).'  '.$desc);
            }
        }
        $this->newLine();
    }

    /**
     * @return list<array{title:string,when:string,commands:list<array{0:string,1:string}>}>
     */
    private function helpSections(): array
    {
        return [
            [
                'title' => 'conversa & contexto',
                'when' => 'comecar, retomar ou trocar de conversa',
                'commands' => [
                    ['/new', 'comeca thread nova'],
                    ['/thread <id>', 'retoma thread especifica'],
                    ['/threads', 'lista threads recentes'],
                    ['/workspace <path>', 'troca workspace e abre nova thread'],
                    ['/exit', 'encerra (ou ctrl+d)'],
                ],
            ],
            [
                'title' => 'modo & comportamento',
                'when' => 'ajustar como a proxima resposta vai vir',
                'commands' => [
                    ['/mode <X>', 'X = direct | plan | review | dev | debug | research'],
                    ['/plan /review ...', 'atalho para /mode <X>'],
                    ['/provider <X>', 'X = claude | codex | conselho | padrao (atlas decide)'],
                    ['/model <X>', 'X = sonnet | spark | default | model-id explicito'],
                    ['/models', 'lista modelos e tiers configurados'],
                    ['/permission <X>', 'X = auto | read | write | danger (intent decide auto)'],
                    ['/stream <on|off>', 'streaming token-a-token'],
                    ['/render <X>', 'X = full | compact (compact esconde codigo)'],
                    ['/intent <X>', 'X = on | off | reset (sobe permissao por pedido)'],
                ],
            ],
            [
                'title' => 'durante execucao',
                'when' => 'atlas esta rodando, voce quer interagir',
                'commands' => [
                    ['/busy <X>', 'X = interrupt | queue | steer | status'],
                    ['/steer <texto>', 'nudge sem cortar a execucao'],
                ],
            ],
            [
                'title' => 'imagens',
                'when' => 'screenshot, tela, bug visual ou design',
                'commands' => [
                    ['automático', 'copie screenshot no macOS e peça "analise essa tela"'],
                    ['/paste-image', 'anexa a imagem atual do clipboard do macOS'],
                    ['/image <path>', 'anexa arquivo png/jpg/webp/gif'],
                    ['/images', 'lista imagens anexadas para a proxima mensagem'],
                    ['/clear-images', 'limpa anexos pendentes'],
                ],
            ],
            [
                'title' => 'memoria da sessao',
                'when' => 'fixar objetivo, fase, proximos passos',
                'commands' => [
                    ['/state', 'estado completo (objetivo, fase, loops, etc)'],
                    ['/objective <texto>', 'fixa objetivo'],
                    ['/phase <texto>', 'fixa fase atual'],
                    ['/topic <texto>', 'fixa topico'],
                    ['/note <texto>', 'registra nota operacional'],
                    ['/next <texto>', 'registra proximo passo'],
                    ['/compact', 'compacta thread quando ficar longa'],
                ],
            ],
            [
                'title' => 'diagnostico',
                'when' => 'verificar runtime, providers, qualidade',
                'commands' => [
                    ['/status', 'runtime completo (todos os toggles)'],
                    ['/quality', 'quality gate da ultima execucao dev'],
                    ['/doctor', 'diagnostico terminal + atlas'],
                    ['/providers', 'estrategia atual de providers'],
                    ['/skills <X>', 'X = list | show | doctor'],
                    ['/checkpoint', 'checkpoints do workspace'],
                ],
            ],
            [
                'title' => 'provider handoff',
                'when' => 'trocar de provider mid-thread mantendo contexto',
                'commands' => [
                    ['/handoff <X>', 'X = claude | codex'],
                ],
            ],
        ];
    }

    /**
     * @return array{has_local:bool,trusted:bool,prompted:bool,auto_trusted:bool,ignored:bool}
     */
    private function prepareSkillBundles(SkillDiscoveryService $discovery, SkillBundleStore $bundles, string $workspace): array
    {
        $hasLocal = $discovery->workspaceHasLocalSkills($workspace);
        $trusted = $hasLocal && $discovery->isWorkspaceTrusted($workspace);
        $prompted = false;
        $autoTrusted = false;
        $ignored = false;

        if ($hasLocal && ! $trusted && (bool) $this->option('trust-workspace-skills')) {
            $discovery->trustWorkspace($workspace);
            $trusted = true;
            $autoTrusted = true;
        }

        if (
            ! (bool) $this->option('json')
            && ! (bool) $this->option('no-skill-prompt')
            && $hasLocal
            && ! $trusted
        ) {
            $prompted = true;
            $this->warn('Este workspace contem skills locais em .atlas/skills ou .agents/skills.');
            $this->warn('Skills locais podem influenciar o comportamento do Atlas. Confie apenas em repositorios que voce controla.');
            if ($this->confirm('Confiar nas skills locais deste workspace?', false)) {
                $discovery->trustWorkspace($workspace);
                $trusted = true;
                $this->line('Workspace marcado como confiavel para skills locais.');
            } else {
                $ignored = true;
                $this->line('Skills locais ignoradas nesta sessao. Skills builtin, user e Vault continuam disponiveis.');
            }
        }

        if ($hasLocal && ! $trusted && ! $prompted) {
            $ignored = true;
        }

        $bundles->clear();
        $bundles->registerAll($discovery->discoverAll($workspace));

        return [
            'has_local' => $hasLocal,
            'trusted' => $trusted,
            'prompted' => $prompted,
            'auto_trusted' => $autoTrusted,
            'ignored' => $ignored,
        ];
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
     * @return array<int,array<string,mixed>>
     */
    private function initialImageAttachments(AtlasImageAttachmentService $images, string $workspace): array
    {
        $attachments = $images->fromPaths((array) $this->option('image'), $workspace);

        if ((bool) $this->option('clipboard-image')) {
            $attachments[] = $images->fromClipboard($workspace);
        }

        return $images->dedupe($attachments);
    }

    /**
     * @param  array<int,array<string,mixed>>  $existing
     * @param  array<int,array<string,mixed>>  $incoming
     * @return array<int,array<string,mixed>>
     */
    private function mergeImageAttachments(array $existing, array $incoming, AtlasImageAttachmentService $images): array
    {
        return $images->dedupe(array_merge($existing, $incoming));
    }

    /**
     * @param  array<int,array<string,mixed>>  $pending
     * @return array<int,array<string,mixed>>
     */
    private function maybeAutoAttachClipboardImage(AtlasImageAttachmentService $images, string $workspace, string $input, array $pending): array
    {
        if ($pending !== [] || (bool) $this->option('no-auto-image') || ! $this->shouldAutoAttachClipboardImage($input)) {
            return $pending;
        }

        try {
            $attachment = $images->fromClipboard($workspace);
        } catch (\Throwable) {
            return $pending;
        }

        if (! (bool) $this->option('json')) {
            $this->line('Imagem do clipboard detectada e anexada automaticamente.');
        }

        return $images->dedupe([$attachment]);
    }

    private function shouldAutoAttachClipboardImage(string $input): bool
    {
        $text = Str::of($input)->lower()->ascii()->squish()->value();
        if ($text === '' || str_starts_with($text, '/')) {
            return false;
        }

        foreach (['sem imagem', 'sem screenshot', 'sem print', 'nao tem imagem', 'não tem imagem'] as $negative) {
            if (str_contains($text, Str::of($negative)->ascii()->value())) {
                return false;
            }
        }

        $patterns = [
            '/\b(screenshot|print|imagem|foto|captura de tela)\b/',
            '/\b(essa|esta|nessa|nesta|isso|isto)\s+(tela|ui|interface|imagem|foto|screenshot|print)\b/',
            '/\b(tela|ui|interface)\s+(acima|anexada|copiada|do print|da imagem)\b/',
            '/\bbug visual\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private function imageCommandPaths(string $input): array
    {
        if ($input === '') {
            throw new \RuntimeException('Uso: /image <caminho-da-imagem>');
        }

        if (File::isFile($this->expandUserPath($input))) {
            return [$input];
        }

        return $this->simpleArguments($input);
    }

    private function expandUserPath(string $path): string
    {
        if (! str_starts_with($path, '~/')) {
            return $path;
        }

        $home = rtrim((string) ($_SERVER['HOME'] ?? getenv('HOME') ?: dirname(base_path())), DIRECTORY_SEPARATOR);

        return $home.substr($path, 1);
    }

    /**
     * @param  array<int,array<string,mixed>>  $attachments
     * @return array<int,array<string,mixed>>
     */
    private function normalizeImageAttachments(array $attachments): array
    {
        return collect($attachments)
            ->filter(fn (mixed $attachment): bool => is_array($attachment) && is_string($attachment['path'] ?? null))
            ->map(fn (array $attachment): array => [
                'path' => (string) $attachment['path'],
                'source' => (string) ($attachment['source'] ?? 'file'),
                'original_path' => (string) ($attachment['original_path'] ?? $attachment['path']),
                'mime_type' => (string) ($attachment['mime_type'] ?? 'application/octet-stream'),
                'bytes' => (int) ($attachment['bytes'] ?? 0),
                'sha256' => (string) ($attachment['sha256'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $attachments
     */
    private function inputWithImageSummary(string $input, array $attachments): string
    {
        $lines = ["{$input}\n\n[Atlas CLI anexou imagem(ns) reais a esta mensagem. Analise visualmente o conteúdo anexado; não trate como apenas caminho de arquivo.]"];

        foreach ($attachments as $index => $attachment) {
            $number = $index + 1;
            $lines[] = sprintf(
                '- imagem %d: %s (%s, %s bytes, source=%s)',
                $number,
                (string) $attachment['path'],
                (string) $attachment['mime_type'],
                (string) $attachment['bytes'],
                (string) $attachment['source'],
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int,array<string,mixed>>  $attachments
     */
    private function printPendingImages(array $attachments): void
    {
        if ($attachments === []) {
            $this->line('Nenhuma imagem anexada para a proxima mensagem.');

            return;
        }

        $this->line('Imagens anexadas para a proxima mensagem:');
        foreach ($attachments as $index => $attachment) {
            $this->line(sprintf(
                '  %d. %s (%s)',
                $index + 1,
                (string) ($attachment['original_path'] ?? $attachment['path'] ?? '-'),
                (string) ($attachment['mime_type'] ?? 'image'),
            ));
        }
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

    /**
     * @param  array<int,string>  $activatedSkills
     * @param  array<string,mixed>  $skillTrust
     */
    private function printWelcome(string $workspace, ?string $provider, string $mode, string $permissionMode, bool $stream, string $busyMode, ?string $threadId, array $activatedSkills = [], array $skillTrust = [], ?array $modelSelection = null): void
    {
        $title = (bool) $this->option('cockpit') ? 'atlas dev cockpit' : 'atlas cli';
        $this->renderConsolePanel($title, $workspace, $provider, $mode, $permissionMode, $stream, $busyMode, $threadId, $activatedSkills, $skillTrust, $modelSelection);
    }

    /**
     * @param  array<int,string>  $activatedSkills
     * @param  array<string,mixed>  $skillTrust
     */
    private function renderConsolePanel(string $title, string $workspace, ?string $provider, string $mode, string $permissionMode, bool $stream, string $busyMode, ?string $threadId, array $activatedSkills = [], array $skillTrust = [], ?array $modelSelection = null): void
    {
        $context = $this->workspaceContext($workspace);
        $branch = (string) ($context['branch'] ?: '-');
        $dirtyCount = count((array) ($context['dirty_files'] ?? []));
        $decorated = $this->output->isDecorated();
        $width = $this->panelWidth();
        $tag = $threadId ? 'thread '.$this->shortId($threadId) : 'nova thread';
        $skillsValue = $activatedSkills === [] ? 'auto' : implode(', ', $activatedSkills);
        $localSkills = $this->skillTrustLabel($skillTrust);
        $providerLabel = $provider ? $this->providerDisplayName($provider) : 'padrao ('.$this->providerDisplayName($this->defaultProviderKey()).')';
        $rootsValue = $this->panelRootsSummary();

        $panel = AtlasCliPanel::make($decorated, $width)
            ->open($title, $tag)
            ->blank()
            ->section('identidade')
            ->kv('workspace', $workspace)
            ->kv('thread', $threadId ?: AtlasTerminalTheme::muted('nova', $decorated))
            ->kv('git', 'branch '.$branch.' '.AtlasTerminalTheme::muted('· '.$dirtyCount.' dirty', $decorated))
            ->blank()
            ->section('runtime')
            ->kv('provider', $providerLabel)
            ->kv('modelo', $this->modelPanelValue($provider, $modelSelection, $decorated))
            ->kv('modo', $mode)
            ->kv('permissao', $this->panelPermissionValue($permissionMode, $decorated))
            ->kv('stream', $this->panelTogglePair([
                'stream' => $stream ? 'on' : 'off',
                'render' => $this->renderMode,
                'intent' => $this->intentEnabled ? 'on' : 'off',
                'busy' => $busyMode,
            ], $decorated))
            ->kv('skills', $skillsValue.AtlasTerminalTheme::muted(' · locais '.$localSkills, $decorated))
            ->blank()
            ->section('controle')
            ->kv('raizes', $rootsValue)
            ->kv('sudo', AtlasTerminalTheme::muted('so com pedido explicito', $decorated))
            ->blank()
            ->line(AtlasTerminalTheme::dimItalic('/help · /model · /paste-image · /status · /handoff codex|claude · /exit', $decorated))
            ->blank()
            ->close()
            ->build();

        $this->newLine();
        foreach ($panel as $line) {
            $this->line($line);
        }

        if (($skillTrust['ignored'] ?? false) === true) {
            $this->line('  '.AtlasTerminalTheme::risk('skills locais ignoradas', $decorated).' '.AtlasTerminalTheme::muted('· atlas skills trust', $decorated));
        }
        $this->newLine();
    }

    /**
     * @param  array<string,string>  $pairs
     */
    private function panelTogglePair(array $pairs, bool $decorated): string
    {
        $parts = [];
        foreach ($pairs as $key => $value) {
            $parts[] = AtlasTerminalTheme::muted($key, $decorated).' '.$value;
        }

        return implode(AtlasTerminalTheme::muted('  ·  ', $decorated), $parts);
    }

    private function panelPermissionValue(string $permissionMode, bool $decorated): string
    {
        $tone = match ($permissionMode) {
            'danger' => AtlasTerminalTheme::risk($permissionMode, $decorated),
            'write' => AtlasTerminalTheme::accent($permissionMode, $decorated),
            default => AtlasTerminalTheme::ok($permissionMode, $decorated),
        };
        $hint = match ($permissionMode) {
            'danger' => 'liberdade dentro das raizes',
            'write' => 'edicao no workspace',
            default => 'so leitura e inspecao',
        };

        return $tone.' '.AtlasTerminalTheme::muted('· '.$hint, $decorated);
    }

    private function panelRootsSummary(): string
    {
        $roots = $this->allowedRootsForPrompt();
        if ($roots === []) {
            return AtlasTerminalTheme::muted('sem raizes configuradas', $this->output->isDecorated());
        }
        $shown = array_slice($roots, 0, 2);
        $extra = count($roots) - count($shown);

        return implode(', ', $shown).($extra > 0 ? AtlasTerminalTheme::muted(' +'.$extra, $this->output->isDecorated()) : '');
    }

    private function panelWidth(): int
    {
        $terminal = new \Symfony\Component\Console\Terminal;
        $cols = $terminal->getWidth();
        if ($cols <= 0) {
            $cols = 80;
        }

        return min(96, max(64, $cols - 2));
    }

    private function dim(string $text): string
    {
        if (! $this->output->isDecorated()) {
            return $text;
        }

        return "\033[2m".$text."\033[0m";
    }

    /**
     * @param  array<int,string>  $activatedSkills
     * @param  array<string,mixed>  $skillTrust
     */
    private function printRuntimeStatus(string $workspace, ?string $provider, string $mode, string $permissionMode, bool $stream, string $busyMode, ?string $threadId, array $activatedSkills = [], array $skillTrust = [], ?array $modelSelection = null): void
    {
        $title = (bool) $this->option('cockpit') ? 'atlas dev status' : 'atlas status';
        $this->renderConsolePanel($title, $workspace, $provider, $mode, $permissionMode, $stream, $busyMode, $threadId, $activatedSkills, $skillTrust, $modelSelection);
    }

    private function permissionBadge(string $permissionMode): string
    {
        return match ($permissionMode) {
            'danger' => '<fg=red;options=bold>DANGER</>',
            'write' => '<fg=yellow;options=bold>WRITE</>',
            default => '<fg=green;options=bold>READ</>',
        };
    }

    private function permissionNotice(string $permissionMode, bool $plain = false): string
    {
        return match ($permissionMode) {
            'danger' => $plain
                ? 'PERMISSAO: DANGER - Atlas pode operar dentro das raizes autorizadas; sudo exige pedido explicito.'
                : '<fg=red;options=bold>PERMISSAO DANGER</> <fg=gray>Atlas pode operar dentro das raizes autorizadas; sudo exige pedido explicito.</>',
            'write' => $plain
                ? 'PERMISSAO: WRITE - Atlas pode editar e rodar comandos no workspace.'
                : '<fg=yellow;options=bold>PERMISSAO WRITE</> <fg=gray>Atlas pode editar e rodar comandos no workspace.</>',
            default => $plain
                ? 'PERMISSAO: READ - Atlas apenas le e inspeciona.'
                : '<fg=green;options=bold>PERMISSAO READ</> <fg=gray>Atlas apenas le e inspeciona.</>',
        };
    }

    private function printControlBanner(string $workspace, string $permissionMode): void
    {
        $roots = $this->allowedRootsForPrompt();
        $rootSummary = $roots !== [] ? implode(', ', array_slice($roots, 0, 3)) : $workspace;
        $extra = count($roots) > 3 ? ' +'.(count($roots) - 3) : '';
        $meaning = match ($permissionMode) {
            'danger' => 'pode operar dentro das raizes',
            'write'  => 'pode editar e rodar dentro do workspace',
            default  => 'so leitura e inspecao',
        };
        $intentLine = $this->intentEnabled
            ? 'intent · ativo · sobe permissao por pedido, nunca por padrao'
            : 'intent · desligado · respeitando --permission verbatim';

        $this->line($this->dimItalic('  · controle · '.$workspace));
        $this->line($this->dimItalic('  · raizes ·   '.$rootSummary.$extra));
        $this->line($this->dimItalic('  · sudo ·     so com pedido explicito'));
        $this->line($this->dimItalic('  · permissao · '.$permissionMode.' · '.$meaning));
        $this->line($this->dimItalic('  · '.$intentLine));
    }

    private function dimItalic(string $text): string
    {
        if (! $this->output->isDecorated()) {
            return $text;
        }

        return "\033[2;3m".$text."\033[0m";
    }

    private function resolveEffectivePermission(IntentPermissionResolver $resolver, string $input, string $currentPermission): string
    {
        if (! $this->intentEnabled) {
            return $currentPermission;
        }

        if (trim($input) === '' || str_starts_with(trim($input), '/')) {
            return $currentPermission;
        }

        if ((bool) $this->option('dangerously-allow-all') || (bool) $this->option('allow-write')) {
            return $currentPermission;
        }

        if ($currentPermission === 'danger') {
            return $currentPermission;
        }

        $resolution = $resolver->resolve($input, $currentPermission);

        if (! $resolution->isReadOnly() && ! (bool) $this->option('json')) {
            $this->renderIntentNotice($resolution);
        }

        if (! $resolution->changed) {
            return $currentPermission;
        }

        if ($this->intentSessionAcks[$resolution->required] ?? false) {
            return $resolution->required;
        }

        if ((bool) $this->option('json') || ! $this->input->isInteractive()) {
            $this->renderIntentBlocked($resolution);

            return $currentPermission;
        }

        return $this->confirmIntentEscalation($resolution, $currentPermission);
    }

    private function renderIntentNotice(IntentResolution $resolution): void
    {
        if ($resolution->signals === []) {
            return;
        }
        $signal = $resolution->signals[0];
        $this->line($this->dimItalic('  · esta acao vai '.$signal));
    }

    private function renderIntentBlocked(IntentResolution $resolution): void
    {
        if ((bool) $this->option('json')) {
            return;
        }
        $this->warn(sprintf(
            'intent quer %s (motivo: %s). rode com --permission=%s ou abra o REPL para confirmar.',
            $resolution->required,
            $resolution->reason,
            $resolution->required,
        ));
    }

    private function confirmIntentEscalation(IntentResolution $resolution, string $currentPermission): string
    {
        $this->line($this->dimItalic(
            '  · subir para '.$resolution->required.'? [s] sim · [a] sessao inteira · [n] nao',
        ));
        $answer = (string) $this->ask('atlas', 'n');
        $normalized = strtolower(trim($answer));

        if (in_array($normalized, ['s', 'sim', 'y', 'yes', '1'], true)) {
            $this->line($this->dimItalic('  · permissao temporaria · '.$resolution->required));

            return $resolution->required;
        }

        if (in_array($normalized, ['a', 'all', 'sessao', 'sessão', 'session', 'sempre'], true)) {
            $this->intentSessionAcks[$resolution->required] = true;
            $this->line($this->dimItalic('  · permissao da sessao · '.$resolution->required));

            return $resolution->required;
        }

        $this->line($this->dimItalic('  · seguindo em '.$currentPermission.' (operador disse nao)'));

        return $currentPermission;
    }

    /**
     * @param  array<string,mixed>  $skillTrust
     */
    private function skillTrustLabel(array $skillTrust): string
    {
        if (($skillTrust['has_local'] ?? false) !== true) {
            return 'none';
        }

        if (($skillTrust['trusted'] ?? false) === true) {
            return ($skillTrust['auto_trusted'] ?? false) === true ? 'trusted now' : 'trusted';
        }

        return 'not trusted';
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
        $allowUnsandboxedProvider = (bool) $this->option('allow-unsandboxed')
            || (bool) config('atlas.ai.tool_permissions.allow_unsandboxed_write', false);

        return [
            'schema_version' => 1,
            'source' => 'atlas_cli',
            'mode' => $mode,
            'workspace' => $workspace,
            'confirmed' => $mode === 'danger' || (bool) $this->option('allow-write') || $workflowMode === 'dev',
            'allow_unsandboxed_provider' => $allowUnsandboxedProvider,
            'requested_provider' => $provider,
            'codex_sandbox' => (string) ($sandboxes[$mode] ?? match ($mode) {
                'write' => 'workspace-write',
                'danger' => 'danger-full-access',
                default => 'read-only',
            }),
            'capabilities' => $this->capabilitiesForPermissionMode($mode),
            'allowed_roots' => $this->allowedRootsForPrompt(),
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

        if (in_array($requested, ['', 'auto', 'default'], true)) {
            $configured = Str::of((string) config('atlas.ai.tool_permissions.default_mode', 'read'))->lower()->trim()->value();

            if (in_array($configured, ['read', 'write', 'danger'], true)) {
                if ($configured !== 'read') {
                    return $configured;
                }
            }
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

    /**
     * @return array<int,string>
     */
    private function allowedRootsForPrompt(): array
    {
        $roots = config('atlas.ai.tool_permissions.allowed_roots', []);
        if (! is_array($roots)) {
            return [];
        }

        return collect($roots)
            ->filter(fn (mixed $root): bool => is_string($root) && trim($root) !== '')
            ->map(fn (string $root): string => realpath($root) ?: $root)
            ->unique()
            ->values()
            ->all();
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));

        return $this->resolveWorkspace($workspace);
    }

    private function resolveWorkspace(string $workspace): string
    {
        $resolved = realpath($workspace);

        if (! $resolved || ! is_dir($resolved)) {
            return $workspace;
        }

        return $this->projectRootFor($resolved) ?: $resolved;
    }

    private function projectRootFor(string $workspace): ?string
    {
        return $this->runProcess(['git', 'rev-parse', '--show-toplevel'], $workspace);
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

    /**
     * @return array{model:string,label:string,tier:string,provider:?string,source:string,alias:string}|null
     */
    private function modelSelection(?string $value, ?string $currentProvider = null): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $raw = trim($value);
        $normalized = $this->normalizeModelAlias($raw);
        if (in_array($normalized, ['default', 'padrao', 'auto', 'atlas'], true)) {
            return null;
        }

        foreach ($this->modelCatalog() as $item) {
            $aliases = array_map(fn (string $alias): string => $this->normalizeModelAlias($alias), $item['aliases']);
            $aliases[] = $this->normalizeModelAlias($item['alias']);
            $aliases[] = $this->normalizeModelAlias($item['model']);
            $aliases[] = $this->normalizeModelAlias($item['label']);
            if (in_array($normalized, array_values(array_unique($aliases)), true)) {
                return [
                    'model' => $item['model'],
                    'label' => $item['label'],
                    'tier' => $item['tier'],
                    'provider' => $item['provider'],
                    'source' => $item['source'],
                    'alias' => $item['alias'],
                ];
            }
        }

        return [
            'model' => $raw,
            'label' => $raw,
            'tier' => 'manual',
            'provider' => $this->inferProviderFromModel($raw) ?: $currentProvider,
            'source' => 'explicit',
            'alias' => $raw,
        ];
    }

    private function handleModelCommand(string $argument, ?string &$provider, ?array &$modelSelection): void
    {
        $argument = trim($argument);
        if ($argument === '') {
            $this->printModelCatalog($provider, $modelSelection);

            if (! $this->input->isInteractive()) {
                $this->line('Use /model sonnet, /model spark, /model default ou /model <model-id>.');

                return;
            }

            $choices = $this->modelChoiceLabels();
            $argument = $this->modelAliasFromChoice((string) $this->choice('Modelo', $choices, $choices[0] ?? null));
        }

        $selection = $this->modelSelection($argument, $provider);
        if ($selection === null) {
            $modelSelection = null;
            $this->line('Modelo ativo: padrao do provider.');

            return;
        }

        $modelSelection = $selection;
        $selectionProvider = is_string($selection['provider'] ?? null) ? $selection['provider'] : null;
        if ($selectionProvider !== null && $provider !== $selectionProvider) {
            $provider = $selectionProvider;
            $this->line('Provider ajustado: '.$this->providerDisplayName($provider));
        }

        $this->line('Modelo ativo: '.$this->modelSelectionLabel($modelSelection));
    }

    private function printModelCatalog(?string $provider, ?array $modelSelection = null): void
    {
        $decorated = $this->output->isDecorated();
        $this->newLine();
        $this->line(AtlasTerminalTheme::bold('Modelos Atlas CLI', $decorated));
        $this->line('Atual: '.$this->modelPanelValue($provider, $modelSelection, $decorated));
        $this->newLine();
        $this->line(sprintf('  %-14s %-12s %-36s %-10s %s', 'alias', 'provider', 'modelo', 'tier', 'label'));
        $this->line('  '.str_repeat('-', 88));

        foreach ($this->modelCatalog() as $item) {
            $active = $modelSelection !== null && ($modelSelection['model'] ?? null) === $item['model'] ? '*' : ' ';
            $this->line(sprintf(
                '%s %-14s %-12s %-36s %-10s %s',
                $active,
                $item['alias'],
                $this->providerDisplayName($item['provider']),
                Str::limit($item['model'], 36, ''),
                $item['tier'],
                $item['label'],
            ));
        }

        $this->newLine();
        $this->line('Use /model <alias>, /model default, ou /model <model-id> para um id explicito.');
        $this->newLine();
    }

    /**
     * @return list<string>
     */
    private function modelChoiceLabels(): array
    {
        $choices = ['default - usar modelo padrao do provider'];
        foreach ($this->modelCatalog() as $item) {
            $choices[] = $item['alias'].' - '.$item['label'].' ['.$this->providerDisplayName($item['provider']).', '.$item['tier'].']';
        }

        return $choices;
    }

    private function modelAliasFromChoice(string $choice): string
    {
        return trim(Str::before($choice, ' - '));
    }

    /**
     * @return list<array{alias:string,provider:string,model:string,label:string,tier:string,source:string,description:string,aliases:list<string>}>
     */
    private function modelCatalog(): array
    {
        $rows = [];

        $this->appendModelCatalogRow(
            $rows,
            'sonnet',
            'claude_cli',
            $this->providerConfiguredModel('claude_cli'),
            'default',
            'Claude diario',
            ['sonnet', 'sonnet-4.6', 'sonnet-4', 'claude-sonnet', 'claude-sonnet-4-6', 'claude'],
        );
        $this->appendModelCatalogRow(
            $rows,
            'spark',
            'codex_cli',
            $this->providerConfiguredModel('codex_cli'),
            'default',
            'Codex diario',
            ['spark', 'codex-spark', 'gpt-5.3-codex-spark', 'gpt-5.3', 'codex'],
        );
        $this->appendModelCatalogRow(
            $rows,
            'gemini-pro',
            'gemini_cli',
            $this->providerConfiguredModel('gemini_cli'),
            'premium',
            'Gemini contexto longo e multimodal',
            ['gemini', 'gemini-pro', 'gemini-3.1-pro-preview', 'gemini-3-1-pro'],
        );
        $this->appendModelCatalogRow(
            $rows,
            'haiku',
            'claude_cli',
            $this->providerNamedModel('claude_cli', 'fallback_model', 'fallback_model_label'),
            'fallback',
            'Claude economico',
            ['haiku', 'claude-haiku', 'fallback-claude'],
        );
        $this->appendModelCatalogRow(
            $rows,
            'mini',
            'codex_cli',
            $this->providerNamedModel('codex_cli', 'fallback_model', 'fallback_model_label'),
            'fallback',
            'Codex economico',
            ['mini', 'codex-mini', 'gpt-5.4-mini', 'fallback-codex'],
        );
        $this->appendModelCatalogRow(
            $rows,
            'opus',
            'claude_cli',
            $this->providerNamedModel('claude_cli', 'premium_model', 'premium_model_label'),
            'premium',
            'Claude premium manual',
            ['opus', 'opus-4.7', 'claude-opus', 'claude-opus-4-7', 'claude-premium'],
        );
        $this->appendModelCatalogRow(
            $rows,
            'codex-premium',
            'codex_cli',
            $this->providerNamedModel('codex_cli', 'premium_model', 'premium_model_label'),
            'premium',
            'Codex premium manual',
            ['codex-premium', 'codex-5.5', 'gpt-5.5', 'gpt-premium', 'premium-codex'],
        );

        return $rows;
    }

    /**
     * @param  list<array{alias:string,provider:string,model:string,label:string,tier:string,source:string,description:string,aliases:list<string>}>  $rows
     * @param  array{model:string,label:string,tier:string}|null  $model
     * @param  list<string>  $aliases
     */
    private function appendModelCatalogRow(array &$rows, string $alias, string $provider, ?array $model, string $source, string $description, array $aliases): void
    {
        if ($model === null || $model['model'] === '') {
            return;
        }

        foreach ($rows as $row) {
            if ($row['provider'] === $provider && $row['model'] === $model['model']) {
                return;
            }
        }

        $rows[] = [
            'alias' => $alias,
            'provider' => $provider,
            'model' => $model['model'],
            'label' => $model['label'],
            'tier' => $model['tier'],
            'source' => $source,
            'description' => $description,
            'aliases' => array_values(array_unique($aliases)),
        ];
    }

    /**
     * @return array{model:string,label:string,tier:string}|null
     */
    private function providerConfiguredModel(string $provider): ?array
    {
        $config = app(AtlasAiRuntimeSettings::class)->providerConfig($provider);
        $model = $this->cleanModelString($config['model'] ?? null) ?: $this->cleanModelString($config['model_identity'] ?? null);
        if ($model === null || str_ends_with($model, '_default')) {
            return null;
        }

        return [
            'model' => $model,
            'label' => $this->cleanModelString($config['model_label'] ?? null) ?: $model,
            'tier' => $this->cleanModelString($config['model_tier'] ?? null) ?: app(AtlasAiRuntimeSettings::class)->defaultTier(),
        ];
    }

    /**
     * @return array{model:string,label:string,tier:string}|null
     */
    private function providerNamedModel(string $provider, string $modelKey, string $labelKey): ?array
    {
        $config = app(AtlasAiRuntimeSettings::class)->providerConfig($provider);
        $model = $this->cleanModelString($config[$modelKey] ?? null);
        if ($model === null || str_ends_with($model, '_default')) {
            return null;
        }

        return [
            'model' => $model,
            'label' => $this->cleanModelString($config[$labelKey] ?? null) ?: $model,
            'tier' => $modelKey === 'premium_model'
                ? 'premium'
                : ($this->cleanModelString($config['model_tier'] ?? null) ?: app(AtlasAiRuntimeSettings::class)->defaultTier()),
        ];
    }

    private function modelOverrideFromSelection(?array $modelSelection): ?string
    {
        $model = $modelSelection['model'] ?? null;
        if (! is_string($model) && ! is_numeric($model)) {
            return null;
        }

        $model = trim((string) $model);

        return $model !== '' ? $model : null;
    }

    private function modelSelectionLabel(array $modelSelection): string
    {
        $model = (string) ($modelSelection['model'] ?? '');
        $label = (string) ($modelSelection['label'] ?? $model);
        $tier = (string) ($modelSelection['tier'] ?? 'manual');
        $provider = $this->providerDisplayName(is_string($modelSelection['provider'] ?? null) ? $modelSelection['provider'] : null);

        return "{$label} ({$model}, {$tier}, {$provider})";
    }

    private function modelSelectionMatchesProvider(array $modelSelection, ?string $provider): bool
    {
        $selectionProvider = is_string($modelSelection['provider'] ?? null) ? $modelSelection['provider'] : null;
        if ($selectionProvider === null) {
            return true;
        }

        return $provider === $selectionProvider;
    }

    private function modelPanelValue(?string $provider, ?array $modelSelection, bool $decorated): string
    {
        if ($modelSelection !== null) {
            $label = (string) ($modelSelection['label'] ?? $modelSelection['model'] ?? 'modelo fixado');
            $model = (string) ($modelSelection['model'] ?? '');
            $tier = (string) ($modelSelection['tier'] ?? 'manual');

            return $label.' '.AtlasTerminalTheme::muted('· '.$model.' · '.$tier.' · fixado', $decorated);
        }

        $provider = $provider ?: $this->defaultProviderKey();
        if ($provider === 'claude_codex') {
            return 'Claude + Codex council '.AtlasTerminalTheme::muted('· modelos por provider', $decorated);
        }

        $configured = $this->providerConfiguredModel($provider);
        if ($configured !== null) {
            return $configured['label'].' '.AtlasTerminalTheme::muted('· '.$configured['model'].' · '.$configured['tier'].' · padrao', $decorated);
        }

        return AtlasTerminalTheme::muted('padrao do provider', $decorated);
    }

    private function providerDisplayName(?string $provider): string
    {
        return match ($provider) {
            'claude_cli' => 'Claude',
            'codex_cli' => 'Codex',
            'gemini_cli' => 'Gemini',
            'claude_codex' => 'Conselho',
            default => 'padrao',
        };
    }

    private function inferProviderFromModel(string $model): ?string
    {
        $normalized = $this->normalizeModelAlias($model);
        if (Str::contains($normalized, ['claude', 'sonnet', 'haiku', 'opus'])) {
            return 'claude_cli';
        }
        if (Str::contains($normalized, ['gpt', 'codex', 'spark'])) {
            return 'codex_cli';
        }
        if (Str::contains($normalized, ['gemini'])) {
            return 'gemini_cli';
        }

        return null;
    }

    private function normalizeModelAlias(string $value): string
    {
        $normalized = strtolower(trim(Str::ascii($value)));
        $normalized = str_replace(['_', ' '], '-', $normalized);
        $normalized = preg_replace('/-+/', '-', $normalized) ?? $normalized;

        return trim($normalized, '-');
    }

    private function cleanModelString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? Str::limit($value, 120, '') : null;
    }

    private function providerKey(?string $provider): ?string
    {
        if ($provider === null || trim($provider) === '') {
            return null;
        }

        return match ($this->normalizeModelAlias($provider)) {
            'padrao', 'default', 'auto', 'atlas' => null,
            'claude', 'claude-cli' => 'claude_cli',
            'codex', 'codex-cli' => 'codex_cli',
            'gemini', 'gemini-cli' => 'gemini_cli',
            'conselho', 'council', 'ambos', 'claude-codex' => 'claude_codex',
            default => throw new \InvalidArgumentException("Provider invalido: {$provider}"),
        };
    }

    private function defaultProviderKey(): string
    {
        try {
            $provider = app(AtlasAiRuntimeSettings::class)->defaultProvider();

            return in_array($provider, ['claude_cli', 'codex_cli', 'gemini_cli'], true) ? $provider : 'claude_cli';
        } catch (\InvalidArgumentException) {
            return 'claude_cli';
        }
    }

    private function workflowMode(string $mode): string
    {
        $mode = Str::of($mode)->lower()->trim()->value();

        return in_array($mode, ['direct', 'plan', 'review', 'dev', 'debug', 'research'], true) ? $mode : 'direct';
    }

    private function modeShortcut(string $line): ?string
    {
        if ($line === '/plan' || $line === '/review' || $line === '/debug' || $line === '/research' || $line === '/direct') {
            return ltrim($line, '/');
        }

        return null;
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
