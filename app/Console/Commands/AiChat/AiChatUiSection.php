<?php

namespace App\Console\Commands\AiChat;

use App\Console\Commands\AiChatCommand;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Services\Ai\Cli\AtlasCliPanel;
use App\Services\Ai\Cli\AtlasCliQualityService;
use App\Services\Ai\Cli\AtlasTerminalTheme;
use App\Services\Ai\Cli\IntentPermissionResolver;
use App\Services\Ai\Cli\IntentResolution;
use App\Services\Ai\Skills\SkillBundleStore;
use App\Services\Ai\Skills\SkillDiscoveryService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Support\JsonFileStore;
use App\Support\AtlasPhpBinary;
use App\Support\AtlasSecurity;
use App\Support\TerminalMarkdownRenderer;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Terminal;
use Symfony\Component\Process\Process;

/**
 * Console rendering, panels, threads, permissions, skills and workspace helpers split verbatim from AiChatCommand (GOD-DEBULK).
 */
class AiChatUiSection
{
    public function __construct(private readonly AiChatCommand $command)
    {
    }

    public function printTraceDiagnostic(AiTrace $trace, mixed $quality = null): void
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
        $this->command->line('<fg=bright-blue;options=bold>Atlas</> <fg=gray>trace '.$this->command->shortId((string) $trace->id).'</> <'.$statusStyle.'>'.$trace->status.'</> <fg=gray>'.OutputFormatter::escape(implode(' · ', $runtime)).'</>');
        $this->command->line('<fg=gray>thread '.$this->command->shortId((string) $trace->thread_id).'</>');
        $activatedSkills = collect((array) data_get($trace->metadata, 'skills_activated', []))
            ->pluck('name')
            ->filter()
            ->implode(', ');
        if ($activatedSkills !== '') {
            $this->command->line('<fg=gray>skills '.$activatedSkills.'</>');
        }

        $openBrain = data_get($trace->metadata, 'open_brain_injection');
        if (is_array($openBrain) && ($openBrain['status'] ?? null) && ($openBrain['status'] ?? null) !== 'skipped') {
            $style = in_array($openBrain['status'], ['injected'], true) ? 'fg=green' : 'fg=yellow';
            $hash = is_string($openBrain['context_pack_hash'] ?? null) ? substr((string) $openBrain['context_pack_hash'], 0, 10) : 'sem hash';
            $refs = (int) data_get($openBrain, 'summary.context_refs', 0);
            $this->command->line('<'.$style.'>open brain '.$openBrain['status'].'</> <fg=gray>hash '.$hash.' · refs '.$refs.'</>');
        }

