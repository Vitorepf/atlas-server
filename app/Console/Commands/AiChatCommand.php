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
use App\Support\AtlasPhpBinary;
use App\Support\AtlasSecurity;
use App\Support\TerminalMarkdownRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Terminal;
use Symfony\Component\Process\Process;

class AiChatCommand extends Command
{
    use RendersProviderChoiceMenu;

    private ?\DateTimeImmutable $replSessionStartedAt = null;

    private ?string $replStatusBarProvider = null;

    private ?string $replStatusBarModel = null;

    /** @var array<int,string> */
    private array $replSubmittedHistory = [];

    protected $signature = 'atlas:ai:chat
        {input? : One-shot input. Omit it to open the interactive Atlas CLI loop}
        {--ai= : Session AI/provider alias: claude, codex, gemini or conselho}
        {--provider= : claude, codex, gemini, conselho, claude_cli, codex_cli, gemini_cli or claude_codex}
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
        AtlasAiRuntimeSettings $settings,
        FairClaudePolicy $fairClaude,
    ): int {
        $workspace = $this->workspace();
        $declaredDevPlan = $this->devExecutionPlanOption();
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

                return $this->kernelPipelineViolation($violation);
            }
        }
        $fairFlags = $fairClaude->normalizeFlags($this->fairClaudeFlags($declaredDevPlan));
        $fairMode = (bool) ($fairFlags['fair_mode'] ?? false);
        $explicitProvider = $this->providerKey($this->option('conselho') ? 'conselho' : ($this->option('provider') ?: $this->option('ai') ?: null));
        $provider = $explicitProvider;
        if ($fairMode && $provider === null) {
            $provider = FairClaudePolicy::PROVIDER_LOCK;
        }
        $modelOption = $this->option('model') ?: null;
        if ($fairMode && (! is_string($modelOption) || trim($modelOption) === '')) {
            $modelOption = FairClaudePolicy::MODEL_LOCK;
        }
        $modelSelection = $this->modelSelection(is_string($modelOption) ? $modelOption : null, $provider);
        $computeEffort = $this->computeEffortSelection($this->option('effort'));
        if ($modelSelection !== null && ! $provider && is_string($modelSelection['provider'] ?? null)) {
            $provider = $modelSelection['provider'];
        }
        if ($fairMode) {
            $fairValidation = $fairClaude->validate($provider, $modelSelection);
            if (! (bool) ($fairValidation['ok'] ?? false)) {
                return $this->fairModeViolation($fairValidation);
            }
        }
        $manualProviderRequested = $explicitProvider !== null || $modelSelection !== null;
        $mode = $this->workflowMode($this->option('dev') ? 'dev' : (string) $this->option('mode'));
        if ($modelSelection !== null && ! $this->modelSelectionMatchesProvider($modelSelection, $provider)) {
            $this->error('Modelo '.$this->modelSelectionLabel($modelSelection).' nao combina com provider '.($provider ? $this->providerDisplayName($provider) : 'padrao').'. Use --provider correto ou remova --model.');

            return self::FAILURE;
        }
        if ($manualProviderRequested && ! $this->manualProviderAllowed($provider, $settings)) {
            return $this->manualProviderBlocked((string) $provider);
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
        $lastImages = [];

        try {
            $pendingImages = $this->initialImageAttachments($imageAttachments, $workspace);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->maybeCleanupStaleAttachments($imageAttachments);

        if ((bool) $this->option('list-threads')) {
            $this->printThreads($workspace);

            return self::SUCCESS;
        }

        $skillTrust = $this->prepareSkillBundles($skillDiscovery, $skillBundles, $workspace);

        if (is_string($input) && trim($input) !== '') {
            [$input, $pendingImages] = $this->attachInlineImagePaths($imageAttachments, $workspace, trim($input), $pendingImages);
            $pendingImages = $this->maybeAutoAttachClipboardImage($imageAttachments, $workspace, trim($input), $pendingImages);
            if ($pendingImages !== [] && ! $this->providerSupportsCliImages($provider)) {
                return $this->imageProviderBlocked((string) $provider);
            }
            $effectivePermission = $this->resolveEffectivePermission($intent, trim($input), $permissionMode);
            $trace = $this->send($gateway, $worker, trim($input), $workspace, $provider, $mode, $effectivePermission, $stream, $threadId, $threadId === null || (bool) $this->option('new-thread'), $activatedSkills, $pendingImages, $modelSelection, $computeEffort, $fairMode ? $fairClaude->metadata() : null, $fairFlags);
            $this->maybeRunDevQualityGate($quality, $workspace, $mode, $pendingImages);

            return $trace->status === 'succeeded' || (bool) $this->option('no-run')
                ? self::SUCCESS
                : self::FAILURE;
        }

        if (! $this->option('json')) {
            $this->printWelcome($workspace, $provider, $mode, $permissionMode, $stream, $busyMode, $threadId, $activatedSkills, $skillTrust, $modelSelection);
            $this->replStatusBarProvider = $this->shortenProviderForStatus($provider);
            $this->replStatusBarModel = $this->shortenModelForStatus($modelSelection);
            $history->load($workspace);
        }

        while (true) {
            $drainedTrace = $this->drainQueuedMessages($queuedMessages, $gateway, $worker, $quality, $workspace, $provider, $mode, $permissionMode, $stream, $threadId, $activatedSkills, $intent, $modelSelection, $computeEffort, $fairMode ? $fairClaude->metadata() : null, $fairFlags);
            if ($drainedTrace) {
                $threadId = $drainedTrace->thread_id ?: $threadId;
            }

            try {
                [$line, $pendingImages] = $this->readInteractiveLine($this->interactivePromptLabel($threadId, $pendingImages), $imageAttachments, $workspace, $pendingImages);
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
                [$line, $pendingImages] = $this->inputFromBlankClipboardPaste($imageAttachments, $workspace, $pendingImages);
                if ($line === null) {
                    continue;
                }
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

            if ($line === '/fix' || str_starts_with($line, '/fix ')) {
                $line = $this->fixPromptInput(trim(Str::after($line, '/fix')));
                $mode = 'dev';
                if (! $provider) {
                    $provider = $this->defaultProviderKey();
                }
                $permissionMode = $this->permissionMode($permissionMode, $mode);
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

            if ($line === '/effort' || str_starts_with($line, '/effort ')) {
                $computeEffort = $this->handleEffortCommand(trim(Str::after($line, '/effort')), $computeEffort);

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
                $nextProvider = $this->providerKey(trim(Str::after($line, '/provider ')));
                if ($this->imageProviderSwitchBlocked($nextProvider, $pendingImages, $queuedMessages)) {
                    $this->error($this->imageProviderBlockedMessage((string) $nextProvider));
                    $this->line($this->dim('Mantenha provider visual, use /provider codex|gemini, ou limpe com /clear-images.'));

                    continue;
                }
                $provider = $nextProvider;
                if ($modelSelection !== null && ! $this->modelSelectionMatchesProvider($modelSelection, $provider)) {
                    $this->warn('Modelo fixado nao combina com esse provider; override de modelo limpo.');
                    $modelSelection = null;
                }
                $this->line('Provider ativo: '.($provider ?: 'padrao'));

                continue;
            }

            if (in_array($line, ['/paste-image', '/clipboard-image', '/paste', '/p', '/img'], true)) {
                try {
                    $pendingImages = $this->mergeImageAttachments($pendingImages, [$imageAttachments->fromClipboard($workspace)], $imageAttachments);
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
                    $this->printPendingImages($pendingImages);
                } catch (\Throwable $exception) {
                    $this->error($exception->getMessage());
                }

                continue;
            }

            if ($line === '/images') {
                $this->printPendingImages($pendingImages !== [] ? $pendingImages : $lastImages, detailed: true);

                continue;
            }

            if ($line === '/open-image' || str_starts_with($line, '/open-image ')) {
                $this->openImageAttachment($pendingImages !== [] ? $pendingImages : $lastImages, trim(Str::after($line, '/open-image')));

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

            [$line, $pendingImages] = $this->attachInlineImagePaths($imageAttachments, $workspace, $line, $pendingImages);
            $pendingImages = $this->maybeAutoAttachClipboardImage($imageAttachments, $workspace, $line, $pendingImages);
            if ($pendingImages !== [] && ! $this->providerSupportsCliImages($provider)) {
                $this->error($this->imageProviderBlockedMessage((string) $provider));

                continue;
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

                    $this->setPendingSteer($states, $activeTrace, $line);
                    $this->line('(steering - will reach agent before the next provider call)');

                    continue;
                }

                $this->cancelTrace($activeTrace);
                $this->warn('(interrupted - previous trace cancelled; sending new message)');
            }

            $effectivePermission = $this->resolveEffectivePermission($intent, $line, $permissionMode);
            $sentImages = $pendingImages;
            $trace = $this->send($gateway, $worker, $line, $workspace, $provider, $mode, $effectivePermission, $stream, $threadId, $threadId === null, $messageSkills, $sentImages, $modelSelection, $computeEffort, $fairMode ? $fairClaude->metadata() : null, $fairFlags);
            if ($pendingImages !== []) {
                $lastImages = $pendingImages;
            }
            $pendingImages = [];
            $threadId = $trace->thread_id ?: $threadId;
            $this->maybeRunDevQualityGate($quality, $workspace, $mode, $sentImages);
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

        $activatedSkills = $this->normalizeSkillNames($activatedSkills);
        $imageAttachments = $this->normalizeImageAttachments($imageAttachments);
        $inputForPrompt = $imageAttachments === [] ? $input : $this->inputWithImageSummary($input, $imageAttachments);
        $agentSlug = $this->agentSlug($mode);
        $modelOverride = $this->modelOverrideFromSelection($modelSelection);
        $aiPolicyOverride = $this->aiPolicyOverride($provider, $modelSelection, $modelOverride, fairMode: $fairModeMetadata !== null);
        if ($fairModeMetadata !== null) {
            $inputForPrompt = $this->fairClaudePromptContract($inputForPrompt);
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
        $devPlan = $this->activeDevExecutionPlan(
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
            'workspace_context' => $this->workspaceContext($workspace),
            'tool_permissions' => $this->toolPermissions($workspace, $mode, $provider, $permissionMode),
            'open_brain' => $this->openBrainPayload($mode, $devPlan),
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
            $payload['programming_dispatch'] = $this->programmingDispatchContract($programmingMessagePlan);
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

        if ($this->shouldDispatchProgrammingExecutor($programmingMessagePlan)) {
            $trace = $this->dispatchProgrammingExecutor(
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
        ?string $computeEffort = null,
        ?array $fairModeMetadata = null,
        array $fairFlags = [],
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
        $batchComputeEffort = collect($items)
            ->map(fn (mixed $item): mixed => is_array($item) ? ($item['effort'] ?? null) : null)
            ->filter(fn (mixed $item): bool => is_string($item) && $item !== '')
            ->last() ?: $computeEffort;
        $messageCount = count($items);
        $this->line("(queued batch - sending {$messageCount} message(s))");

        $effectivePermission = $intent
            ? $this->resolveEffectivePermission($intent, $batch, $permissionMode)
            : $permissionMode;
        $trace = $this->send($gateway, $worker, $batch, $workspace, $provider, $mode, $effectivePermission, $stream, $threadId, $threadId === null, $batchSkills, $batchImages, $batchModelSelection, $batchComputeEffort, $fairModeMetadata, $fairFlags);
        $this->maybeRunDevQualityGate($quality, $workspace, $mode, $batchImages);

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

        $this->flushMarkdownStream();

        if ($trace->response_text && $this->streamedAssistantContent === '') {
            $this->line('');
            $this->line($this->renderMarkdown($trace->response_text));

            if ($this->output->isVerbose()) {
                $this->printTraceDiagnostic($trace, $quality);
            }

            return;
        }

        if ($trace->job?->error_message) {
            $this->line('');
            $summary = $this->summarizeErrorContent((string) $trace->job->error_message);
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

        $this->printTraceDiagnostic($trace, $quality);
    }

    private function printTraceDiagnostic(AiTrace $trace, mixed $quality = null): void
    {
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

        $openBrain = data_get($trace->metadata, 'open_brain_injection');
        if (is_array($openBrain) && ($openBrain['status'] ?? null) && ($openBrain['status'] ?? null) !== 'skipped') {
            $style = in_array($openBrain['status'], ['injected'], true) ? 'fg=green' : 'fg=yellow';
            $hash = is_string($openBrain['context_pack_hash'] ?? null) ? substr((string) $openBrain['context_pack_hash'], 0, 10) : 'sem hash';
            $refs = (int) data_get($openBrain, 'summary.context_refs', 0);
            $this->line('<'.$style.'>open brain '.$openBrain['status'].'</> <fg=gray>hash '.$hash.' · refs '.$refs.'</>');
        }

        if ($quality) {
            $flags = collect($quality->flags)->pluck('code')->implode(', ') ?: 'none';
            $qualityStyle = $quality->status === 'passed' ? 'fg=green' : ($quality->status === 'failed' ? 'fg=red' : 'fg=yellow');
            $this->line('<'.$qualityStyle.'>quality '.$quality->score.'/100 '.$quality->status.'</> <fg=gray>flags '.$flags.'</>');
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
                    ['Ctrl+V', 'cola imagem do clipboard no composer e mostra [imagem 1, imagem 2] antes de enviar'],
                    ['Ctrl+X', 'remove a ultima imagem anexada (preserva o texto digitado)'],
                    ['Ctrl+U', 'limpa o texto digitado na linha (preserva imagens anexadas)'],
                    ['Enter vazio', 'fallback: verifica clipboard e envia a imagem se houver'],
                    ['/paste-image', 'anexa a imagem atual do clipboard (aliases: /paste, /p, /img)'],
                    ['/image <path>', 'anexa arquivo png/jpg/webp/gif'],
                    ['/images', 'lista imagens anexadas para a proxima mensagem'],
                    ['/open-image [N]', 'abre a imagem N no Preview/Finder do macOS'],
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
                    ['/fix [texto]', 'envia repair no fluxo atual do atlas dev'],
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

        $pending = $images->dedupe([$attachment]);

        if (! (bool) $this->option('json')) {
            $this->line('Imagem do clipboard detectada e anexada automaticamente.');
            $this->printPendingImages($pending);
        }

        return $pending;
    }

    /**
     * @param  array<int,array<string,mixed>>  $pending
     * @return array{0:?string,1:array<int,array<string,mixed>>}
     */
    private function inputFromBlankClipboardPaste(AtlasImageAttachmentService $images, string $workspace, array $pending): array
    {
        if ((bool) $this->option('no-auto-image')) {
            if (! (bool) $this->option('json')) {
                $this->warn('Entrada vazia; auto imagem esta desligado por --no-auto-image.');
            }

            return [null, $pending];
        }

        if ($pending !== []) {
            if (! (bool) $this->option('json')) {
                $this->line('Imagem ja anexada; enviando para analise visual.');
            }

            return ['Analise a imagem anexada.', $pending];
        }

        if (! (bool) $this->option('json')) {
            $this->line('Verificando clipboard visual, aguarde...');
        }

        try {
            $attachment = $images->fromClipboard($workspace);
        } catch (\Throwable $exception) {
            if (! (bool) $this->option('json')) {
                $this->warn('Nenhuma imagem detectada no clipboard apos Enter vazio. Copie o screenshot novamente e aperte Enter.');
                $this->line($this->dim('detalhe: '.$exception->getMessage()));
            }

            return [null, $pending];
        }

        if (! (bool) $this->option('json')) {
            $this->line('Imagem detectada; preparando anexo visual...');
        }

        $pending = $images->dedupe([$attachment]);

        if (! (bool) $this->option('json')) {
            $this->line('Imagem colada do clipboard e anexada automaticamente.');
            $this->printPendingImages($pending);
        }

        return ['Analise a imagem anexada.', $pending];
    }

    /**
     * @param  array<int,array<string,mixed>>  $pendingImages
     */
    private function interactivePromptLabel(?string $threadId, array $pendingImages): string
    {
        $base = $threadId ? 'atlas '.$this->shortId($threadId) : 'atlas';

        if ($pendingImages !== []) {
            return $base.' ['.$this->imagemTokens($pendingImages).']';
        }

        return $base;
    }

    /**
     * @param  array<int,array<string,mixed>>  $pendingImages
     * @return array{0:?string,1:array<int,array<string,mixed>>}
     */
    private function readInteractiveLine(string $label, AtlasImageAttachmentService $images, string $workspace, array $pendingImages): array
    {
        if (! $this->canReadRawTerminal()) {
            return [$this->ask($label), $pendingImages];
        }

        $composer = new ReplComposer;
        $composer->attachImages($pendingImages);

        $renderer = new ReplRenderer(
            $this->output,
            supportsAnsi: $this->output->isDecorated(),
        );
        $parser = new KeySequenceParser;
        $statusBar = new StatusBarFormatter;
        $sessionStartedAt = $this->replSessionStartedAt ??= new \DateTimeImmutable;
        $renderer->setStatusBarProducer(fn (ReplComposer $c): string => $statusBar->format(
            $this->replStatusBarProvider,
            $this->replStatusBarModel,
            $c,
            $sessionStartedAt,
        ));

        $stty = trim((string) shell_exec('stty -g 2>/dev/null'));
        $bracketedPaste = false;

        try {
            $this->output->write(KeyCodes::SEQ_BRACKETED_PASTE_ENABLE);
            $bracketedPaste = true;
            $this->setRawTerminalMode();
            $renderer->paint($label, $composer);

            while (true) {
                $first = fread(STDIN, 1);
                if ($first === false || $first === '') {
                    continue;
                }
                $keyChar = $this->readUtf8Char($first);
                $event = $parser->parse(
                    $keyChar,
                    fn (int $maxBytes = 8): string => $this->readAvailableTerminalSequence($maxBytes),
                    fn (string $marker): string => $this->readBracketedPasteUntil($marker),
                );

                $action = $this->handleReplKey($event, $composer, $renderer, $images, $workspace, $label);
                if ($action === 'submit') {
                    $this->output->write("\n");
                    $submitted = trim($composer->text());
                    if ($submitted !== '' && (end($this->replSubmittedHistory) ?: null) !== $submitted) {
                        $this->replSubmittedHistory[] = $submitted;
                        if (count($this->replSubmittedHistory) > 200) {
                            array_shift($this->replSubmittedHistory);
                        }
                    }

                    return [$composer->text(), $composer->images()];
                }
                if ($action === 'cancel') {
                    $composer->checkpoint();
                    $composer->clearLine();
                    $renderer->paint($label, $composer);
                }
            }
        } finally {
            if ($stty !== '') {
                shell_exec('stty '.$stty.' 2>/dev/null');
            }
            if ($bracketedPaste) {
                $this->output->write(KeyCodes::SEQ_BRACKETED_PASTE_DISABLE);
            }
        }
    }

    private function runHistoryReverseSearch(
        ReplComposer $composer,
        ReplRenderer $renderer,
        string $label,
    ): void {
        if ($this->replSubmittedHistory === []) {
            $renderer->feedback('Sem historico ainda nessa sessao.');
            $renderer->paint($label, $composer);

            return;
        }

        $search = new HistorySearch($this->replSubmittedHistory);
        $original = $renderer->statusBarProducer();
        $renderer->setStatusBarProducer(fn () => $search->statusLine());
        $renderer->paint($label, $composer);

        $parser = new KeySequenceParser;

        while (true) {
            $first = fread(STDIN, 1);
            if ($first === false || $first === '') {
                continue;
            }
            $keyChar = $this->readUtf8Char($first);
            $event = $parser->parse(
                $keyChar,
                fn (int $maxBytes = 8): string => $this->readAvailableTerminalSequence($maxBytes),
                fn (string $marker): string => $this->readBracketedPasteUntil($marker),
            );

            $kind = $event->kind;

            if ($kind === KeyEvent::CHAR) {
                $search->appendQueryChar($event->payload);
                $renderer->paint($label, $composer);

                continue;
            }
            if ($kind === KeyEvent::BACKSPACE) {
                $search->deleteQueryChar();
                $renderer->paint($label, $composer);

                continue;
            }
            if ($kind === KeyEvent::CTRL_R) {
                $search->findNext();
                $renderer->paint($label, $composer);

                continue;
            }
            if ($kind === KeyEvent::ENTER) {
                $match = $search->currentMatch();
                if ($match !== null) {
                    $composer->checkpoint();
                    $composer->clearLine();
                    $composer->insertText($match);
                }
                $renderer->setStatusBarProducer($original);
                $renderer->paint($label, $composer);

                return;
            }
            if (in_array($kind, [
                KeyEvent::CTRL_G,
                KeyEvent::INTERRUPT,
            ], true)) {
                $renderer->setStatusBarProducer($original);
                $renderer->paint($label, $composer);

                return;
            }
        }
    }

    private function shortenProviderForStatus(?string $provider): ?string
    {
        if ($provider === null) {
            return null;
        }
        $clean = (string) preg_replace('/_cli$/', '', $provider);

        return $clean !== '' ? $clean : null;
    }

    /**
     * @param  array<string,mixed>|null  $modelSelection
     */
    private function shortenModelForStatus(?array $modelSelection): ?string
    {
        if ($modelSelection === null) {
            return null;
        }
        $candidate = (string) ($modelSelection['model'] ?? $modelSelection['label'] ?? '');
        if ($candidate === '') {
            return null;
        }
        $candidate = preg_replace('/^claude-/', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/^gpt-/', 'gpt-', $candidate) ?? $candidate;

        return strlen($candidate) > 30 ? substr($candidate, 0, 27).'...' : $candidate;
    }

    private function readUtf8Char(string $firstByte): string
    {
        if ($firstByte === '') {
            return $firstByte;
        }
        $code = ord($firstByte);
        if ($code < 0x80) {
            return $firstByte;
        }

        $expected = match (true) {
            ($code & 0xE0) === 0xC0 => 1,
            ($code & 0xF0) === 0xE0 => 2,
            ($code & 0xF8) === 0xF0 => 3,
            default => 0,
        };

        $char = $firstByte;
        for ($i = 0; $i < $expected; $i++) {
            $next = fread(STDIN, 1);
            if ($next === false || $next === '') {
                break;
            }
            $char .= $next;
        }

        return $char;
    }

    private function readBracketedPasteUntil(string $marker): string
    {
        $payload = '';
        while (true) {
            $chunk = fread(STDIN, 4096);
            if ($chunk === false) {
                continue;
            }
            $payload .= $chunk;
            if (str_contains($payload, $marker)) {
                $payload = substr($payload, 0, strpos($payload, $marker));
                break;
            }
        }

        return $payload;
    }

    /**
     * Handler de KeyEvent emitido pelo KeySequenceParser. Atualiza Composer e
     * delega rendering ao ReplRenderer. Devolve 'submit' apenas em ENTER.
     */
    private function handleReplKey(
        KeyEvent $event,
        ReplComposer $composer,
        ReplRenderer $renderer,
        AtlasImageAttachmentService $images,
        string $workspace,
        string $label,
    ): string {
        $kind = $event->kind;

        if ($kind === KeyEvent::ENTER) {
            return 'submit';
        }

        if ($kind === KeyEvent::INTERRUPT) {
            throw new ConsoleRuntimeException('Interrupted');
        }

        if ($kind === KeyEvent::EOF) {
            if ($composer->isEmpty()) {
                throw new ConsoleRuntimeException('EOF');
            }

            return 'continue';
        }

        if ($kind === KeyEvent::CHAR) {
            if (! $composer->isImageSelectionActive()) {
                $composer->checkpoint();
            }
            $composer->insertChar($event->payload);
            $renderer->paint($label, $composer);

            return 'continue';
        }

        if ($kind === KeyEvent::CTRL_Z) {
            if ($composer->undo()) {
                $renderer->paint($label, $composer);
            }

            return 'continue';
        }

        if ($kind === KeyEvent::CTRL_Y) {
            if ($composer->redo()) {
                $renderer->paint($label, $composer);
            }

            return 'continue';
        }

        if ($kind === KeyEvent::CTRL_R) {
            $this->runHistoryReverseSearch($composer, $renderer, $label);

            return 'continue';
        }

        if ($kind === KeyEvent::CTRL_G) {
            return 'cancel';
        }

        $navOps = [
            KeyEvent::ARROW_LEFT => fn () => $composer->moveCursorLeft(),
            KeyEvent::ARROW_RIGHT => fn () => $composer->moveCursorRight(),
            KeyEvent::ARROW_UP => fn () => $composer->moveCursorUp(),
            KeyEvent::ARROW_DOWN => fn () => $composer->moveCursorDown(),
            KeyEvent::HOME => fn () => $composer->moveCursorToLineStart(),
            KeyEvent::END => fn () => $composer->moveCursorToLineEnd(),
            KeyEvent::ALT_LEFT => fn () => $composer->moveCursorWordLeft(),
            KeyEvent::ALT_RIGHT => fn () => $composer->moveCursorWordRight(),
            KeyEvent::SHIFT_LEFT => fn () => $composer->extendSelectionLeft(),
            KeyEvent::SHIFT_RIGHT => fn () => $composer->extendSelectionRight(),
            KeyEvent::SHIFT_ALT_LEFT => fn () => $composer->extendSelectionWordLeft(),
            KeyEvent::SHIFT_ALT_RIGHT => fn () => $composer->extendSelectionWordRight(),
            KeyEvent::SHIFT_HOME => fn () => $composer->extendSelectionToLineStart(),
            KeyEvent::SHIFT_END => fn () => $composer->extendSelectionToLineEnd(),
            KeyEvent::SELECT_ALL => fn () => $composer->selectAll(),
            KeyEvent::SELECT_LINE => fn () => $composer->selectLine(),
        ];

        if (isset($navOps[$kind])) {
            $navOps[$kind]();
            $renderer->paint($label, $composer);

            return 'continue';
        }

        $destructiveOps = [
            KeyEvent::ALT_BACKSPACE => fn () => $composer->deleteWordBefore(),
            KeyEvent::CTRL_W => fn () => $composer->deleteWordBefore(),
            KeyEvent::DELETE => fn () => $composer->deleteCharAfter(),
            KeyEvent::BACKSPACE => fn () => $composer->deleteCharBefore(),
            KeyEvent::CTRL_U => fn () => $composer->clearLine(),
        ];

        if (isset($destructiveOps[$kind])) {
            $composer->checkpoint();
            $destructiveOps[$kind]();
            $renderer->paint($label, $composer);

            return 'continue';
        }

        if ($kind === KeyEvent::CTRL_X) {
            if ($composer->hasImages()) {
                $isSelection = $composer->isImageSelectionActive();
                $removed = $isSelection
                    ? ($composer->images()[$composer->selectedImageIndex()] ?? null)
                    : ($composer->images()[count($composer->images()) - 1] ?? null);
                if ($isSelection) {
                    $composer->removeSelectedImage();
                } else {
                    $composer->removeLastImage();
                }
                $name = is_array($removed) && is_string($removed['path'] ?? null) ? basename($removed['path']) : 'imagem';
                $renderer->feedback(ReplMessages::imageRemoved($name));
                $renderer->paint($label, $composer);
            }

            return 'continue';
        }

        if ($kind === KeyEvent::CTRL_L) {
            $renderer->reset();
            $renderer->paint($label, $composer);

            return 'continue';
        }

        if ($kind === KeyEvent::CTRL_V) {
            $this->handleSmartPasteFromClipboard($composer, $renderer, $images, $workspace, $label);

            return 'continue';
        }

        if ($kind === KeyEvent::BRACKETED_PASTE) {
            $this->handleBracketedPasteEvent($event->payload, $composer, $renderer, $images, $workspace, $label);

            return 'continue';
        }

        return 'continue';
    }

    private function handleSmartPasteFromClipboard(
        ReplComposer $composer,
        ReplRenderer $renderer,
        AtlasImageAttachmentService $images,
        string $workspace,
        string $label,
    ): void {
        $kind = $images->clipboardKind();

        if ($kind === 'image') {
            $renderer->feedback(ReplMessages::clipboardImageReading());
            try {
                $attachment = $images->fromClipboard($workspace);
            } catch (\Throwable $exception) {
                $renderer->feedback(ReplMessages::clipboardImageInvalid($exception->getMessage()));
                $renderer->paint($label, $composer);

                return;
            }
            $added = $composer->attachImage($attachment);
            $renderer->feedback($added
                ? ReplMessages::clipboardImageAttached()
                : ReplMessages::imageDuplicate(basename($attachment['path'] ?? 'imagem')));
            $renderer->paint($label, $composer);

            return;
        }

        if ($kind === 'text') {
            $text = $images->clipboardText();
            if ($text !== null && $text !== '') {
                $composer->insertText($text);
            }
            $renderer->paint($label, $composer);

            return;
        }

        $message = $kind === 'empty'
            ? ReplMessages::clipboardEmpty()
            : ReplMessages::clipboardOsascriptBlocked();
        $renderer->feedback($message);
        $renderer->paint($label, $composer);
    }

    private function handleBracketedPasteEvent(
        string $payload,
        ReplComposer $composer,
        ReplRenderer $renderer,
        AtlasImageAttachmentService $images,
        string $workspace,
        string $label,
    ): void {
        $classification = $this->classifyBracketedPaste($payload);

        if ($classification['kind'] === 'text') {
            $composer->insertText($payload);
            $renderer->paint($label, $composer);

            return;
        }

        if ($classification['kind'] === 'clipboard_image') {
            $this->handleSmartPasteFromClipboard($composer, $renderer, $images, $workspace, $label);

            return;
        }

        if ($classification['kind'] === 'image_path' && isset($classification['path'])) {
            $this->attachImagesFromDrop([$classification['path']], $composer, $renderer, $images, $workspace, $label);

            return;
        }

        if ($classification['kind'] === 'image_paths' && isset($classification['paths']) && is_array($classification['paths'])) {
            $this->attachImagesFromDrop($classification['paths'], $composer, $renderer, $images, $workspace, $label);
        }
    }

    /**
     * @param  array<int,string>  $paths
     */
    private function attachImagesFromDrop(
        array $paths,
        ReplComposer $composer,
        ReplRenderer $renderer,
        AtlasImageAttachmentService $images,
        string $workspace,
        string $label,
    ): void {
        if ($paths === []) {
            return;
        }
        $renderer->feedback(count($paths) === 1
            ? ReplMessages::imageAttached(basename($paths[0]))
            : 'Anexando '.count($paths).' imagens...');
        try {
            $attachments = $images->fromPaths($paths, $workspace);
        } catch (\Throwable $exception) {
            $renderer->feedback(ReplMessages::imageAttachFailed($exception->getMessage()));
            $renderer->paint($label, $composer);

            return;
        }
        $result = $composer->attachImages($attachments);
        if ($result['skipped'] > 0) {
            $renderer->feedback(ReplMessages::imagesDeduped($result['added'], $result['skipped']));
        }
        $renderer->paint($label, $composer);
    }

    private function canReadRawTerminal(): bool
    {
        return defined('STDIN') && function_exists('stream_isatty') && stream_isatty(STDIN) && ! (bool) $this->option('json');
    }

    private function setRawTerminalMode(): void
    {
        shell_exec('stty -icanon -echo min 0 time 1 2>/dev/null');
    }

    /**
     * @param  array<int,array<string,mixed>>  $pendingImages
     */
    private function imagemTokens(array $pendingImages): string
    {
        $tokens = [];
        foreach (array_values($pendingImages) as $index => $attachment) {
            $path = is_string($attachment['path'] ?? null) ? $attachment['path'] : '';
            $label = 'imagem '.($index + 1);
            if ($path === '') {
                $tokens[] = $label;

                continue;
            }
            $tokens[] = "\033]8;;file://".$path."\033\\".$label."\033]8;;\033\\";
        }

        return implode(', ', $tokens);
    }

    /**
     * @return array{kind:'clipboard_image'|'image_path'|'image_paths'|'text', path?:string, paths?:array<int,string>}
     */
    private function classifyBracketedPaste(string $payload): array
    {
        $trimmed = trim($payload);

        if ($trimmed === '') {
            return ['kind' => 'clipboard_image'];
        }

        if (str_contains($trimmed, "\n")) {
            return ['kind' => 'text'];
        }

        $singlePath = $this->resolveImagePathCandidate($trimmed);
        if ($singlePath !== null) {
            return ['kind' => 'image_path', 'path' => $singlePath];
        }

        $arguments = $this->shellLikeArguments($trimmed);
        if (count($arguments) <= 1) {
            return ['kind' => 'text'];
        }

        $resolvedPaths = [];
        foreach ($arguments as $argument) {
            $resolved = $this->resolveImagePathCandidate($argument);
            if ($resolved === null) {
                return ['kind' => 'text'];
            }
            $resolvedPaths[] = $resolved;
        }

        return ['kind' => 'image_paths', 'paths' => array_values(array_unique($resolvedPaths))];
    }

    private function resolveImagePathCandidate(string $candidate): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }

        if (str_starts_with($candidate, 'file://')) {
            $path = parse_url($candidate, PHP_URL_PATH);
            $candidate = is_string($path) ? rawurldecode($path) : '';
        }

        if ($candidate === '' || ! str_starts_with($candidate, '/') || ! is_file($candidate)) {
            return null;
        }

        $imageInfo = @getimagesize($candidate);
        $mime = is_array($imageInfo) && is_string($imageInfo['mime'] ?? null) ? $imageInfo['mime'] : null;
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            return null;
        }

        return $candidate;
    }

    /**
     * @param  array{kind:string, path?:string}  $classification
     * @param  array<int,array<string,mixed>>  $pendingImages
     */
    private function applyBracketedPasteClassification(
        array $classification,
        AtlasImageAttachmentService $images,
        string $workspace,
        string &$buffer,
        string &$label,
        array &$pendingImages,
    ): void {
        if ($classification['kind'] === 'clipboard_image') {
            [$label, $pendingImages] = $this->pasteClipboardImageIntoComposer($images, $workspace, $pendingImages, $label, $buffer);

            return;
        }

        if ($classification['kind'] === 'image_path' && isset($classification['path'])) {
            $this->output->write("\n");
            $this->line('Anexando imagem '.basename($classification['path']).'...');

            try {
                $attachments = $images->fromPaths([$classification['path']], $workspace);
            } catch (\Throwable $exception) {
                $this->warn('Nao consegui anexar imagem: '.$exception->getMessage());
                $this->renderRawPrompt($label, $buffer);

                return;
            }

            $pendingImages = $this->mergeImageAttachments($pendingImages, $attachments, $images);
            $this->printPendingImages($pendingImages);
            $label = $this->labelWithImageCount($label, $pendingImages);
            $this->renderRawPrompt($label, $buffer);

            return;
        }
    }

    private function readAvailableTerminalSequence(int $maxBytes = 16): string
    {
        $sequence = '';

        while (strlen($sequence) < $maxBytes) {
            $read = [STDIN];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 0, 10000) !== 1) {
                break;
            }

            $char = fread(STDIN, 1);
            if ($char === false || $char === '') {
                break;
            }
            $sequence .= $char;

            if (preg_match('/[~A-Za-z]$/', $sequence) === 1) {
                break;
            }
        }

        return $sequence;
    }

    /**
     * @param  array<int,array<string,mixed>>  $pending
     * @return array{0:string,1:array<int,array<string,mixed>>}
     */
    private function attachInlineImagePaths(AtlasImageAttachmentService $images, string $workspace, string $input, array $pending): array
    {
        if ($input === '' || str_starts_with(ltrim($input), '/')) {
            return [$input, $pending];
        }

        $paths = $this->inlineImagePaths($input, $workspace);
        if ($paths === []) {
            return [$input, $pending];
        }

        $attachments = $images->fromPaths($paths, $workspace);
        $pending = $this->mergeImageAttachments($pending, $attachments, $images);
        $cleanedInput = $this->stripInlineImagePaths($input, $paths);
        if (trim($cleanedInput) === '') {
            $cleanedInput = 'Analise a imagem anexada.';
        }

        if (! (bool) $this->option('json')) {
            $this->line(count($attachments) === 1
                ? 'Imagem detectada no terminal e anexada automaticamente.'
                : 'Imagens detectadas no terminal e anexadas automaticamente.');
            $this->printPendingImages($pending);
        }

        return [$cleanedInput, $pending];
    }

    /**
     * @return array<int,string>
     */
    private function inlineImagePaths(string $input, string $workspace): array
    {
        return collect($this->shellLikeArguments($input))
            ->map(fn (string $argument): string => $this->normalizeInlineImagePathCandidate($argument))
            ->filter(fn (string $path): bool => $path !== '' && $this->looksLikeImagePath($path))
            ->filter(fn (string $path): bool => File::isFile($this->expandInlinePath($path, $workspace)))
            ->unique()
            ->values()
            ->all();
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

        return $this->shellLikeArguments($input);
    }

    /**
     * @return array<int,string>
     */
    private function shellLikeArguments(string $input): array
    {
        if ($input === '') {
            return [];
        }

        $arguments = [];
        $buffer = '';
        $quote = null;
        $escaping = false;
        $length = strlen($input);

        for ($index = 0; $index < $length; $index++) {
            $char = $input[$index];

            if ($escaping) {
                $buffer .= $char;
                $escaping = false;

                continue;
            }

            if ($char === '\\') {
                $escaping = true;

                continue;
            }

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                } else {
                    $buffer .= $char;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if (ctype_space($char)) {
                if ($buffer !== '') {
                    $arguments[] = $buffer;
                    $buffer = '';
                }

                continue;
            }

            $buffer .= $char;
        }

        if ($escaping) {
            $buffer .= '\\';
        }

        if ($buffer !== '') {
            $arguments[] = $buffer;
        }

        return $arguments;
    }

    private function normalizeInlineImagePathCandidate(string $argument): string
    {
        $argument = trim($argument);
        $argument = trim($argument, " \t\n\r\0\x0B,;()[]{}<>");

        if (str_starts_with($argument, 'file://')) {
            $decoded = rawurldecode((string) parse_url($argument, PHP_URL_PATH));

            return $decoded !== '' ? $decoded : $argument;
        }

        return $argument;
    }

    private function looksLikeImagePath(string $path): bool
    {
        return preg_match('/\.(png|jpe?g|webp|gif)$/i', parse_url($path, PHP_URL_PATH) ?: $path) === 1;
    }

    private function expandInlinePath(string $path, string $workspace): string
    {
        if (str_starts_with($path, '~/')) {
            return $this->expandUserPath($path);
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
    }

    /**
     * @param  array<int,string>  $paths
     */
    private function stripInlineImagePaths(string $input, array $paths): string
    {
        foreach ($paths as $path) {
            $quoted = preg_quote($path, '/');
            $escapedSpaces = preg_quote(str_replace(' ', '\\ ', $path), '/');
            $fileUri = preg_quote('file://'.$path, '/');

            $input = preg_replace('/(?:"'.$quoted.'"|\''.$quoted.'\'|'.$escapedSpaces.'|'.$fileUri.'|'.$quoted.')/u', ' ', $input) ?? $input;
        }

        return Str::of($input)->squish()->value();
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
    private function printPendingImages(array $attachments, bool $detailed = false): void
    {
        if ($attachments === []) {
            $this->line('Nenhuma imagem anexada para a proxima mensagem.');

            return;
        }

        if (! $detailed) {
            $this->line($this->imageAttachmentCompactLine($attachments));

            return;
        }

        $this->line('Imagens anexadas: '.count($attachments));
        foreach ($attachments as $index => $attachment) {
            $path = (string) ($attachment['path'] ?? '');
            $originalPath = (string) ($attachment['original_path'] ?? $path ?: '-');
            $this->line(sprintf('  %d. imagem anexada e pronta para envio visual', $index + 1));
            $this->line('     origem: '.(string) ($attachment['source'] ?? 'file'));
            $this->line('     detalhe: '.$this->imageAttachmentDetail($attachment));
            $this->line('     arquivo: '.$this->terminalFileLink($originalPath));
            $this->line('     abrir: '.$this->terminalFileLink($path !== '' ? $path : $originalPath));
            $this->printInlineImagePreview($path);
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $attachments
     */
    private function imageAttachmentCompactLine(array $attachments): string
    {
        $count = count($attachments);
        $first = $attachments[0] ?? [];
        $detail = $first !== [] ? $this->imageAttachmentDetail((array) $first) : 'sem metadados';
        $suffix = $count === 1 ? '' : ' · use /images para detalhes';

        return '[img:'.$count.'] pronta para enviar · '.$detail.$suffix;
    }

    private function terminalFileLink(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '-') {
            return '-';
        }

        $uri = 'file://'.str_replace('%2F', '/', rawurlencode($path));
        if (! $this->output->isDecorated()) {
            return $uri;
        }

        return "\033]8;;{$uri}\033\\{$path}\033]8;;\033\\";
    }

    /**
     * @param  array<int,array<string,mixed>>  $attachments
     */
    private function openImageAttachment(array $attachments, string $argument): void
    {
        if ($attachments === []) {
            $this->warn('Nenhuma imagem anexada ou enviada recentemente.');

            return;
        }

        $index = max(1, (int) ($argument !== '' ? $argument : 1)) - 1;
        $attachment = $attachments[$index] ?? null;
        if (! is_array($attachment)) {
            $this->warn('Imagem nao encontrada. Use /images para ver a lista.');

            return;
        }

        $path = (string) ($attachment['path'] ?? $attachment['original_path'] ?? '');
        if ($path === '' || ! File::isFile($path)) {
            $this->warn('Arquivo da imagem nao encontrado.');

            return;
        }

        $process = new Process(['open', $path]);
        $process->run();

        if ($process->isSuccessful()) {
            $this->line('Abrindo imagem '.($index + 1).': '.$path);

            return;
        }

        $this->error('Falha ao abrir imagem: '.trim($process->getErrorOutput() ?: $process->getOutput()));
    }

    /**
     * @param  array<string,mixed>  $attachment
     */
    private function imageAttachmentDetail(array $attachment): string
    {
        $parts = array_values(array_filter([
            (string) ($attachment['mime_type'] ?? 'image'),
            $this->humanBytes((int) ($attachment['bytes'] ?? 0)),
            $this->imageDimensions((string) ($attachment['path'] ?? '')),
            $this->shortSha((string) ($attachment['sha256'] ?? '')),
        ], fn (?string $part): bool => is_string($part) && $part !== ''));

        return implode(' · ', $parts);
    }

    private function imageDimensions(string $path): ?string
    {
        if ($path === '' || ! File::isFile($path)) {
            return null;
        }

        $size = @getimagesize($path);
        if (! is_array($size) || ! is_int($size[0] ?? null) || ! is_int($size[1] ?? null)) {
            return null;
        }

        return $size[0].'x'.$size[1];
    }

    private function humanBytes(int $bytes): ?string
    {
        if ($bytes <= 0) {
            return null;
        }

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 2).' MB';
    }

    private function shortSha(string $sha256): ?string
    {
        $sha256 = trim($sha256);

        return $sha256 !== '' ? 'sha256 '.substr($sha256, 0, 12) : null;
    }

    private function printInlineImagePreview(string $path): void
    {
        if (! $this->supportsInlineImagePreview()) {
            $this->line($this->dim('     preview: terminal sem preview inline; anexo confirmado por metadados.'));

            return;
        }

        if ($path === '' || ! File::isFile($path) || File::size($path) > 5 * 1024 * 1024) {
            $this->line($this->dim('     preview: arquivo indisponivel ou grande demais para render inline.'));

            return;
        }

        $contents = File::get($path);
        if ($contents === '') {
            $this->line($this->dim('     preview: arquivo vazio.'));

            return;
        }

        $payload = base64_encode($contents);
        $this->output->write("\033]1337;File=inline=1;width=40;height=auto;preserveAspectRatio=1:{$payload}\a\n");
    }

    private function supportsInlineImagePreview(): bool
    {
        if (! $this->output->isDecorated()) {
            return false;
        }

        $termProgram = (string) ($_SERVER['TERM_PROGRAM'] ?? getenv('TERM_PROGRAM') ?: '');

        return in_array($termProgram, ['iTerm.app', 'WezTerm'], true);
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
        $process = new Process(array_merge([AtlasPhpBinary::path(), 'artisan'], $arguments), base_path(), AtlasSecurity::processEnv(profile: 'internal'));
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

    /**
     * @param  array<int,array<string,mixed>>  $imageAttachments
     */
    private function maybeRunDevQualityGate(AtlasCliQualityService $quality, string $workspace, string $mode, array $imageAttachments = []): void
    {
        if ($mode !== 'dev' || (bool) $this->option('no-quality-gate') || (bool) $this->option('json') || (bool) $this->option('no-run')) {
            return;
        }

        if ($imageAttachments !== [] && ! $this->output->isVerbose()) {
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
        $terminal = new Terminal;
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
            'write' => 'pode editar e rodar dentro do workspace',
            default => 'so leitura e inspecao',
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

    private function computeEffortSelection(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return app(ComputeEffortPolicy::class)->normalize((string) $value);
    }

    private function handleEffortCommand(string $argument, ?string $current): ?string
    {
        $argument = trim($argument);
        if ($argument === '') {
            $this->line('Esforco atual: '.($current ?: 'balanced'));
            $this->line('Use /effort fast, /effort balanced, /effort deep ou /effort max.');

            return $current;
        }

        $effort = $this->computeEffortSelection($argument);
        if ($effort === null) {
            $this->warn('Esforco invalido. Use fast, balanced, deep ou max.');

            return $current;
        }

        $this->line('Esforco ativo: '.$effort);

        return $effort;
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
            'gemini_flash',
            'gemini_cli',
            [
                'model' => (string) config('atlas.ai.providers.gemini_cli.models.gemini_flash.model', 'gemini-3.5-flash'),
                'label' => (string) config('atlas.ai.providers.gemini_cli.models.gemini_flash.label', 'Gemini Flash'),
                'tier' => (string) config('atlas.ai.providers.gemini_cli.models.gemini_flash.tier', 'daily'),
            ],
            'default',
            'Gemini rapido',
            ['gemini', 'gemini-flash', 'gemini_flash', 'gemini-3.5-flash', 'gemini-3-5-flash'],
        );
        $this->appendModelCatalogRow(
            $rows,
            'gemini_pro',
            'gemini_cli',
            [
                'model' => (string) config('atlas.ai.providers.gemini_cli.models.gemini_pro.model', 'gemini-3.1-pro-preview'),
                'label' => (string) config('atlas.ai.providers.gemini_cli.models.gemini_pro.label', 'Gemini Pro'),
                'tier' => (string) config('atlas.ai.providers.gemini_cli.models.gemini_pro.tier', 'premium'),
            ],
            'premium',
            'Gemini raciocinio profundo',
            ['gemini-pro', 'gemini_pro', 'gemini-3.1-pro-preview', 'gemini-3-1-pro'],
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
            ['5.5', '55', 'codex-premium', 'codex-5.5', 'gpt-5.5', 'gpt-premium', 'premium-codex'],
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

    /**
     * @param  array<string,mixed>|null  $modelSelection
     * @return array<string,mixed>
     */
    private function modelSelectionContract(?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode, array $context = []): array
    {
        return app(ModelSelectionContractFactory::class)->forAiChat($provider, $modelSelection, $modelOverride, $fairMode, $context);
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

    private function manualProviderAllowed(?string $provider, AtlasAiRuntimeSettings $settings): bool
    {
        if (! in_array($provider, ['claude_cli', 'codex_cli', 'gemini_cli'], true)) {
            return true;
        }

        return (bool) ($settings->providerConfig((string) $provider)['allow_manual'] ?? true);
    }

    private function manualProviderBlocked(string $provider): int
    {
        $message = "Provider {$provider} esta bloqueado para uso manual pelas configuracoes do Atlas app.";
        if ((bool) $this->option('json')) {
            $this->line(json_encode(AtlasSecurity::redactArray([
                'ok' => false,
                'phase' => 'preflight',
                'error' => 'atlas_manual_provider_blocked',
                'provider' => $provider,
                'message' => $message,
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function providerSupportsCliImages(?string $provider): bool
    {
        return $provider === null || in_array($provider, ['claude_cli', 'codex_cli', 'gemini_cli'], true);
    }

    /**
     * Garbage collection de anexos de imagem antigos.
     * Roda no maximo uma vez a cada 24h por workspace, controlado por touch file.
     */
    private function maybeCleanupStaleAttachments(AtlasImageAttachmentService $images): void
    {
        $marker = storage_path('app/ai/attachments/.last-cleanup');
        $lastRun = is_file($marker) ? (int) @filemtime($marker) : 0;
        if ($lastRun > 0 && (time() - $lastRun) < 86400) {
            return;
        }

        try {
            $directory = dirname($marker);
            if (! is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }
            $images->cleanupStaleAttachments(7);
            @touch($marker);
        } catch (\Throwable) {
            // cleanup nao deve quebrar o REPL
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $pendingImages
     * @param  array<int,array<string,mixed>>  $queuedMessages
     */
    private function imageProviderSwitchBlocked(?string $provider, array $pendingImages, array $queuedMessages): bool
    {
        if ($this->providerSupportsCliImages($provider)) {
            return false;
        }

        return $pendingImages !== [] || $this->queuedMessagesHaveImages($queuedMessages);
    }

    /**
     * @param  array<int,array<string,mixed>>  $queuedMessages
     */
    private function queuedMessagesHaveImages(array $queuedMessages): bool
    {
        foreach ($queuedMessages as $message) {
            if ((array) ($message['images'] ?? []) !== []) {
                return true;
            }
        }

        return false;
    }

    private function imageProviderBlocked(string $provider): int
    {
        $message = $this->imageProviderBlockedMessage($provider);
        if ((bool) $this->option('json')) {
            $this->line(json_encode(AtlasSecurity::redactArray([
                'ok' => false,
                'phase' => 'preflight',
                'error' => 'atlas_image_provider_unsupported',
                'provider' => $provider,
                'message' => $message,
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function imageProviderBlockedMessage(string $provider): string
    {
        return "Provider {$provider} nao suporta imagens neste runtime. Use /provider codex ou /provider gemini, ou remova o override manual.";
    }

    private function shouldDispatchProgrammingExecutor(?array $programmingMessagePlan): bool
    {
        return data_get($programmingMessagePlan, 'executor_decision.executor') === 'engineering_harness';
    }

    /**
     * @param  array<string,mixed>|null  $programmingMessagePlan
     * @return array<string,mixed>|null
     */
    private function programmingDispatchContract(?array $programmingMessagePlan): ?array
    {
        return app(AtlasProgrammingOrchestrator::class)->dispatchContract($programmingMessagePlan);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $programmingMessagePlan
     */
    private function dispatchProgrammingExecutor(
        string $input,
        string $workspace,
        ?string $provider,
        ?string $model,
        string $mode,
        string $permissionMode,
        ?string $threadId,
        ?string $agentSlug,
        array $payload,
        array $programmingMessagePlan,
        float $startedAt,
    ): AiTrace {
        $result = app(AtlasProgrammingOrchestrator::class)
            ->executeWithHarness(ProgrammingExecutionRequest::fromArray($this->programmingExecutionRequestData(
                input: $input,
                workspace: $workspace,
                provider: $provider,
                model: $model,
                permissionMode: $permissionMode,
                payload: $payload,
                programmingMessagePlan: $programmingMessagePlan,
            )))
            ->toArray();

        $status = in_array($result['status'] ?? null, ['passed', 'partial'], true) ? 'succeeded' : 'failed';
        $response = $this->programmingExecutorResponseText($result);
        $dispatch = array_merge(
            (array) ($payload['programming_dispatch'] ?? []),
            [
                'status' => $status === 'succeeded' ? 'executed' : 'blocked',
                'trace_provider' => 'engineering_harness',
                'completed_at' => now()->toJSON(),
            ],
        );
        $metadata = AtlasSecurity::redactArray([
            'model_label' => $model,
            'programming_profile' => data_get($programmingMessagePlan, 'programming_profile'),
            'programming_session_plan' => $payload['programming_session_plan'] ?? null,
            'programming_dispatch' => $dispatch,
            'programming_message_plan' => $programmingMessagePlan,
            'programming_repair' => $payload['programming_repair'] ?? null,
            'programming_completion' => app(AtlasProgrammingOrchestrator::class)->harnessCompletionContract($result, $dispatch, $model),
            'programming_result' => $result,
            'dispatch' => [
                'executor' => 'engineering_harness',
                'source' => 'AtlasProgrammingOrchestrator',
                'mode' => $mode,
            ],
        ]);

        $attributes = [
            'trace_key' => 'trace_'.Str::orderedUuid()->toString(),
            'thread_id' => $threadId,
            'status' => $status,
            'operator_input' => $input,
            'agent_slug' => $agentSlug,
            'provider' => 'engineering_harness',
            'model' => $model,
            'response_hash' => hash('sha256', $response),
            'response_text' => $response,
            'latency_ms' => $this->elapsedMs($startedAt),
            'completed_at' => now(),
            'metadata' => $metadata,
        ];

        if (Schema::hasTable('ai_traces')) {
            return AiTrace::query()->create($attributes);
        }

        return tap(new AiTrace, function (AiTrace $trace) use ($attributes): void {
            $trace->forceFill(['id' => (string) Str::orderedUuid()] + $attributes);
        });
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $programmingMessagePlan
     * @return array<string,mixed>
     */
    private function programmingExecutionRequestData(
        string $input,
        string $workspace,
        ?string $provider,
        ?string $model,
        string $permissionMode,
        array $payload,
        array $programmingMessagePlan,
    ): array {
        $profile = data_get($programmingMessagePlan, 'programming_profile') === 'forge' ? 'forge' : 'dev';
        $overrides = (array) data_get($payload, 'dev_execution_plan.operator_options.harness_overrides', []);
        $executionProfile = (array) data_get($programmingMessagePlan, 'execution_profile', []);
        $complete = (bool) ($executionProfile['complete'] ?? ($profile === 'forge'));

        return [
            'profile' => $profile,
            'workspace' => $workspace,
            'task' => $input,
            'objective' => $input,
            'provider' => $provider,
            'model' => $model,
            'permission' => $permissionMode,
            'complete' => $complete,
            'auto_test' => (bool) ($executionProfile['auto_test'] ?? ($profile === 'forge')),
            'critical' => $profile === 'forge',
            'max_attempts' => ProgrammingIterationPolicy::forExecutionPolicy(
                $executionProfile['max_iterations'] ?? null,
                $complete,
                $profile === 'forge',
            ),
            'no_provider' => (bool) $this->option('no-run'),
            'test_command' => is_string($overrides['test_command'] ?? null) ? $overrides['test_command'] : null,
            'sandbox' => is_string($overrides['sandbox'] ?? null) ? $overrides['sandbox'] : null,
            'provider_runtime' => is_string($overrides['provider_runtime'] ?? null) ? $overrides['provider_runtime'] : null,
            'visual_e2e' => is_string($overrides['visual_e2e'] ?? null) ? $overrides['visual_e2e'] : null,
            'quality_scan' => is_string($overrides['quality_scan'] ?? null) ? $overrides['quality_scan'] : null,
            'harness_policy' => is_string($overrides['harness_policy'] ?? null) ? $overrides['harness_policy'] : null,
            'apply_isolated_patch' => (bool) ($overrides['apply_isolated_patch'] ?? true),
            'policy_contracts' => data_get($programmingMessagePlan, 'policy_contracts')
                ?: data_get($programmingMessagePlan, 'policy_profile.policy_contracts')
                ?: data_get($programmingMessagePlan, 'policy_profile.effective_policy.operational_contracts')
                ?: [],
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function programmingExecutorResponseText(array $result): string
    {
        $score = data_get($result, 'harness_payload.run.score');
        $evidence = implode(', ', array_map('strval', (array) ($result['evidence_refs'] ?? []))) ?: '-';
        $blocking = implode('; ', array_map('strval', (array) ($result['blocking_failures'] ?? []))) ?: '-';

        return implode("\n", [
            '# Atlas Programming Executor',
            '',
            '- Status: '.(string) ($result['status'] ?? 'unknown'),
            '- Executor: '.(string) ($result['executor'] ?? 'engineering_harness'),
            '- Task: '.(string) ($result['task_id'] ?? '-'),
            '- Engineering run: '.(string) data_get($result, 'harness_payload.run.id', '-'),
            '- Decision: '.(string) data_get($result, 'harness_payload.run.decision', 'unknown'),
            '- Score: '.($score === null ? '-' : (string) $score),
            '- Evidence: '.$evidence,
            '- Blocking failures: '.$blocking,
        ]);
    }

    /**
     * @param  array<string,mixed>|null  $devPlan
     * @return array<string,bool>
     */
    private function fairClaudeFlags(?array $devPlan = null): array
    {
        $devPlanFair = (bool) data_get($devPlan, 'fair_mode.fair_mode')
            || (bool) data_get($devPlan, 'operator_options.fair_mode');

        return [
            'claude_only' => (bool) $this->option('claude-only') || (bool) data_get($devPlan, 'operator_options.claude_only'),
            'single_provider' => (bool) $this->option('single-provider') || $devPlanFair || (bool) data_get($devPlan, 'operator_options.single_provider'),
            'no_decide' => (bool) $this->option('no-decide') || $devPlanFair || (bool) data_get($devPlan, 'operator_options.no_decide'),
            'fallback_disabled' => (bool) $this->option('fallback-disabled') || $devPlanFair || (bool) data_get($devPlan, 'operator_options.fallback_disabled'),
        ];
    }

    /**
     * @param  array<string,mixed>  $violation
     */
    private function fairModeViolation(array $violation): int
    {
        $payload = AtlasSecurity::redactArray([
            'ok' => false,
            'phase' => 'preflight',
            'error' => FairClaudePolicy::ERROR_CODE,
            'message' => (string) ($violation['message'] ?? 'Fair Claude mode violation.'),
            'fair_mode' => $violation['fair_mode'] ?? [],
            'details' => $violation['details'] ?? [],
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $this->error((string) $payload['message']);

        return self::FAILURE;
    }

    private function fairClaudePromptContract(string $prompt): string
    {
        return app(AtlasCliDevWorkflowService::class)
            ->fairClaudePromptContract($prompt);
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

    /**
     * @param  array<string,mixed>|null  $modelSelection
     * @return array<string,mixed>
     */
    private function aiPolicyOverride(?string $provider, ?array $modelSelection = null, ?string $modelOverride = null, bool $fairMode = false): array
    {
        if (! in_array($provider, ['claude_cli', 'codex_cli', 'gemini_cli'], true)) {
            return [];
        }

        if ($fairMode) {
            return app(FairClaudePolicy::class)->runtimeOverride($modelSelection, $modelOverride);
        }

        $override = [
            'default_provider' => $provider,
            'enabled_providers' => ['claude_cli', 'codex_cli', 'gemini_cli'],
            'disabled_providers' => [],
            'fallback_order' => array_values(array_unique([$provider, 'claude_cli', 'codex_cli', 'gemini_cli'])),
            'allow_council' => false,
            'allow_multistage_graph' => false,
        ];

        $model = $modelOverride ?: (is_string($modelSelection['model'] ?? null) ? (string) $modelSelection['model'] : null);
        if (is_string($model) && trim($model) !== '') {
            $model = trim($model);
            $override['providers'][$provider] = array_filter([
                'model' => $model,
                'model_label' => is_string($modelSelection['label'] ?? null) ? (string) $modelSelection['label'] : null,
                'model_tier' => is_string($modelSelection['tier'] ?? null) ? (string) $modelSelection['tier'] : null,
                'model_identity' => $model,
                'allow_auto' => true,
                'allow_manual' => true,
            ], fn (mixed $value): bool => $value !== null);
            $override['allowed_models'][$provider] = [$model];
        }

        return $override;
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

    private function fixPromptInput(string $description): string
    {
        $description = trim($description);

        return $description !== ''
            ? "Corrija: {$description}"
            : 'Corrija o ultimo teste falho, bug ou quality gate detectado neste workspace. Primeiro inspecione o estado atual, depois aplique a menor correcao segura.';
    }

    /**
     * @param  array<string,mixed>  $devPlan
     * @return array<string,mixed>
     */
    private function programmingIntent(string $input, string $profile, array $devPlan): array
    {
        $explicitIntent = (string) (
            data_get($devPlan, 'operator_options.programming_intent')
            ?: data_get($devPlan, 'operator_options.intent')
            ?: data_get($devPlan, 'programming_intent')
            ?: ''
        );
        $text = strtolower(Str::ascii($input));
        $matchedRepair = $this->matchedIntentSignals($text, [
            'corrija', 'corrigir', 'conserte', 'consertar', 'arrume', 'arrumar',
            'fix', 'repair', 'bug', 'erro', 'error', 'falha', 'falhando',
            'teste falhando', 'test failing', 'quality gate', 'quebrado',
        ]);
        $matchedHarness = $this->matchedIntentSignals($text, [
            'forge', 'harness', 'fluxo inteiro', 'todo o fluxo', 'ponta a ponta',
            'end to end', 'e2e', 'banco', 'database', 'migration', 'migracao',
            'fila', 'queue', 'worker', 'ui', 'frontend', 'api', 'testes',
            'arquitetura', 'refatoracao grande', 'refatorar grande', 'complexo',
            'dificil', 'critico', 'producao', 'seguranca', 'permissao',
        ]);
        $layerCount = $this->programmingIntentLayerCount($text);
        $explicitRepair = in_array($explicitIntent, ['repair', 'fix', 'quality_repair'], true);
        $explicitHarness = in_array('forge', $matchedHarness, true) || in_array('harness', $matchedHarness, true);
        $forceHarness = $profile === 'forge'
            || $explicitHarness
            || $layerCount >= 3
            || count($matchedHarness) >= 4;

        return [
            'schema_version' => 1,
            'source' => 'atlas_dev_auto_intent',
            'kind' => match (true) {
                $forceHarness => 'harness',
                $explicitRepair || $matchedRepair !== [] => 'repair',
                default => 'implementation',
            },
            'force_harness' => $forceHarness,
            'repair_detected' => $explicitRepair || $matchedRepair !== [],
            'explicit_intent' => $explicitIntent !== '' ? $explicitIntent : null,
            'harness_detected' => $matchedHarness !== [] || $layerCount >= 3,
            'matched_repair_signals' => $matchedRepair,
            'matched_harness_signals' => $matchedHarness,
            'layer_count' => $layerCount,
            'profile' => $profile,
            'parent_plan_id' => data_get($devPlan, 'plan_id'),
        ];
    }

    /**
     * @param  array<int,string>  $signals
     * @return array<int,string>
     */
    private function matchedIntentSignals(string $text, array $signals): array
    {
        return collect($signals)
            ->filter(fn (string $signal): bool => str_contains($text, $signal))
            ->values()
            ->all();
    }

    private function programmingIntentLayerCount(string $text): int
    {
        return collect([
            ['banco', 'database', 'migration', 'migracao', 'schema'],
            ['api', 'endpoint', 'controller', 'service', 'job', 'worker', 'fila', 'queue'],
            ['ui', 'frontend', 'tela', 'componente', 'formulario'],
            ['teste', 'testes', 'test', 'e2e', 'lint', 'quality'],
            ['permissao', 'permission', 'auth', 'seguranca', 'security'],
        ])->filter(fn (array $signals): bool => collect($signals)->contains(
            fn (string $signal): bool => str_contains($text, $signal)
        ))->count();
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

    /**
     * @param  array<string,mixed>  $aiPolicyOverride
     * @return array<string,mixed>|null
     */
    private function activeDevExecutionPlan(
        string $workspace,
        string $mode,
        string $input,
        ?string $provider,
        ?string $model,
        array $aiPolicyOverride = [],
    ): ?array {
        $declared = $this->devExecutionPlanOption();
        if ($declared !== null) {
            return $this->withKernelPipelinePlan($declared, $workspace, $input, 'declared_dev_plan');
        }

        if ($mode !== 'dev') {
            return null;
        }

        $plan = app(AtlasProgrammingOrchestrator::class)->sessionPlan($workspace, 'dev', [
            'task' => $input,
            'provider' => $provider,
            'model' => $model,
            'interactive' => true,
            'complete' => true,
            'auto_test' => (bool) $this->option('auto-test'),
            'max_iterations' => 3,
            'ai_policy_override' => $aiPolicyOverride,
        ]);
        data_set($plan, 'operator_options.input_mode', 'chat_dev_auto_plan');
        data_set($plan, 'operator_options.generated_by', 'AiChatCommand');

        return $this->withKernelPipelinePlan($plan, $workspace, $input, 'chat_dev_auto_plan');
    }

    /**
     * @param  array<string,mixed>  $devPlan
     * @return array<string,mixed>
     */
    private function withKernelPipelinePlan(array $devPlan, string $workspace, string $input, string $inputMode): array
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
        $intent = $this->programmingIntent($input, $profile, $devPlan);
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

    private function kernelPipelineViolation(KernelPipelinePlanViolation $violation): int
    {
        $payload = AtlasSecurity::redactArray([
            'ok' => false,
            'phase' => 'preflight',
            'error' => 'atlas_kernel_pipeline_contract_violation',
            'message' => $violation->getMessage(),
            'violations' => $violation->errors,
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $this->error((string) $payload['message']);
        foreach ($payload['violations'] as $message) {
            $this->line('- '.(string) $message);
        }

        return self::FAILURE;
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
        $intent = $this->programmingIntent($input, $profile, $devPlan);

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

    /**
     * @return array<string,mixed>
     */
    private function openBrainPayload(string $mode, ?array $devPlan = null): array
    {
        $budget = $this->option('open-brain-budget');
        $budgetChars = is_scalar($budget) && trim((string) $budget) !== ''
            ? max(2000, (int) $budget)
            : null;
        $surface = $devPlan === null
            ? 'cli_chat'
            : (data_get($devPlan, 'resumed_at') ? 'cli_continue' : 'cli_dev');

        return array_filter([
            'mode' => (bool) $this->option('no-open-brain')
                ? 'off'
                : ((bool) $this->option('require-open-brain') ? 'required' : 'auto'),
            'surface' => $surface,
            'workflow_mode' => $mode,
            'budget_chars' => $budgetChars,
            'refresh' => (bool) $this->option('open-brain-refresh'),
            'provider_safe_only' => true,
        ], fn (mixed $value): bool => $value !== null);
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
        $relations = ['thread', 'session'];

        if (Schema::hasTable('ai_jobs')) {
            $relations[] = 'job';
            $relations[] = 'jobs';
        }

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
