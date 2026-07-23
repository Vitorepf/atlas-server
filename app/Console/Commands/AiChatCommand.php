<?php

namespace App\Console\Commands;

use App\Console\Commands\AiChat\AiChatModelSection;
use App\Console\Commands\AiChat\AiChatProgrammingSection;
use App\Console\Commands\AiChat\AiChatReplSection;
use App\Console\Commands\AiChat\AiChatUiSection;
use App\Console\Concerns\RendersProviderChoiceMenu;
use App\Models\AiJob;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AiProviderChoiceException;
use App\Services\Ai\AiProviderChoiceResolver;
use App\Services\Ai\ConversationOps\AiSessionStateService;
use App\Services\Ai\AiWorker;
use App\Services\Ai\Policy\AtlasAiRuntimeSettings;
use App\Services\Ai\Cli\AtlasCliDevWorkflowService;
use App\Services\Ai\Cli\AtlasCliPanel;
use App\Services\Ai\Cli\AtlasCliQualityService;
use App\Services\Ai\Cli\AtlasCliSessionService;
use App\Services\Ai\Cli\AtlasCliTelemetry;
use App\Services\Ai\Cli\AtlasImageAttachmentService;
use App\Services\Ai\Cli\AtlasReplHistory;
use App\Services\Ai\Cli\AtlasTerminalTheme;
use App\Services\Ai\Cli\IntentPermissionResolver;
use App\Services\Ai\Cli\IntentResolution;
use App\Services\Ai\Cli\Repl\HistorySearch;
use App\Services\Ai\Cli\Repl\KeyCodes;
use App\Services\Ai\Cli\Repl\KeyEvent;
use App\Services\Ai\Cli\Repl\KeySequenceParser;
use App\Services\Ai\Cli\Repl\ReplComposer;
use App\Services\Ai\Cli\Repl\ReplMessages;
use App\Services\Ai\Cli\Repl\ReplRenderer;
use App\Services\Ai\Cli\Repl\StatusBarFormatter;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Ai\Kernel\Decision\ComputeEffortPolicy;
use App\Services\Ai\Kernel\Decision\ModelSelectionContractFactory;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineAuditService;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineDevPlanBuilder;
use App\Services\Ai\Kernel\Pipeline\KernelPipelinePlanGuard;
use App\Services\Ai\Kernel\Pipeline\KernelPipelinePlanViolation;
use App\Services\Ai\Programming\AtlasProgrammingOrchestrator;
use App\Services\Ai\Programming\ProgrammingExecutionRequest;
use App\Services\Ai\Programming\ProgrammingIterationPolicy;
use App\Services\Ai\Programming\ProgrammingSurfaceContractFactory;
use App\Services\Ai\Skills\SkillBundleStore;
use App\Services\Ai\Skills\SkillDiscoveryService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Support\JsonFileStore;
use App\Support\AtlasPhpBinary;
use App\Support\AtlasSecurity;
use App\Support\TerminalMarkdownRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Terminal;
use Symfony\Component\Process\Process;

class AiChatCommand extends Command
{
    use RendersProviderChoiceMenu;

    public AiChatReplSection $repl;

    public AiChatUiSection $ui;

    public AiChatModelSection $models;

    public AiChatProgrammingSection $programming;

    public ?string $replStatusBarProvider = null;

    public ?string $replStatusBarModel = null;

    protected $signature = 'atlas:ai:chat
        {input? : One-shot input. Omit it to open the interactive Atlas CLI loop}
        {--ai= : Session AI/provider alias: hermes, minimax, claude, codex, gemini or conselho}
        {--provider= : hermes, minimax, claude, codex, gemini, conselho, hermes_cli, minimax_m27_cli, claude_cli, codex_cli, gemini_cli or claude_codex}
        {--model= : Model alias/id for this run, for example sonnet, opus, spark, codex-premium, claude-opus-4-7 or gpt-5.5}
        {--effort= : Atlas compute effort: fast, balanced, deep or max}
        {--claude-only : Fair Claude benchmark mode: force claude_cli + Claude Opus and disable fallback/decide/council}
        {--single-provider : Fair Claude benchmark mode: forbid provider switching}
        {--no-decide : Fair Claude benchmark mode: disable Atlas Decide for this run}
        {--fallback-disabled : Fair Claude benchmark mode: fail instead of falling back to another provider/model}
        {--agent= : Force a specific Atlas agent/skill slug}
        {--thread= : Continue a specific Atlas thread}
        {--new-thread : Start a fresh Atlas thread (default unless --thread or --resume-latest is used)}
        {--resume-latest : Continue the latest Atlas CLI thread for this workspace}
        {--workspace= : Workspace path. Defaults to the current directory}
        {--mode=direct : direct, plan, review, dev, debug or research}
        {--dev : Shortcut for --mode=dev with Atlas Decide provider selection}
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
        {--no-open-brain : Disable automatic Open Brain context injection for this prompt}
        {--require-open-brain : Fail if Open Brain context cannot be injected}
        {--open-brain-refresh : Request a fresh Open Brain context instead of reusing a prior hash}
        {--open-brain-budget= : Override Open Brain context budget in characters}
        {--skill=* : Activate one or more agentskills bundle names}
        {--list-threads : List recent Atlas CLI threads and exit}
        {--no-run : Enqueue only; do not run the local worker inline}
        {--timeout=900 : Seconds to wait when running inline}
        {--compact : Render in compact mode (hide code blocks, keep prose)}
        {--no-intent : Disable intent-based permission elevation; respect --permission verbatim}
        {--json : Print machine-readable JSON}';

    protected $description = 'Use Atlas directly from the Mac while preserving Atlas threads, sessions, memory and provider handoffs.';