        if ($quality) {
            $flags = collect($quality->flags)->pluck('code')->implode(', ') ?: 'none';
            $qualityStyle = $quality->status === 'passed' ? 'fg=green' : ($quality->status === 'failed' ? 'fg=red' : 'fg=yellow');
            $this->command->line('<'.$qualityStyle.'>quality '.$quality->score.'/100 '.$quality->status.'</> <fg=gray>flags '.$flags.'</>');
        }
    }

    public function printStreamEvent(array $event): void
    {
        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        $content = is_string($event['content'] ?? null) ? $event['content'] : '';

        if ($type === 'token' && $content !== '') {
            $this->printMarkdownStreamChunk($content);
            $this->command->streamedAssistantContent .= $content;

            return;
        }

        if ($type === 'error' && $content !== '') {
            if ($this->command->streamedAssistantContent !== '') {
                $this->flushMarkdownStream();
            }
            $summary = $this->summarizeErrorContent($content);
            $this->command->line('<fg=red;options=bold>✗ '.$summary.'</>');
            if ($this->command->getOutput()->isVerbose()) {
                $this->command->line('<fg=gray>'.OutputFormatter::escape($content).'</>');
            }

            return;
        }

        if ($type === 'permission' && $this->command->getOutput()->isVerbose()) {
            $allowed = (bool) data_get($event, 'metadata.permission.allowed', false);
            $mode = data_get($event, 'metadata.permission.mode', 'read');
            $workspace = data_get($event, 'metadata.permission.workspace', '');
            $this->command->line(($allowed ? '<fg=gray>permission allowed</>' : '<fg=red>permission denied</>')." mode={$mode} workspace={$workspace}");
        }

        if ($type === 'stderr' && $content !== '' && $this->command->getOutput()->isVerbose()) {
            fwrite(STDERR, $content);
        }
    }

    public function summarizeErrorContent(string $content): string
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
        if (! $this->command->streamOutputStarted) {
            $this->command->newLine();
            $this->command->line('<fg=bright-blue;options=bold>Atlas</>');
            $this->command->streamOutputStarted = true;
        }

        $this->command->markdownStreamBuffer .= str_replace("\r\n", "\n", str_replace("\r", "\n", $content));
        $lines = explode("\n", $this->command->markdownStreamBuffer);
        $this->command->markdownStreamBuffer = (string) array_pop($lines);

        foreach ($lines as $line) {
            $this->emitStreamLine($line);
        }
    }

    private function emitStreamLine(string $line): void
    {
        if (preg_match('/^\s*```([^`]*)\s*$/', $line, $match) === 1) {
            if (! $this->command->streamInCodeBlock) {
                $this->command->streamInCodeBlock = true;
                $this->command->streamCodeLabel = trim((string) ($match[1] ?? ''));
                $this->command->streamCodeLineCount = 0;
                if ($this->command->renderMode === 'compact') {
                    return;
                }
            } else {
                $count = $this->command->streamCodeLineCount;
                $label = $this->command->streamCodeLabel !== '' ? $this->command->streamCodeLabel : 'codigo';
                $this->command->streamInCodeBlock = false;
                $this->command->streamCodeLabel = '';
                $this->command->streamCodeLineCount = 0;
                if ($this->command->renderMode === 'compact') {
                    $word = $count === 1 ? 'linha oculta' : 'linhas ocultas';
                    $summary = '['.$label.' - '.$count.' '.$word.']';
                    $this->command->getOutput()->write($this->renderMarkdown($summary).PHP_EOL);

                    return;
                }
            }

            $this->command->getOutput()->write($this->renderMarkdown($line).PHP_EOL);

            return;
        }

        if ($this->command->streamInCodeBlock) {
            $this->command->streamCodeLineCount++;
            if ($this->command->renderMode === 'compact') {
                return;
            }
        }

        $this->command->getOutput()->write($this->renderMarkdown($line).PHP_EOL);
    }

    public function flushMarkdownStream(): void
    {
        if ($this->command->markdownStreamBuffer === '') {
            return;
        }

        if ($this->command->streamInCodeBlock && $this->command->renderMode === 'compact') {
            $this->command->markdownStreamBuffer = '';

            return;
        }

        $this->command->getOutput()->write($this->renderMarkdown($this->command->markdownStreamBuffer));
        $this->command->markdownStreamBuffer = '';
        $this->command->newLine();
    }

    public function renderMarkdown(string $markdown): string
    {
        return app(TerminalMarkdownRenderer::class)->render(
            $markdown,
            $this->command->getOutput()->isDecorated(),
            $this->command->renderMode === 'compact',
        );
    }

    public function printHelp(): void
    {
        $decorated = $this->command->getOutput()->isDecorated();
        $sections = $this->helpSections();

        $this->command->newLine();
        $this->command->line(AtlasTerminalTheme::bold('  comandos atlas cli', $decorated));
        $this->command->line('  '.AtlasTerminalTheme::muted(str_repeat('═', 18), $decorated));

        foreach ($sections as $section) {
            $this->command->newLine();
            $title = AtlasTerminalTheme::accent('· '.$section['title'], $decorated);
            $hint = AtlasTerminalTheme::dimItalic('· '.$section['when'], $decorated);
            $this->command->line('  '.$title.' '.$hint);

            $maxCmd = 0;
            foreach ($section['commands'] as [$cmd, $desc]) {
                $maxCmd = max($maxCmd, mb_strlen($cmd, 'UTF-8'));
            }
            $maxCmd = min(28, $maxCmd);

            foreach ($section['commands'] as [$cmd, $desc]) {
                $cmdPadded = str_pad($cmd, $maxCmd, ' ', STR_PAD_RIGHT);
                $this->command->line('    '.AtlasTerminalTheme::accent($cmdPadded, $decorated).'  '.$desc);
            }
        }
        $this->command->newLine();
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
    public function prepareSkillBundles(SkillDiscoveryService $discovery, SkillBundleStore $bundles, string $workspace): array
    {
        $hasLocal = $discovery->workspaceHasLocalSkills($workspace);
        $trusted = $hasLocal && $discovery->isWorkspaceTrusted($workspace);
        $prompted = false;
        $autoTrusted = false;
        $ignored = false;

        if ($hasLocal && ! $trusted && (bool) $this->command->option('trust-workspace-skills')) {
            $discovery->trustWorkspace($workspace);
            $trusted = true;
            $autoTrusted = true;
        }

        if (
            ! (bool) $this->command->option('json')
            && ! (bool) $this->command->option('no-skill-prompt')
            && $hasLocal
            && ! $trusted
        ) {
            $prompted = true;
            $this->command->warn('Este workspace contem skills locais em .atlas/skills ou .agents/skills.');
            $this->command->warn('Skills locais podem influenciar o comportamento do Atlas. Confie apenas em repositorios que voce controla.');
            if ($this->command->confirm('Confiar nas skills locais deste workspace?', false)) {
                $discovery->trustWorkspace($workspace);
                $trusted = true;
                $this->command->line('Workspace marcado como confiavel para skills locais.');
            } else {
                $ignored = true;
                $this->command->line('Skills locais ignoradas nesta sessao. Skills builtin, user e Vault continuam disponiveis.');
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
    public function skillSlash(string $line, SkillBundleStore $bundles): ?array
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
    public function skillOptions(): array
    {
        return $this->normalizeSkillNames((array) $this->command->option('skill'));
    }

    /**
     * @param  array<int,mixed>  $skills
     * @return array<int,string>
     */
    public function normalizeSkillNames(array $skills): array
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
    public function simpleArguments(string $input): array
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
    public function printQuality(array $quality): void
    {
        $this->command->line('');
        $this->command->line('Atlas quality: '.($quality['status'] ?? 'unknown'));
        $this->command->line('summary: '.data_get($quality, 'completion_packet.summary', '-'));

        $gates = (array) ($quality['quality_gates'] ?? []);
        if ($gates !== []) {
            $this->command->table(
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
    public function runLocalAtlasCommand(array $arguments): void
    {
        $process = new Process(array_merge([AtlasPhpBinary::path(), 'artisan'], $arguments), base_path(), AtlasSecurity::processEnv(profile: 'internal'));
        $process->setTimeout(120);
        $process->run(function (string $type, string $buffer): void {
            $this->command->getOutput()->write(AtlasSecurity::redactString($buffer));
        });

        if (! $process->isSuccessful()) {
            $this->command->warn('Comando local Atlas terminou com exit code '.($process->getExitCode() ?? 'n/a').'.');
        }
    }

    public function printCliState(array $snapshot): void
    {
        $thread = is_array($snapshot['thread'] ?? null) ? $snapshot['thread'] : null;
        if (! $thread) {
            $this->command->warn('Nenhuma thread Atlas CLI ativa neste workspace.');

            return;
        }

        $session = is_array($snapshot['session'] ?? null) ? $snapshot['session'] : null;
        $state = is_array($snapshot['state'] ?? null) ? $snapshot['state'] : [];

        $this->command->line('');
        $this->command->line('Atlas session state:');
        $this->command->line('thread: '.$this->command->shortId((string) $thread['id']).' | '.($thread['title'] ?? 'sem titulo'));
        $this->command->line('session: '.($session ? $this->command->shortId((string) $session['id']).' '.$session['status'] : '-'));
        $this->command->line('objective: '.($state['objective'] ?? '-'));
        $this->command->line('phase: '.($state['current_phase'] ?? '-'));
        $this->command->line('topic: '.($state['current_topic'] ?? '-'));

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
                $this->command->line($label.':');
                foreach ($items as $item) {
                    $this->command->line('  - '.Str::limit($item, 140));
                }
            }
        }

        if (is_array($snapshot['created_compaction'] ?? null)) {
            $this->command->line('compaction: '.$this->command->shortId((string) data_get($snapshot, 'created_compaction.id')));
        }

        if (is_array($snapshot['created_provider_handoff'] ?? null)) {
            $this->command->line('handoff: '.$this->command->shortId((string) data_get($snapshot, 'created_provider_handoff.id')).' -> '.data_get($snapshot, 'created_provider_handoff.to_provider'));
        }
    }

    public function printCliStateResult(callable $callback): void
    {
        try {
            $this->printCliState($callback());
        } catch (\Throwable $exception) {
            $this->command->error($exception->getMessage());
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $imageAttachments
     */
    public function maybeRunDevQualityGate(AtlasCliQualityService $quality, string $workspace, string $mode, array $imageAttachments = []): void
    {
        if ($mode !== 'dev' || (bool) $this->command->option('no-quality-gate') || (bool) $this->command->option('json') || (bool) $this->command->option('no-run')) {
            return;
        }

        if ($imageAttachments !== [] && ! $this->command->getOutput()->isVerbose()) {
            return;
        }

        $payload = $quality->evaluate(
            workspace: $workspace,
            runTests: (bool) $this->command->option('auto-test'),
            approved: (bool) $this->command->option('allow-write') || $mode === 'dev',
        );

        $this->command->newLine();
        $this->command->line('<fg=bright-blue;options=bold>Atlas quality gate</> '.$payload['status']);
        $this->command->line((string) data_get($payload, 'completion_packet.summary'));

        $risks = (array) data_get($payload, 'completion_packet.risks', []);
        if ($risks !== []) {
            $this->command->line('Riscos:');
            foreach ($risks as $risk) {
                $this->command->line('  - '.$risk);
            }
        }

        $gates = collect((array) ($payload['quality_gates'] ?? []))
            ->map(fn (array $gate): string => "{$gate['name']}: {$gate['status']}")
            ->implode(' | ');
        if ($gates !== '') {
            $this->command->line($gates);
        }
    }

    /**
     * @param  array<int,string>  $activatedSkills
     * @param  array<string,mixed>  $skillTrust
     */
    public function printWelcome(string $workspace, ?string $provider, string $mode, string $permissionMode, bool $stream, string $busyMode, ?string $threadId, array $activatedSkills = [], array $skillTrust = [], ?array $modelSelection = null): void
    {
        $title = (bool) $this->command->option('cockpit') ? 'atlas dev cockpit' : 'atlas cli';
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
        $decorated = $this->command->getOutput()->isDecorated();
        $width = $this->panelWidth();
        $tag = $threadId ? 'thread '.$this->command->shortId($threadId) : 'nova thread';
        $skillsValue = $activatedSkills === [] ? 'auto' : implode(', ', $activatedSkills);
        $localSkills = $this->skillTrustLabel($skillTrust);
        $providerLabel = $provider ? $this->command->models->providerDisplayName($provider) : 'padrao ('.$this->command->models->providerDisplayName($this->command->models->defaultProviderKey()).')';
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
            ->kv('modelo', $this->command->models->modelPanelValue($provider, $modelSelection, $decorated))
            ->kv('modo', $mode)
            ->kv('permissao', $this->panelPermissionValue($permissionMode, $decorated))
            ->kv('stream', $this->panelTogglePair([
                'stream' => $stream ? 'on' : 'off',
                'render' => $this->command->renderMode,
                'intent' => $this->command->intentEnabled ? 'on' : 'off',
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

        $this->command->newLine();
        foreach ($panel as $line) {
            $this->command->line($line);
        }

        if (($skillTrust['ignored'] ?? false) === true) {
            $this->command->line('  '.AtlasTerminalTheme::risk('skills locais ignoradas', $decorated).' '.AtlasTerminalTheme::muted('· atlas skills trust', $decorated));
        }
        $this->command->newLine();
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
            return AtlasTerminalTheme::muted('sem raizes configuradas', $this->command->getOutput()->isDecorated());
        }
        $shown = array_slice($roots, 0, 2);
        $extra = count($roots) - count($shown);

        return implode(', ', $shown).($extra > 0 ? AtlasTerminalTheme::muted(' +'.$extra, $this->command->getOutput()->isDecorated()) : '');
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

    public function dim(string $text): string
    {
        if (! $this->command->getOutput()->isDecorated()) {
            return $text;
        }

        return "\033[2m".$text."\033[0m";
    }

    /**
     * @param  array<int,string>  $activatedSkills
     * @param  array<string,mixed>  $skillTrust
     */
    public function printRuntimeStatus(string $workspace, ?string $provider, string $mode, string $permissionMode, bool $stream, string $busyMode, ?string $threadId, array $activatedSkills = [], array $skillTrust = [], ?array $modelSelection = null): void
    {
        $title = (bool) $this->command->option('cockpit') ? 'atlas dev status' : 'atlas status';
        $this->renderConsolePanel($title, $workspace, $provider, $mode, $permissionMode, $stream, $busyMode, $threadId, $activatedSkills, $skillTrust, $modelSelection);
    }

    private function dimItalic(string $text): string
    {
        if (! $this->command->getOutput()->isDecorated()) {
            return $text;
        }

        return "\033[2;3m".$text."\033[0m";
    }

    public function resolveEffectivePermission(IntentPermissionResolver $resolver, string $input, string $currentPermission): string
    {
        if (! $this->command->intentEnabled) {
            return $currentPermission;
        }

        if (trim($input) === '' || str_starts_with(trim($input), '/')) {
            return $currentPermission;
        }

        if ((bool) $this->command->option('dangerously-allow-all') || (bool) $this->command->option('allow-write')) {
            return $currentPermission;
        }

        if ($currentPermission === 'danger') {
            return $currentPermission;
        }

        $resolution = $resolver->resolve($input, $currentPermission);

        if (! $resolution->isReadOnly() && ! (bool) $this->command->option('json')) {
            $this->renderIntentNotice($resolution);
        }

        if (! $resolution->changed) {
            return $currentPermission;
        }

        if ($this->command->intentSessionAcks[$resolution->required] ?? false) {
            return $resolution->required;
        }

        if ((bool) $this->command->option('json') || ! $this->command->inputIsInteractive()) {
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
        $this->command->line($this->dimItalic('  · esta acao vai '.$signal));
    }

    private function renderIntentBlocked(IntentResolution $resolution): void
    {
        if ((bool) $this->command->option('json')) {
            return;
        }
        $this->command->warn(sprintf(
            'intent quer %s (motivo: %s). rode com --permission=%s ou abra o REPL para confirmar.',
            $resolution->required,
            $resolution->reason,
            $resolution->required,
        ));
    }

    private function confirmIntentEscalation(IntentResolution $resolution, string $currentPermission): string
    {
        $this->command->line($this->dimItalic(
            '  · subir para '.$resolution->required.'? [s] sim · [a] sessao inteira · [n] nao',
        ));
        $answer = (string) $this->command->ask('atlas', 'n');
        $normalized = strtolower(trim($answer));

        if (in_array($normalized, ['s', 'sim', 'y', 'yes', '1'], true)) {
            $this->command->line($this->dimItalic('  · permissao temporaria · '.$resolution->required));

            return $resolution->required;
        }

        if (in_array($normalized, ['a', 'all', 'sessao', 'sessão', 'session', 'sempre'], true)) {
            $this->command->intentSessionAcks[$resolution->required] = true;
            $this->command->line($this->dimItalic('  · permissao da sessao · '.$resolution->required));

            return $resolution->required;
        }

        $this->command->line($this->dimItalic('  · seguindo em '.$currentPermission.' (operador disse nao)'));

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

    public function printThreads(string $workspace): void
    {
        if (! DatabaseTableAvailability::has('ai_threads')) {
            if ((bool) $this->command->option('json')) {
                $this->command->line(json_encode([
                    'ok' => false,
                    'error' => 'Tabela ai_threads indisponivel.',
                    'threads' => [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return;
            }

            $this->command->warn('Tabela ai_threads indisponivel.');

            return;
        }

        $threads = AiThread::query()
            ->where('surface', 'atlas_cli')
            ->where('workspace', $workspace)
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        if ((bool) $this->command->option('json')) {
            $this->command->line(json_encode([
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
            $this->command->line('Nenhuma thread Atlas CLI neste workspace.');

            return;
        }

        $this->command->table(
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
    public function toolPermissions(string $workspace, string $workflowMode, ?string $provider, string $permissionMode): array
    {
        $mode = $this->permissionMode($permissionMode, $workflowMode);
        $sandboxes = config('atlas.ai.tool_permissions.codex_sandboxes', []);
        $sandboxes = is_array($sandboxes) ? $sandboxes : [];
        $allowUnsandboxedProvider = (bool) $this->command->option('allow-unsandboxed')
            || (bool) config('atlas.ai.tool_permissions.allow_unsandboxed_write', false);
        $workspaceCert = null;
        if (in_array($mode, ['write', 'danger'], true)) {
            $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class)->gate(
                workspace: $workspace,
                mode: $workflowMode === '' ? 'dev' : $workflowMode,
                task: 'atlas_cli_workspace_permission_certification',
            );
            if (($gate['allowed'] ?? false) === true) {
                $workspaceCert = [
                    'status' => 'available',
                    'mode' => $mode,
                    'workspace_id' => (string) ($gate['workspace_id'] ?? ''),
                    'gate_hash' => (string) ($gate['gate_hash'] ?? ''),
                    'source' => 'awis_execution_gate',
                ];
            }
        }

        $permissions = [
            'schema_version' => 1,
            'source' => 'atlas_cli',
            'mode' => $mode,
            'workspace' => $workspace,
            'confirmed' => $mode === 'danger' || (bool) $this->command->option('allow-write') || $workflowMode === 'dev',
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
        if ($workspaceCert !== null) {
            $permissions['workspace_cert'] = $workspaceCert;
        }

        return $permissions;
    }

    public function permissionMode(string $requested, string $workflowMode): string
    {
        $requested = Str::of($requested)->lower()->trim()->value();

        if (in_array($requested, ['read', 'write', 'danger'], true)) {
            return $requested;
        }

        if ((bool) $this->command->option('dangerously-allow-all')) {
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

        if ((bool) $this->command->option('allow-write') || $workflowMode === 'dev') {
            return 'write';
        }

        return 'read';
    }

    public function busyInputMode(): string
    {
        $mode = Str::of((string) config('atlas.display.busy_input_mode', 'interrupt'))->lower()->trim()->value();

        return in_array($mode, ['interrupt', 'queue', 'steer'], true) ? $mode : 'interrupt';
    }

    public function showBusyHintOnce(): void
    {
        $data = $this->onboardingData();
        if ((bool) data_get($data, 'seen.busy_input_prompt', false)) {
            return;
        }

        $this->command->line('(tip) Existe uma execucao ativa. Use /busy queue ou /busy steer para nao interromper.');
        data_set($data, 'seen.busy_input_prompt', true);
        $this->writeOnboardingData($data);
    }

    /**
     * @return array<string,mixed>
     */
    private function onboardingData(): array
    {
        $path = $this->onboardingPath();
        return JsonFileStore::readArray($path) ?? [];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function writeOnboardingData(array $data): void
    {
        $path = $this->onboardingPath();
        JsonFileStore::writeLine($path, $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

    public function workspace(): string
    {
        $workspace = (string) ($this->command->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));

        return $this->resolveWorkspace($workspace);
    }

    public function resolveWorkspace(string $workspace): string
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

    public function latestThreadId(string $workspace): ?string
    {
        return AiThread::query()
            ->where('status', 'active')
            ->where('surface', 'atlas_cli')
            ->where('workspace', $workspace)
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->value('id');
    }

    public function workspaceContext(string $workspace): array
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
}
