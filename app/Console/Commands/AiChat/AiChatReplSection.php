<?php

namespace App\Console\Commands\AiChat;

use App\Console\Commands\AiChatCommand;
use App\Models\AiTrace;
use App\Services\Ai\Cli\AtlasImageAttachmentService;
use App\Services\Ai\Cli\Repl\HistorySearch;
use App\Services\Ai\Cli\Repl\KeyCodes;
use App\Services\Ai\Cli\Repl\KeyEvent;
use App\Services\Ai\Cli\Repl\KeySequenceParser;
use App\Services\Ai\Cli\Repl\ReplComposer;
use App\Services\Ai\Cli\Repl\ReplMessages;
use App\Services\Ai\Cli\Repl\ReplRenderer;
use App\Services\Ai\Cli\Repl\StatusBarFormatter;
use App\Services\Ai\ConversationOps\AiSessionStateService;
use App\Services\Ai\Provider\ProviderCatalog;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Process\Process;

/**
 * Interactive REPL input, paste/drop handling and image attachment helpers split verbatim from AiChatCommand (GOD-DEBULK).
 */
class AiChatReplSection
{
    public function __construct(private readonly AiChatCommand $command) {}

    private ?\DateTimeImmutable $replSessionStartedAt = null;

    /** @var array<int,string> */
    private array $replSubmittedHistory = [];

    public function handleBusyCommand(string $line, string $currentMode): string
    {
        $value = Str::of(Str::after($line, '/busy'))->lower()->trim()->value();
        if ($value === '' || $value === 'status') {
            $this->command->line("busy input mode: {$currentMode}");

            return $currentMode;
        }

        if (! in_array($value, ['interrupt', 'queue', 'steer'], true)) {
            $this->command->warn('Modo invalido. Use /busy interrupt, /busy queue, /busy steer ou /busy status.');

            return $currentMode;
        }

        $this->command->line("busy input mode: {$value}");

        return $value;
    }

    public function handleSteerCommand(AiSessionStateService $states, string $workspace, ?string $threadId, string $message): void
    {
        $message = trim($message);
        if ($message === '') {
            $this->command->warn('Uso: /steer <mensagem>');

            return;
        }

        $activeTrace = $this->activeTrace($threadId, $workspace);
        if (! $activeTrace) {
            $this->command->error('no agent running');

            return;
        }

        $this->setPendingSteer($states, $activeTrace, $message);
        $this->command->line('(steering - will reach agent before the next provider call)');
    }

    public function setPendingSteer(AiSessionStateService $states, AiTrace $trace, string $message): void
    {
        $states->setPendingSteer((string) $trace->thread_id, $message, $trace->session_id);
    }