    public string $streamedAssistantContent = '';

    public string $markdownStreamBuffer = '';

    public bool $streamOutputStarted = false;

    public string $renderMode = 'full';

    public bool $streamInCodeBlock = false;

    public string $streamCodeLabel = '';

    public int $streamCodeLineCount = 0;

    public bool $intentEnabled = true;

    /** @var array<string,bool> */
    public array $intentSessionAcks = [];

    public function __construct()
    {
        parent::__construct();
        $this->repl = new AiChatReplSection($this);
        $this->ui = new AiChatUiSection($this);
        $this->models = new AiChatModelSection($this);
        $this->programming = new AiChatProgrammingSection($this);
    }

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
        AtlasAiRuntimeSettings $settings,
        FairClaudePolicy $fairClaude,
    ): int {
        $workspace = $this->ui->workspace();
        $declaredDevPlan = $this->programming->devExecutionPlanOption();
        if ($declaredDevPlan !== null && is_array(data_get($declaredDevPlan, 'kernel_pipeline'))) {
            try {
                $pipelinePlan = (array) data_get($declaredDevPlan, 'kernel_pipeline');
                $contract = is_array(data_get($declaredDevPlan, 'kernel_pipeline_contract'))
                    ? (array) data_get($declaredDevPlan, 'kernel_pipeline_contract')
                    : null;
                app(KernelPipelinePlanGuard::class)->assertValidPlanAndContract($pipelinePlan, $contract);
            } catch (KernelPipelinePlanViolation $violation) {
                app(KernelPipelineAuditService::class)->recordRejectedPlan(
                    (array) data_get($declaredDevPlan, 'kernel_pipeline'),
                    $violation->errors,
                    $this->kernelPipelineLedgerContext(
                        (array) data_get($declaredDevPlan, 'kernel_pipeline'),
                        $workspace,
                        is_array(data_get($declaredDevPlan, 'kernel_pipeline_contract')) ? (array) data_get($declaredDevPlan, 'kernel_pipeline_contract') : null,
                    ),
                );

                return $this->programming->kernelPipelineViolation($violation);
            }
        }
        $fairFlags = $fairClaude->normalizeFlags($this->models->fairClaudeFlags($declaredDevPlan));
        $fairMode = (bool) ($fairFlags['fair_mode'] ?? false);
        $explicitProvider = $this->models->providerKey($this->option('conselho') ? 'conselho' : ($this->option('provider') ?: $this->option('ai') ?: null));
        $provider = $explicitProvider;
        if ($fairMode && $provider === null) {
            $provider = FairClaudePolicy::PROVIDER_LOCK;
        }
        $modelOption = $this->option('model') ?: null;
        if ($fairMode && (! is_string($modelOption) || trim($modelOption) === '')) {
            $modelOption = FairClaudePolicy::MODEL_LOCK;
        }
        $modelSelection = $this->models->modelSelection(is_string($modelOption) ? $modelOption : null, $provider);
        $computeEffort = $this->models->computeEffortSelection($this->option('effort'));
        if ($modelSelection !== null && ! $provider && is_string($modelSelection['provider'] ?? null)) {
            $provider = $modelSelection['provider'];
        }
        if ($fairMode) {
            $fairValidation = $fairClaude->validate($provider, $modelSelection);
            if (! (bool) ($fairValidation['ok'] ?? false)) {
                return $this->models->fairModeViolation($fairValidation);
            }
        }
        $manualProviderRequested = $explicitProvider !== null || $modelSelection !== null;
        $mode = $this->programming->workflowMode($this->option('dev') ? 'dev' : (string) $this->option('mode'));
        if ($modelSelection !== null && ! $this->models->modelSelectionMatchesProvider($modelSelection, $provider)) {
            $this->error('Modelo '.$this->models->modelSelectionLabel($modelSelection).' nao combina com provider '.($provider ? $this->models->providerDisplayName($provider) : 'padrao').'. Use --provider correto ou remova --model.');

            return self::FAILURE;
        }
        if ($manualProviderRequested && ! $this->models->manualProviderAllowed($provider, $settings)) {
            return $this->models->manualProviderBlocked((string) $provider);
        }
        $permissionMode = $this->ui->permissionMode((string) $this->option('permission'), $mode);
        $stream = (bool) $this->option('stream') && ! (bool) $this->option('json');
        $this->renderMode = (bool) $this->option('compact') ? 'compact' : 'full';
        $this->intentEnabled = ! (bool) $this->option('no-intent');
        $threadId = $this->option('thread') ?: ((bool) $this->option('resume-latest') && ! (bool) $this->option('new-thread') ? $this->ui->latestThreadId($workspace) : null);
        $busyMode = $this->ui->busyInputMode();
        $queuedMessages = [];
        $input = $this->argument('input');
        $activatedSkills = $this->ui->skillOptions();
        $pendingImages = [];
        $lastImages = [];

        try {
            $pendingImages = $this->repl->initialImageAttachments($imageAttachments, $workspace);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->repl->maybeCleanupStaleAttachments($imageAttachments);

        if ((bool) $this->option('list-threads')) {
            $this->ui->printThreads($workspace);

            return self::SUCCESS;
        }

        $skillTrust = $this->ui->prepareSkillBundles($skillDiscovery, $skillBundles, $workspace);

        if (is_string($input) && trim($input) !== '') {
            [$input, $pendingImages] = $this->repl->attachInlineImagePaths($imageAttachments, $workspace, trim($input), $pendingImages);
            $pendingImages = $this->repl->maybeAutoAttachClipboardImage($imageAttachments, $workspace, trim($input), $pendingImages);
            if ($pendingImages !== [] && ! $this->repl->providerSupportsCliImages($provider)) {
                return $this->repl->imageProviderBlocked((string) $provider);
            }
            $effectivePermission = $this->ui->resolveEffectivePermission($intent, trim($input), $permissionMode);
            $trace = $this->send($gateway, $worker, trim($input), $workspace, $provider, $mode, $effectivePermission, $stream, $threadId, $threadId === null || (bool) $this->option('new-thread'), $activatedSkills, $pendingImages, $modelSelection, $computeEffort, $fairMode ? $fairClaude->metadata() : null, $fairFlags);
            $this->ui->maybeRunDevQualityGate($quality, $workspace, $mode, $pendingImages);

            return $trace->status === 'succeeded' || (bool) $this->option('no-run')
                ? self::SUCCESS
                : self::FAILURE;
        }

        if (! $this->option('json')) {
            $this->ui->printWelcome($workspace, $provider, $mode, $permissionMode, $stream, $busyMode, $threadId, $activatedSkills, $skillTrust, $modelSelection);
            $this->replStatusBarProvider = $this->repl->shortenProviderForStatus($provider);
            $this->replStatusBarModel = $this->repl->shortenModelForStatus($modelSelection);
            $history->load($workspace);
        }

        while (true) {
            $drainedTrace = $this->drainQueuedMessages($queuedMessages, $gateway, $worker, $quality, $workspace, $provider, $mode, $permissionMode, $stream, $threadId, $activatedSkills, $intent, $modelSelection, $computeEffort, $fairMode ? $fairClaude->metadata() : null, $fairFlags);
            if ($drainedTrace) {
                $threadId = $drainedTrace->thread_id ?: $threadId;
            }

            try {
                [$line, $pendingImages] = $this->repl->readInteractiveLine($this->repl->interactivePromptLabel($threadId, $pendingImages), $imageAttachments, $workspace, $pendingImages);
            } catch (ConsoleRuntimeException) {
                $history->save();

                return self::SUCCESS;
            }
            if ($line === null) {
                $line = '';
            }
            if (! is_string($line)) {
                continue;
            }

            $line = trim($line);
            if ($line === '') {
                [$line, $pendingImages] = $this->repl->inputFromBlankClipboardPaste($imageAttachments, $workspace, $pendingImages);
                if ($line === null) {
                    continue;
                }
            }

            if (in_array($line, ['/exit', '/quit', '/sair'], true)) {
                $history->save();

                return self::SUCCESS;
            }

            if ($line === '/help') {
                $this->ui->printHelp();

                continue;
            }

            if ($line === '/status') {
                $this->ui->printRuntimeStatus($workspace, $provider, $mode, $permissionMode, $stream, $busyMode, $threadId, $activatedSkills, $skillTrust, $modelSelection);

                continue;
            }

            if ($line === '/state') {
                $this->ui->printCliState($cliSessions->snapshot($workspace, $threadId));

                continue;
            }

            if ($line === '/quality') {
                $this->ui->printQuality($quality->compact($quality->evaluate($workspace)));

                continue;
            }

            if ($line === '/fix' || str_starts_with($line, '/fix ')) {
                $line = $this->programming->fixPromptInput(trim(Str::after($line, '/fix')));
                $mode = 'dev';
                if (! $provider) {
                    $provider = $this->models->defaultProviderKey();
                }
                $permissionMode = $this->ui->permissionMode($permissionMode, $mode);
            }

            if ($line === '/doctor') {
                $this->ui->runLocalAtlasCommand(['atlas:cli:doctor', '--workspace='.$workspace]);

                continue;
            }

            if ($line === '/providers') {
                $this->ui->runLocalAtlasCommand(['atlas:cli:providers', '--mode='.$mode]);

                continue;
            }

            if ($line === '/models') {
                $this->models->printModelCatalog($provider, $modelSelection);

                continue;
            }

            if ($line === '/model' || str_starts_with($line, '/model ')) {
                $this->models->handleModelCommand(trim(Str::after($line, '/model')), $provider, $modelSelection);

                continue;
            }

            if ($line === '/effort' || str_starts_with($line, '/effort ')) {
                $computeEffort = $this->models->handleEffortCommand(trim(Str::after($line, '/effort')), $computeEffort);

                continue;
            }

            if ($line === '/skills' || str_starts_with($line, '/skills ')) {
                $this->ui->runLocalAtlasCommand(array_merge(
                    ['atlas:cli:skills', '--workspace='.$workspace],
                    $this->ui->simpleArguments(trim(Str::after($line, '/skills'))),
                ));

                continue;
            }

            if (in_array($line, ['/checkpoint', '/checkpoints'], true)) {
                $this->ui->runLocalAtlasCommand(['atlas:cli:checkpoint', '--workspace='.$workspace]);

                continue;
            }

            if ($line === '/threads') {
                $this->ui->printThreads($workspace);

                continue;
            }

            if ($line === '/new') {
                $threadId = null;
                $pendingImages = [];
                $this->line('Nova thread será criada na próxima mensagem.');

                continue;
            }

            if (str_starts_with($line, '/thread ')) {
                $threadId = trim(Str::after($line, '/thread '));
                $pendingImages = [];
                $this->line("Thread ativa: {$threadId}");

                continue;
            }

            if (str_starts_with($line, '/workspace ')) {
                $history->save();
                $workspace = $this->ui->resolveWorkspace(trim(Str::after($line, '/workspace ')));
                $threadId = null;
                $queuedMessages = [];
                $skillTrust = $this->ui->prepareSkillBundles($skillDiscovery, $skillBundles, $workspace);
                $pendingImages = [];
                $this->line("Workspace ativo: {$workspace}");
                $this->ui->printRuntimeStatus($workspace, $provider, $mode, $permissionMode, $stream, $busyMode, $threadId, $activatedSkills, $skillTrust, $modelSelection);
                $history->load($workspace);

                continue;
            }

            if (str_starts_with($line, '/provider ')) {
                $nextProvider = $this->models->providerKey(trim(Str::after($line, '/provider ')));
                if ($this->repl->imageProviderSwitchBlocked($nextProvider, $pendingImages, $queuedMessages)) {
                    $this->error($this->repl->imageProviderBlockedMessage((string) $nextProvider));
                    $this->line($this->ui->dim('Mantenha provider visual, use /provider codex|gemini, ou limpe com /clear-images.'));

                    continue;
                }
                $provider = $nextProvider;
                if ($modelSelection !== null && ! $this->models->modelSelectionMatchesProvider($modelSelection, $provider)) {
                    $this->warn('Modelo fixado nao combina com esse provider; override de modelo limpo.');
                    $modelSelection = null;
                }
                $this->line('Provider ativo: '.($provider ?: 'padrao'));

                continue;
            }

            if (in_array($line, ['/paste-image', '/clipboard-image', '/paste', '/p', '/img'], true)) {
                try {
                    $pendingImages = $this->repl->mergeImageAttachments($pendingImages, [$imageAttachments->fromClipboard($workspace)], $imageAttachments);
                    $this->line('Imagem do clipboard anexada para a proxima mensagem.');
                    $this->repl->printPendingImages($pendingImages);
                } catch (\Throwable $exception) {
                    $this->error($exception->getMessage());
                }

                continue;
            }

            if (str_starts_with($line, '/image ')) {
                try {
                    $paths = $this->repl->imageCommandPaths(trim(Str::after($line, '/image ')));
                    $pendingImages = $this->repl->mergeImageAttachments($pendingImages, $imageAttachments->fromPaths($paths, $workspace), $imageAttachments);
                    $this->repl->printPendingImages($pendingImages);
                } catch (\Throwable $exception) {
                    $this->error($exception->getMessage());
                }

                continue;
            }

            if ($line === '/images') {
                $this->repl->printPendingImages($pendingImages !== [] ? $pendingImages : $lastImages, detailed: true);

                continue;
            }

            if ($line === '/open-image' || str_starts_with($line, '/open-image ')) {
                $this->repl->openImageAttachment($pendingImages !== [] ? $pendingImages : $lastImages, trim(Str::after($line, '/open-image')));

                continue;
            }

            if ($line === '/clear-images') {
                $pendingImages = [];
                $this->line('Imagens pendentes limpas.');

                continue;
            }

            if (str_starts_with($line, '/mode ')) {
                $mode = $this->programming->workflowMode(trim(Str::after($line, '/mode ')));
                if ($mode === 'dev' && ! $provider) {
                    $provider = $this->models->defaultProviderKey();
                }
                $permissionMode = $this->ui->permissionMode($permissionMode, $mode);
                $this->line("Modo ativo: {$mode}");

                continue;
            }

            $modeShortcut = $this->programming->modeShortcut($line);
            if ($modeShortcut !== null) {
                $mode = $this->programming->workflowMode($modeShortcut);
                if ($mode === 'dev' && ! $provider) {
                    $provider = $this->models->defaultProviderKey();
                }
                $permissionMode = $this->ui->permissionMode($permissionMode, $mode);
                $this->line("Modo ativo: {$mode}");

                continue;
            }

            if (str_starts_with($line, '/permission ')) {
                $permissionMode = $this->ui->permissionMode(trim(Str::after($line, '/permission ')), $mode);
                $this->line("Permissao ativa: {$permissionMode}");

                continue;
            }

            if (str_starts_with($line, '/objective ')) {
                $this->ui->printCliStateResult(fn (): array => $cliSessions->update($workspace, $threadId, [
                    'objective' => trim(Str::after($line, '/objective ')),
                ]));

                continue;
            }

            if (str_starts_with($line, '/phase ')) {
                $this->ui->printCliStateResult(fn (): array => $cliSessions->update($workspace, $threadId, [
                    'phase' => trim(Str::after($line, '/phase ')),
                ]));

                continue;
            }

            if (str_starts_with($line, '/topic ')) {
                $this->ui->printCliStateResult(fn (): array => $cliSessions->update($workspace, $threadId, [
                    'topic' => trim(Str::after($line, '/topic ')),
                ]));

                continue;
            }

            if (str_starts_with($line, '/note ')) {
                $this->ui->printCliStateResult(fn (): array => $cliSessions->update($workspace, $threadId, [
                    'notes' => [trim(Str::after($line, '/note '))],
                ]));

                continue;
            }

            if (str_starts_with($line, '/next ')) {
                $this->ui->printCliStateResult(fn (): array => $cliSessions->update($workspace, $threadId, [
                    'next_steps' => [trim(Str::after($line, '/next '))],
                ]));

                continue;
            }

            if ($line === '/compact') {
                $this->ui->printCliStateResult(fn (): array => $cliSessions->compact($workspace, $threadId));

                continue;
            }

            if (str_starts_with($line, '/handoff ')) {
                $this->ui->printCliStateResult(fn (): array => $cliSessions->handoff(
                    $workspace,
                    $threadId,
                    $this->models->providerKey(trim(Str::after($line, '/handoff '))) ?: trim(Str::after($line, '/handoff ')),
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
                $busyMode = $this->repl->handleBusyCommand($line, $busyMode);

                continue;
            }

            if (str_starts_with($line, '/steer')) {
                $this->repl->handleSteerCommand($states, $workspace, $threadId, trim(Str::after($line, '/steer')));

                continue;
            }

            $messageSkills = $activatedSkills;
            $skillSlash = $this->ui->skillSlash($line, $skillBundles);
            if ($skillSlash) {
                $messageSkills[] = $skillSlash['skill'];
                $messageSkills = array_values(array_unique($messageSkills));
                $line = $skillSlash['input'];
            }

            [$line, $pendingImages] = $this->repl->attachInlineImagePaths($imageAttachments, $workspace, $line, $pendingImages);
            $pendingImages = $this->repl->maybeAutoAttachClipboardImage($imageAttachments, $workspace, $line, $pendingImages);
            if ($pendingImages !== [] && ! $this->repl->providerSupportsCliImages($provider)) {
                $this->error($this->repl->imageProviderBlockedMessage((string) $provider));

                continue;
            }

            $activeTrace = $this->repl->activeTrace($threadId, $workspace);
            if ($activeTrace) {
                $threadId = $activeTrace->thread_id ?: $threadId;
                $this->ui->showBusyHintOnce();

                if ($busyMode === 'queue') {
                    $queuedMessages[] = [
                        'input' => $line,
                        'skills' => $messageSkills,
                        'images' => $pendingImages,
                        'model' => $modelSelection,
                        'effort' => $computeEffort,
                    ];
                    $pendingImages = [];
                    $this->line('(queued - will send next turn)');

                    continue;
                }

                if ($busyMode === 'steer') {
                    if ($pendingImages !== []) {
                        $queuedMessages[] = [
                            'input' => $line,
                            'skills' => $messageSkills,
                            'images' => $pendingImages,
                            'model' => $modelSelection,
                            'effort' => $computeEffort,
                        ];
                        $pendingImages = [];
                        $this->line('(queued - imagem requer envio visual na proxima chamada)');

                        continue;
                    }

                    $this->repl->setPendingSteer($states, $activeTrace, $line);
                    $this->line('(steering - will reach agent before the next provider call)');

                    continue;
                }

                $this->repl->cancelTrace($activeTrace);
                $this->warn('(interrupted - previous trace cancelled; sending new message)');
            }

            $effectivePermission = $this->ui->resolveEffectivePermission($intent, $line, $permissionMode);
            $sentImages = $pendingImages;
            $trace = $this->send($gateway, $worker, $line, $workspace, $provider, $mode, $effectivePermission, $stream, $threadId, $threadId === null, $messageSkills, $sentImages, $modelSelection, $computeEffort, $fairMode ? $fairClaude->metadata() : null, $fairFlags);
            if ($pendingImages !== []) {
                $lastImages = $pendingImages;
            }
            $pendingImages = [];
            $threadId = $trace->thread_id ?: $threadId;
            $this->ui->maybeRunDevQualityGate($quality, $workspace, $mode, $sentImages);
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
        ?string $computeEffort = null,
        ?array $fairModeMetadata = null,
        array $fairFlags = [],
    ): AiTrace {
        $this->streamedAssistantContent = '';
        $this->markdownStreamBuffer = '';
        $this->streamOutputStarted = false;
        $this->streamInCodeBlock = false;
        $this->streamCodeLabel = '';
        $this->streamCodeLineCount = 0;

        $activatedSkills = $this->ui->normalizeSkillNames($activatedSkills);
        $imageAttachments = $this->repl->normalizeImageAttachments($imageAttachments);
        $inputForPrompt = $imageAttachments === [] ? $input : $this->repl->inputWithImageSummary($input, $imageAttachments);
        $agentSlug = $this->programming->agentSlug($mode);
        $modelOverride = $this->models->modelOverrideFromSelection($modelSelection);
        $aiPolicyOverride = $this->models->aiPolicyOverride($provider, $modelSelection, $modelOverride, fairMode: $fairModeMetadata !== null);
        if ($fairModeMetadata !== null) {
            $inputForPrompt = $this->models->fairClaudePromptContract($inputForPrompt);
        }
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
                'compute_effort' => $computeEffort,
            ],
        );
        $devPlan = $this->programming->activeDevExecutionPlan(
            workspace: $workspace,
            mode: $mode,
            input: $input,
            provider: $provider,
            model: $modelOverride,
            aiPolicyOverride: $aiPolicyOverride,
        );
        $payload = [
            'app_surface' => 'atlas_cli',
            'atlas_workflow_mode' => $mode,
            'workspace' => $workspace,
            'decision_mode' => $provider ? 'manual_override' : 'atlas_decide',
            'model_selection_contract' => $this->modelSelectionContract(
                provider: $provider,
                modelSelection: $modelSelection,
                modelOverride: $modelOverride,
                fairMode: $fairModeMetadata !== null,
                context: [
                    'domain' => $mode === 'dev' ? 'programming' : null,
                    'flow' => data_get($devPlan, 'kernel_pipeline.input.safe_hints.flow'),
                    'task' => $input,
                    'compute_effort' => $computeEffort,
                ],
            ),
            'compute_effort' => $computeEffort,
            'compute_effort_contract' => app(ComputeEffortPolicy::class)->contract(
                requested: $computeEffort,
                provider: $provider,
                context: [
                    'domain' => $mode === 'dev' ? 'programming' : null,
                    'flow' => data_get($devPlan, 'kernel_pipeline.input.safe_hints.flow'),
                    'task' => $input,
                ],
            ),
            'operator_requested_provider' => $provider ?: 'auto',
            'requested_provider' => $provider,
            'requested_model' => $modelOverride,
            'requested_model_alias' => $modelSelection['alias'] ?? null,
            'requested_model_label' => $modelSelection['label'] ?? null,
            'requested_model_tier' => $modelSelection['tier'] ?? null,
            'requested_model_source' => $modelSelection['source'] ?? null,
            'requested_agent' => $agentSlug,
            'workspace_context' => $this->ui->workspaceContext($workspace),
            'tool_permissions' => $this->ui->toolPermissions($workspace, $mode, $provider, $permissionMode),
            'open_brain' => $this->programming->openBrainPayload($mode, $devPlan),
        ];
        if ($aiPolicyOverride !== []) {
            $payload['ai_policy_override'] = $aiPolicyOverride;
        }
        if ($activatedSkills !== []) {
            $payload['activated_skills'] = $activatedSkills;
        }
        if ($imageAttachments !== []) {
            $payload['attachments'] = [
                'images' => $imageAttachments,
            ];
            $payload['visual_input'] = [
                'image_count' => count($imageAttachments),
                'sources' => collect($imageAttachments)
                    ->map(fn (array $attachment): string => (string) ($attachment['source'] ?? 'file'))
                    ->unique()
                    ->values()
                    ->all(),
            ];
        }

        if ($devPlan !== null) {
            $payload['dev_execution_plan'] = $devPlan;
            if (is_array(data_get($devPlan, 'kernel_pipeline'))) {
                $payload['kernel_pipeline'] = data_get($devPlan, 'kernel_pipeline');
            }
        }
        $programmingMessagePlan = $this->programmingMessagePlan($workspace, $mode, $input, $provider, $modelOverride, $devPlan, $aiPolicyOverride);
        if ($programmingMessagePlan !== null) {
            $payload['programming_profile'] = (string) ($programmingMessagePlan['programming_profile'] ?? $this->programmingProfileFromDevPlan($devPlan));
            $payload['programming_session_plan'] = $devPlan;
            $payload['programming_message_plan'] = $programmingMessagePlan;
            $payload['programming_intent'] = data_get($programmingMessagePlan, 'operator_intent');
            $payload['programming_dispatch'] = $this->programming->programmingDispatchContract($programmingMessagePlan);
            $payload['programming_chat_contract'] = app(ProgrammingSurfaceContractFactory::class)->chatDev($devPlan, $programmingMessagePlan, $payload['programming_dispatch']);
            $payload['programming_repair'] = app(AtlasProgrammingOrchestrator::class)->repairExecutionContract($programmingMessagePlan);
            $payload['programming_profile_context'] = data_get($programmingMessagePlan, 'policy_profile.profile_context');
            $payload['programming_execution_policy'] = data_get($programmingMessagePlan, 'policy_profile.execution_policy');
        }

        if ($fairModeMetadata !== null) {
            $payload['fair_mode'] = $fairModeMetadata;
            $payload['decision_mode'] = 'manual_override';
            $payload['operator_requested_provider'] = FairClaudePolicy::PROVIDER_LOCK;
            $payload['requested_provider'] = FairClaudePolicy::PROVIDER_LOCK;
            $payload['fair_mode_flags'] = $fairFlags;
        }

        if ($provider === 'claude_codex') {
            $payload['execution_policy'] = 'dual_review';
            $payload['council_providers'] = ['claude_cli', 'codex_cli'];
        }

        if ($this->programming->shouldDispatchProgrammingExecutor($programmingMessagePlan)) {
            $trace = $this->programming->dispatchProgrammingExecutor(
                input: $input,
                workspace: $workspace,
                provider: $provider,
                model: $modelOverride,
                mode: $mode,
                permissionMode: $permissionMode,
                threadId: $threadId,
                agentSlug: $agentSlug,
                payload: $payload,
                programmingMessagePlan: (array) $programmingMessagePlan,
                startedAt: $interactionStartedAt,
            );
            $telemetry->interactionCompleted($correlationId, $trace, $this->elapsedMs($interactionStartedAt), [
                'mode' => $mode,
                'permission_mode' => $permissionMode,
                'stream' => false,
                'executor' => 'engineering_harness',
            ]);
            $this->printTrace($trace);

            return $trace;
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

    public function elapsedMs(float $startedAt): int
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
        ?string $computeEffort = null,
        ?array $fairModeMetadata = null,
        array $fairFlags = [],
    ): ?AiTrace {
        if ($queuedMessages === [] || $this->repl->activeTrace($threadId, $workspace)) {
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
        $batchComputeEffort = collect($items)
            ->map(fn (mixed $item): mixed => is_array($item) ? ($item['effort'] ?? null) : null)
            ->filter(fn (mixed $item): bool => is_string($item) && $item !== '')
            ->last() ?: $computeEffort;
        $messageCount = count($items);
        $this->line("(queued batch - sending {$messageCount} message(s))");

        $effectivePermission = $intent
            ? $this->ui->resolveEffectivePermission($intent, $batch, $permissionMode)
            : $permissionMode;
        $trace = $this->send($gateway, $worker, $batch, $workspace, $provider, $mode, $effectivePermission, $stream, $threadId, $threadId === null, $batchSkills, $batchImages, $batchModelSelection, $batchComputeEffort, $fairModeMetadata, $fairFlags);
        $this->ui->maybeRunDevQualityGate($quality, $workspace, $mode, $batchImages);

        return $trace;
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
                $this->ui->printStreamEvent($event);
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
                'model_selection_contract' => data_get($trace->metadata, 'model_selection_contract')
                    ?? data_get($trace->job?->payload, 'model_selection_contract'),
                'compute_effort_contract' => data_get($trace->metadata, 'compute_effort_contract')
                    ?? data_get($trace->job?->payload, 'compute_effort_contract')
                    ?? data_get($trace->job?->payload, 'model_selection_contract.compute_effort'),
                'agent' => $trace->agent_slug,
                'skills_activated' => (array) data_get($trace->metadata, 'skills_activated', []),
                'open_brain_injection' => data_get($trace->metadata, 'open_brain_injection'),
                'programming_profile' => data_get($trace->metadata, 'programming_profile'),
                'programming_dispatch' => data_get($trace->metadata, 'programming_dispatch'),
                'programming_message_plan' => data_get($trace->metadata, 'programming_message_plan'),
                'programming_repair' => data_get($trace->metadata, 'programming_repair'),
                'programming_completion' => data_get($trace->metadata, 'programming_completion'),
                'programming_result' => data_get($trace->metadata, 'programming_result'),
                'response_text' => $trace->response_text,
                'quality' => $quality ? [
                    'score' => $quality->score,
                    'status' => $quality->status,
                    'flags' => collect($quality->flags)->pluck('code')->values()->all(),
                ] : null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->ui->flushMarkdownStream();

        if ($trace->response_text && $this->streamedAssistantContent === '') {
            $this->line('');
            $this->line($this->ui->renderMarkdown($trace->response_text));

            if ($this->output->isVerbose()) {
                $this->ui->printTraceDiagnostic($trace, $quality);
            }

            return;
        }

        if ($trace->job?->error_message) {
            $this->line('');
            $summary = $this->ui->summarizeErrorContent((string) $trace->job->error_message);
            $this->line('<fg=red;options=bold>✗ '.$summary.'</>');
            if ($this->output->isVerbose()) {
                $this->line('<fg=gray>'.OutputFormatter::escape((string) $trace->job->error_message).'</>');
            }

            return;
        }

        $this->line('');
        if (in_array($trace->status, ['queued', 'processing'], true)) {
            $this->line('<fg=yellow>Atlas ainda esta processando.</> <fg=gray>thread '.$this->shortId((string) $trace->thread_id).' · trace '.$this->shortId((string) $trace->id).'</>');
            $this->line('<fg=gray>Use /status para detalhes ou aguarde a proxima rodada.</>');

            return;
        }

        $this->ui->printTraceDiagnostic($trace, $quality);
    }

    /**
     * @param  array<string,mixed>|null  $modelSelection
     * @return array<string,mixed>
     */
    private function modelSelectionContract(?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode, array $context = []): array
    {
        return app(ModelSelectionContractFactory::class)->forAiChat($provider, $modelSelection, $modelOverride, $fairMode, $context);
    }

    /**
     * @param  array<string,mixed>  $devPlan
     * @return array<string,mixed>
     */
    public function withKernelPipelinePlan(array $devPlan, string $workspace, string $input, string $inputMode): array
    {
        if (is_array(data_get($devPlan, 'kernel_pipeline'))) {
            $pipelinePlan = (array) data_get($devPlan, 'kernel_pipeline');
            $contract = is_array(data_get($devPlan, 'kernel_pipeline_contract'))
                ? (array) data_get($devPlan, 'kernel_pipeline_contract')
                : null;
            app(KernelPipelinePlanGuard::class)->assertValidPlanAndContract($pipelinePlan, $contract);
            app(KernelPipelineAuditService::class)->recordAcceptedPlan(
                $pipelinePlan,
                $this->kernelPipelineLedgerContext($pipelinePlan, $workspace, $contract),
            );

            return $devPlan;
        }

        $profile = $this->programmingProfileFromDevPlan($devPlan);
        $intent = $this->programming->programmingIntent($input, $profile, $devPlan);
        $flow = $profile === 'forge'
            ? 'programming.forge'
            : (((string) ($intent['kind'] ?? '') === 'repair') ? 'programming.repair' : 'programming.dev');
        $runtime = data_get($devPlan, 'programming_session_plan.executor_decision.executor')
            ?: data_get($devPlan, 'executor_decision.executor')
            ?: ($profile === 'forge' ? 'engineering_harness' : 'dev_repair_executor');
        $devPlan = app(KernelPipelineDevPlanBuilder::class)->attachProgrammingPlan(
            devPlan: $devPlan,
            text: $input,
            workspace: $workspace,
            surfaceId: 'atlas_ai_chat',
            command: 'atlas:ai:chat',
            inputMode: $inputMode,
            programmingProfile: $profile,
            flow: $flow,
            taskKind: (string) ($intent['kind'] ?? 'implementation'),
            runtime: (string) $runtime,
        );
        app(KernelPipelineAuditService::class)->recordAcceptedPlan(
            (array) data_get($devPlan, 'kernel_pipeline'),
            $this->kernelPipelineLedgerContext(
                (array) data_get($devPlan, 'kernel_pipeline'),
                $workspace,
                is_array(data_get($devPlan, 'kernel_pipeline_contract')) ? (array) data_get($devPlan, 'kernel_pipeline_contract') : null,
            ),
        );

        return $devPlan;
    }

    /**
     * @param  array<string,mixed>  $pipelinePlan
     * @return array<string,mixed>
     */
    private function kernelPipelineLedgerContext(array $pipelinePlan, string $workspace, ?array $surfaceContract = null): array
    {
        $pipelineId = is_string($pipelinePlan['pipeline_id'] ?? null) && trim((string) $pipelinePlan['pipeline_id']) !== ''
            ? (string) $pipelinePlan['pipeline_id']
            : 'kernel_pipeline_unknown';

        return [
            'tenant_id' => data_get($pipelinePlan, 'input.tenant_id', 'default'),
            'operator_id' => data_get($pipelinePlan, 'input.operator_id', 'system'),
            'envelope_id' => 'kernel_pipeline:'.$pipelineId,
            'correlation_id' => $pipelineId,
            'emitter_stage' => 'atlas.ai_chat.kernel_pipeline_guard',
            'emitter_version' => 'atlas.ai_chat.kernel_pipeline_guard.v1',
            'workspace' => $workspace,
            'surface_contract' => $surfaceContract,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function programmingMessagePlan(
        string $workspace,
        string $mode,
        string $input,
        ?string $provider,
        ?string $model,
        ?array $devPlan,
        array $aiPolicyOverride = [],
    ): ?array {
        if ($mode !== 'dev' || $devPlan === null) {
            return null;
        }

        $profile = $this->programmingProfileFromDevPlan($devPlan);
        $executionProfile = (array) data_get($devPlan, 'execution_profile', []);
        $intent = $this->programming->programmingIntent($input, $profile, $devPlan);

        try {
            $plan = app(AtlasProgrammingOrchestrator::class)->sessionPlan($workspace, $profile, [
                'task' => $input,
                'provider' => $provider,
                'model' => $model,
                'interactive' => true,
                'complete' => (bool) ($executionProfile['complete'] ?? ($profile === 'forge')),
                'auto_test' => (bool) ($executionProfile['auto_test'] ?? ($profile === 'forge')),
                'max_iterations' => $profile === 'forge'
                    ? ProgrammingIterationPolicy::DEFAULT_FORGE_ITERATIONS
                    : ProgrammingIterationPolicy::forExecutionPolicy(
                        $executionProfile['max_iterations'] ?? null,
                        (bool) ($executionProfile['complete'] ?? false),
                        false,
                    ),
                'parent_plan_id' => is_string($devPlan['plan_id'] ?? null) ? $devPlan['plan_id'] : null,
                'ai_policy_override' => $aiPolicyOverride,
                'force_harness' => (bool) ($intent['force_harness'] ?? false),
                'intent' => (string) ($intent['kind'] ?? ''),
            ]);
            $plan['operator_intent'] = $intent;

            return $plan;
        } catch (\Throwable $exception) {
            return [
                'schema_version' => 1,
                'status' => 'plan_failed',
                'orchestrator' => 'AtlasProgrammingOrchestrator',
                'programming_profile' => $profile,
                'operator_intent' => $intent,
                'workspace' => $workspace,
                'error' => class_basename($exception),
                'message' => AtlasSecurity::redactString($exception->getMessage()),
                'created_at' => now()->toJSON(),
            ];
        }
    }

    private function programmingProfileFromDevPlan(?array $devPlan): string
    {
        $profile = data_get($devPlan, 'programming_profile')
            ?: data_get($devPlan, 'programming_session_plan.programming_profile')
            ?: data_get($devPlan, 'dev_execution_plan.programming_profile');

        return $profile === 'forge' ? 'forge' : 'dev';
    }

    public function shortId(string $id): string
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
        $relations = ['thread', 'session'];

        if (DatabaseTableAvailability::has('ai_jobs')) {
            $relations[] = 'job';
            $relations[] = 'jobs';
        }

        if (DatabaseTableAvailability::has('ai_quality_evaluations')) {
            $relations[] = 'qualityEvaluation';
        }

        if (DatabaseTableAvailability::has('ai_quality_actions')) {
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

    /** Sections cannot reach the protected $input; expose the single query they need. */
    public function inputIsInteractive(): bool
    {
        return $this->input->isInteractive();
    }

    // Delegators kept so the test reflection surface (ReflectionMethod on AiChatCommand) stays intact.
    private function classifyBracketedPaste(string $payload): array
    {
        return $this->repl->classifyBracketedPaste($payload);
    }

    private function interactivePromptLabel(?string $threadId, array $pendingImages): string
    {
        return $this->repl->interactivePromptLabel($threadId, $pendingImages);
    }

    private function attachInlineImagePaths(AtlasImageAttachmentService $images, string $workspace, string $input, array $pending): array
    {
        return $this->repl->attachInlineImagePaths($images, $workspace, $input, $pending);
    }

    private function fixPromptInput(string $description): string
    {
        return $this->programming->fixPromptInput($description);
    }

    private function imageCommandPaths(string $input): array
    {
        return $this->repl->imageCommandPaths($input);
    }

    private function imageProviderSwitchBlocked(?string $provider, array $pendingImages, array $queuedMessages): bool
    {
        return $this->repl->imageProviderSwitchBlocked($provider, $pendingImages, $queuedMessages);
    }

    private function inputFromBlankClipboardPaste(AtlasImageAttachmentService $images, string $workspace, array $pending): array
    {
        return $this->repl->inputFromBlankClipboardPaste($images, $workspace, $pending);
    }

    private function maybeAutoAttachClipboardImage(AtlasImageAttachmentService $images, string $workspace, string $input, array $pending): array
    {
        return $this->repl->maybeAutoAttachClipboardImage($images, $workspace, $input, $pending);
    }

    private function openImageAttachment(array $attachments, string $argument): void
    {
        $this->repl->openImageAttachment($attachments, $argument);
    }

    private function printPendingImages(array $attachments, bool $detailed = false): void
    {
        $this->repl->printPendingImages($attachments, $detailed);
    }

    private function programmingDispatchContract(?array $programmingMessagePlan): ?array
    {
        return $this->programming->programmingDispatchContract($programmingMessagePlan);
    }

    private function programmingExecutionRequestData(
        string $input,
        string $workspace,
        ?string $provider,
        ?string $model,
        string $permissionMode,
        array $payload,
        array $programmingMessagePlan,
    ): array
    {
        return $this->programming->programmingExecutionRequestData($input, $workspace, $provider, $model, $permissionMode, $payload, $programmingMessagePlan);
    }

    private function shouldAutoAttachClipboardImage(string $input): bool
    {
        return $this->repl->shouldAutoAttachClipboardImage($input);
    }

    private function toolPermissions(string $workspace, string $workflowMode, ?string $provider, string $permissionMode): array
    {
        return $this->ui->toolPermissions($workspace, $workflowMode, $provider, $permissionMode);
    }
}