    public function activeTrace(?string $threadId, string $workspace): ?AiTrace
    {
        if (! DatabaseTableAvailability::has('ai_traces') || ! DatabaseTableAvailability::has('ai_threads')) {
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

    public function cancelTrace(AiTrace $trace): void
    {
        $trace->update([
            'status' => 'cancelled',
            'completed_at' => now(),
            'metadata' => array_merge($trace->metadata ?? [], [
                'cancelled_by' => 'atlas_cli_busy_interrupt',
                'cancelled_at' => now()->toJSON(),
            ]),
        ]);

        if (DatabaseTableAvailability::has('ai_jobs')) {
            $trace->jobs()->whereIn('status', ['queued', 'processing'])->update([
                'status' => 'cancelled',
                'finished_at' => now(),
                'error_code' => 'cancelled_by_operator',
                'error_message' => 'Interrompido pelo operador via Atlas CLI busy interrupt.',
            ]);
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function initialImageAttachments(AtlasImageAttachmentService $images, string $workspace): array
    {
        $attachments = $images->fromPaths((array) $this->command->option('image'), $workspace);

        if ((bool) $this->command->option('clipboard-image')) {
            $attachments[] = $images->fromClipboard($workspace);
        }

        return $images->dedupe($attachments);
    }

    /**
     * @param  array<int,array<string,mixed>>  $existing
     * @param  array<int,array<string,mixed>>  $incoming
     * @return array<int,array<string,mixed>>
     */
    public function mergeImageAttachments(array $existing, array $incoming, AtlasImageAttachmentService $images): array
    {
        return $images->dedupe(array_merge($existing, $incoming));
    }

    /**
     * @param  array<int,array<string,mixed>>  $pending
     * @return array<int,array<string,mixed>>
     */
    public function maybeAutoAttachClipboardImage(AtlasImageAttachmentService $images, string $workspace, string $input, array $pending): array
    {
        if ($pending !== [] || (bool) $this->command->option('no-auto-image') || ! $this->shouldAutoAttachClipboardImage($input)) {
            return $pending;
        }

        try {
            $attachment = $images->fromClipboard($workspace);
        } catch (\Throwable) {
            return $pending;
        }

        $pending = $images->dedupe([$attachment]);

        if (! (bool) $this->command->option('json')) {
            $this->command->line('Imagem do clipboard detectada e anexada automaticamente.');
            $this->printPendingImages($pending);
        }

        return $pending;
    }

    /**
     * @param  array<int,array<string,mixed>>  $pending
     * @return array{0:?string,1:array<int,array<string,mixed>>}
     */
    public function inputFromBlankClipboardPaste(AtlasImageAttachmentService $images, string $workspace, array $pending): array
    {
        if ((bool) $this->command->option('no-auto-image')) {
            if (! (bool) $this->command->option('json')) {
                $this->command->warn('Entrada vazia; auto imagem esta desligado por --no-auto-image.');
            }

            return [null, $pending];
        }

        if ($pending !== []) {
            if (! (bool) $this->command->option('json')) {
                $this->command->line('Imagem ja anexada; enviando para analise visual.');
            }

            return ['Analise a imagem anexada.', $pending];
        }

        if (! (bool) $this->command->option('json')) {
            $this->command->line('Verificando clipboard visual, aguarde...');
        }

        try {
            $attachment = $images->fromClipboard($workspace);
        } catch (\Throwable $exception) {
            if (! (bool) $this->command->option('json')) {
                $this->command->warn('Nenhuma imagem detectada no clipboard apos Enter vazio. Copie o screenshot novamente e aperte Enter.');
                $this->command->line($this->command->ui->dim('detalhe: '.$exception->getMessage()));
            }

            return [null, $pending];
        }

        if (! (bool) $this->command->option('json')) {
            $this->command->line('Imagem detectada; preparando anexo visual...');
        }

        $pending = $images->dedupe([$attachment]);

        if (! (bool) $this->command->option('json')) {
            $this->command->line('Imagem colada do clipboard e anexada automaticamente.');
            $this->printPendingImages($pending);
        }

        return ['Analise a imagem anexada.', $pending];
    }

    /**
     * @param  array<int,array<string,mixed>>  $pendingImages
     */
    public function interactivePromptLabel(?string $threadId, array $pendingImages): string
    {
        $base = $threadId ? 'atlas '.$this->command->shortId($threadId) : 'atlas';

        if ($pendingImages !== []) {
            return $base.' ['.$this->imagemTokens($pendingImages).']';
        }

        return $base;
    }

    /**
     * @param  array<int,array<string,mixed>>  $pendingImages
     * @return array{0:?string,1:array<int,array<string,mixed>>}
     */
    public function readInteractiveLine(string $label, AtlasImageAttachmentService $images, string $workspace, array $pendingImages): array
    {
        if (! $this->canReadRawTerminal()) {
            return [$this->command->ask($label), $pendingImages];
        }

        $composer = new ReplComposer;
        $composer->attachImages($pendingImages);

        $renderer = new ReplRenderer(
            $this->command->getOutput(),
            supportsAnsi: $this->command->getOutput()->isDecorated(),
        );
        $parser = new KeySequenceParser;
        $statusBar = new StatusBarFormatter;
        $sessionStartedAt = $this->replSessionStartedAt ??= new \DateTimeImmutable;
        $renderer->setStatusBarProducer(fn (ReplComposer $c): string => $statusBar->format(
            $this->command->replStatusBarProvider,
            $this->command->replStatusBarModel,
            $c,
            $sessionStartedAt,
        ));

        $stty = trim((string) shell_exec('stty -g 2>/dev/null'));
        $bracketedPaste = false;

        try {
            $this->command->getOutput()->write(KeyCodes::SEQ_BRACKETED_PASTE_ENABLE);
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
                    $this->command->getOutput()->write("\n");
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
                $this->command->getOutput()->write(KeyCodes::SEQ_BRACKETED_PASTE_DISABLE);
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

    public function shortenProviderForStatus(?string $provider): ?string
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
    public function shortenModelForStatus(?array $modelSelection): ?string
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
        return defined('STDIN') && function_exists('stream_isatty') && stream_isatty(STDIN) && ! (bool) $this->command->option('json');
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
    public function classifyBracketedPaste(string $payload): array
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
    public function attachInlineImagePaths(AtlasImageAttachmentService $images, string $workspace, string $input, array $pending): array
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

        if (! (bool) $this->command->option('json')) {
            $this->command->line(count($attachments) === 1
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

    public function shouldAutoAttachClipboardImage(string $input): bool
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
    public function imageCommandPaths(string $input): array
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
    public function normalizeImageAttachments(array $attachments): array
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
    public function inputWithImageSummary(string $input, array $attachments): string
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
    public function printPendingImages(array $attachments, bool $detailed = false): void
    {
        if ($attachments === []) {
            $this->command->line('Nenhuma imagem anexada para a proxima mensagem.');

            return;
        }

        if (! $detailed) {
            $this->command->line($this->imageAttachmentCompactLine($attachments));

            return;
        }

        $this->command->line('Imagens anexadas: '.count($attachments));
        foreach ($attachments as $index => $attachment) {
            $path = (string) ($attachment['path'] ?? '');
            $originalPath = (string) ($attachment['original_path'] ?? $path ?: '-');
            $this->command->line(sprintf('  %d. imagem anexada e pronta para envio visual', $index + 1));
            $this->command->line('     origem: '.(string) ($attachment['source'] ?? 'file'));
            $this->command->line('     detalhe: '.$this->imageAttachmentDetail($attachment));
            $this->command->line('     arquivo: '.$this->terminalFileLink($originalPath));
            $this->command->line('     abrir: '.$this->terminalFileLink($path !== '' ? $path : $originalPath));
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
        if (! $this->command->getOutput()->isDecorated()) {
            return $uri;
        }

        return "\033]8;;{$uri}\033\\{$path}\033]8;;\033\\";
    }

    /**
     * @param  array<int,array<string,mixed>>  $attachments
     */
    public function openImageAttachment(array $attachments, string $argument): void
    {
        if ($attachments === []) {
            $this->command->warn('Nenhuma imagem anexada ou enviada recentemente.');

            return;
        }

        $index = max(1, (int) ($argument !== '' ? $argument : 1)) - 1;
        $attachment = $attachments[$index] ?? null;
        if (! is_array($attachment)) {
            $this->command->warn('Imagem nao encontrada. Use /images para ver a lista.');

            return;
        }

        $path = (string) ($attachment['path'] ?? $attachment['original_path'] ?? '');
        if ($path === '' || ! File::isFile($path)) {
            $this->command->warn('Arquivo da imagem nao encontrado.');

            return;
        }

        $process = new Process(['open', $path]);
        $process->run();

        if ($process->isSuccessful()) {
            $this->command->line('Abrindo imagem '.($index + 1).': '.$path);

            return;
        }

        $this->command->error('Falha ao abrir imagem: '.trim($process->getErrorOutput() ?: $process->getOutput()));
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
            $this->command->line($this->command->ui->dim('     preview: terminal sem preview inline; anexo confirmado por metadados.'));

            return;
        }

        if ($path === '' || ! File::isFile($path) || File::size($path) > 5 * 1024 * 1024) {
            $this->command->line($this->command->ui->dim('     preview: arquivo indisponivel ou grande demais para render inline.'));

            return;
        }

        $contents = File::get($path);
        if ($contents === '') {
            $this->command->line($this->command->ui->dim('     preview: arquivo vazio.'));

            return;
        }

        $payload = base64_encode($contents);
        $this->command->getOutput()->write("\033]1337;File=inline=1;width=40;height=auto;preserveAspectRatio=1:{$payload}\a\n");
    }

    private function supportsInlineImagePreview(): bool
    {
        if (! $this->command->getOutput()->isDecorated()) {
            return false;
        }

        $termProgram = (string) ($_SERVER['TERM_PROGRAM'] ?? getenv('TERM_PROGRAM') ?: '');

        return in_array($termProgram, ['iTerm.app', 'WezTerm'], true);
    }

    public function providerSupportsCliImages(?string $provider): bool
    {
        // minimax_m27_cli is invocation-eligible but not on the CLI image path yet.
        if ($provider === null) {
            return true;
        }

        return ProviderCatalog::isInvocationProvider($provider) && $provider !== 'minimax_m27_cli';
    }

    /**
     * Garbage collection de anexos de imagem antigos.
     * Roda no maximo uma vez a cada 24h por workspace, controlado por touch file.
     */
    public function maybeCleanupStaleAttachments(AtlasImageAttachmentService $images): void
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
    public function imageProviderSwitchBlocked(?string $provider, array $pendingImages, array $queuedMessages): bool
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

    public function imageProviderBlocked(string $provider): int
    {
        $message = $this->imageProviderBlockedMessage($provider);
        if ((bool) $this->command->option('json')) {
            $this->command->line(json_encode(AtlasSecurity::redactArray([
                'ok' => false,
                'phase' => 'preflight',
                'error' => 'atlas_image_provider_unsupported',
                'provider' => $provider,
                'message' => $message,
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return AiChatCommand::FAILURE;
        }

        $this->command->error($message);

        return AiChatCommand::FAILURE;
    }

    public function imageProviderBlockedMessage(string $provider): string
    {
        return "Provider {$provider} nao suporta imagens neste runtime. Use /provider codex ou /provider gemini, ou remova o override manual.";
    }
}
