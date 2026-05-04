# Atlas dev REPL — paste-image Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cmd+V de imagem em qualquer terminal anexa ao composer e `atlas:cli:dev` interativo mostra `[imagem 1, imagem 2]` clicáveis (OSC 8) que abrem no Preview com Cmd+click / Ctrl+click.

**Architecture:** Tudo dentro de `app/Console/Commands/AiChatCommand.php`. Bracketed paste classifica payload (vazio = clipboard de imagem; path absoluto/`file://` = anexar por path; resto = texto). Label com OSC 8 hyperlinks. Polling de clipboard atualmente no código é removido (custo alto, conflito com gerenciadores de clipboard) e substituído pela classificação de paste.

**Tech Stack:** PHP 8.4 (Homebrew), Laravel 11, PHPUnit 11, Symfony Console. macOS (`pngpaste`/`osascript` já configurados). Sem dependência nova.

**Spec:** `atlas-server/docs/superpowers/specs/2026-05-04-atlas-dev-paste-image-design.md`

---

## Estado atual do código (pre-implementação)

`app/Console/Commands/AiChatCommand.php` já tem (commits "implementaçao" recentes):

- `dispatchRawKey()` (linha 1730) — switch de keystrokes extraído. Bracketed paste hoje **só joga payload no buffer** (linha 1767-1771), sem classificação.
- `autoAttachCurrentClipboardImage()` (linha 1818) + propriedade `seenClipboardImageHashes` (linha 111) + bloco de polling em `readInteractiveLine` (linha 1689-1695) — implementação anterior baseada em polling de 750ms. **Vai ser removido** pelo Task 1; a spec rejeita polling.
- `pasteClipboardImageIntoComposer()` (linha 1792) — anexa via `fromClipboard`, atualiza label, redesenha prompt. **Reutilizado** pela classificação.
- `attachInlineImagePaths()` (linha ~1822 do snapshot anterior) — já anexa quando user digita path. **Reutilizado** pela classificação.
- `labelWithImageCount()` (linha 1857) e `interactivePromptLabel()` (linha 1659) — formato atual `[img:N] Enter=analisar`. **Reescrito** pelo Task 4 pra `[imagem 1, imagem 2] Enter=analisar` com OSC 8.

Run `cd atlas-server && /opt/homebrew/bin/php artisan test --filter=AiChat 2>&1 | tail -20` antes de começar pra ter baseline verde.

---

## File Structure

| Caminho | Responsabilidade | Operação |
|---|---|---|
| `atlas-server/app/Console/Commands/AiChatCommand.php` | REPL `atlas:ai:chat`. Hospeda o raw-mode loop, dispatcher de keystrokes, label do prompt, helpers de anexo. | Modify |
| `atlas-server/tests/Feature/AiChatCommandPasteImageTest.php` | Testes unit/feature dos helpers privados via `ReflectionMethod` (mesmo padrão de `AtlasCliDevCommandTest.php`). | Create |

Nada novo de service. Nada novo de migration. Nada novo de classe. `wrapOsc8()` é um método privado dentro do próprio command.

---

## Task 1: Remover polling de clipboard

**Goal:** Tirar o `autoAttachCurrentClipboardImage` e infraestrutura de polling do raw-mode loop. A spec rejeita polling (~4×/s shellout, conflito com gerenciadores de clipboard, macOS-only por design ruim).

**Files:**
- Modify: `atlas-server/app/Console/Commands/AiChatCommand.php` linhas 111, 1689-1695, 1818-1855

- [ ] **Step 1.1: Baseline tests verdes**

```bash
cd atlas-server && /opt/homebrew/bin/php artisan test --filter=AiChatCommand 2>&1 | tail -20
```

Expected: tudo PASS. Se algum teste de `autoAttachCurrentClipboardImage` existir hoje, anota o nome — vai precisar deletar junto.

- [ ] **Step 1.2: Deletar a propriedade `seenClipboardImageHashes`**

Em `AiChatCommand.php` linha 111, remover:

```php
    private array $seenClipboardImageHashes = [];
```

- [ ] **Step 1.3: Remover o bloco de polling do `readInteractiveLine`**

Em `AiChatCommand.php`, no método `readInteractiveLine`, trocar o trecho que tem `lastClipboardProbe` (linhas 1689-1695):

ANTES:
```php
            $this->renderRawPrompt($label, $buffer);
            $lastClipboardProbe = 0.0;

            while (true) {
                if (microtime(true) - $lastClipboardProbe >= 0.75) {
                    $lastClipboardProbe = microtime(true);
                    [$label, $pendingImages] = $this->autoAttachCurrentClipboardImage($images, $workspace, $pendingImages, $label, $buffer);
                }

                $char = fread(STDIN, 1);
```

DEPOIS:
```php
            $this->renderRawPrompt($label, $buffer);

            while (true) {
                $char = fread(STDIN, 1);
```

- [ ] **Step 1.4: Deletar o método `autoAttachCurrentClipboardImage`**

Em `AiChatCommand.php`, apagar todo o método entre `private function autoAttachCurrentClipboardImage(...)` (linha 1818) e o `}` final (linha ~1855), incluindo o docblock acima.

- [ ] **Step 1.5: Verificar que nada mais referencia os símbolos removidos**

```bash
cd atlas-server && grep -n "autoAttachCurrentClipboardImage\|seenClipboardImageHashes" app/Console/Commands/AiChatCommand.php
```

Expected: zero saída.

- [ ] **Step 1.6: Rodar testes**

```bash
cd atlas-server && /opt/homebrew/bin/php artisan test --filter=AiChat 2>&1 | tail -20
```

Expected: tudo PASS. Se algum teste especificamente do polling falhar, deletar o teste — funcionalidade saiu por design.

- [ ] **Step 1.7: Commit**

```bash
cd atlas-server && git add app/Console/Commands/AiChatCommand.php
git commit -m "refactor(cli): remove clipboard polling from chat REPL

Polling shells out 4x/sec for the entire interactive session, conflicts
with clipboard managers, and is macOS-only by accident. Bracketed-paste
classification (next commits) replaces it with event-driven detection."
```

---

## Task 2: Bracketed paste vazio dispara captura de clipboard

**Goal:** Quando o terminal manda `\033[200~\033[201~` com payload vazio (Cmd+V em Ghostty/Terminal.app quando clipboard só tem imagem), classificar como paste de imagem e chamar `pasteClipboardImageIntoComposer`.

**Files:**
- Create: `atlas-server/tests/Feature/AiChatCommandPasteImageTest.php`
- Modify: `atlas-server/app/Console/Commands/AiChatCommand.php` (`dispatchRawKey`, novo helper `classifyBracketedPaste`)

- [ ] **Step 2.1: Criar arquivo de teste com primeiro caso (vai falhar)**

Create `atlas-server/tests/Feature/AiChatCommandPasteImageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Console\Commands\AiChatCommand;
use App\Services\Ai\Cli\AtlasImageAttachmentService;
use Illuminate\Support\Facades\File;
use Mockery;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AiChatCommandPasteImageTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir().'/atlas-chat-paste-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);
        Mockery::close();
        parent::tearDown();
    }

    public function test_classify_bracketed_paste_empty_payload_returns_clipboard_image_kind(): void
    {
        $command = new AiChatCommand();
        $command->setLaravel(app());
        $method = new ReflectionMethod($command, 'classifyBracketedPaste');
        $method->setAccessible(true);

        $result = $method->invoke($command, '', $this->workspace);

        $this->assertSame('clipboard_image', $result['kind']);
    }

    public function test_classify_bracketed_paste_whitespace_payload_returns_clipboard_image_kind(): void
    {
        $command = new AiChatCommand();
        $command->setLaravel(app());
        $method = new ReflectionMethod($command, 'classifyBracketedPaste');
        $method->setAccessible(true);

        $result = $method->invoke($command, "\t  \n", $this->workspace);

        $this->assertSame('clipboard_image', $result['kind']);
    }
}
```

- [ ] **Step 2.2: Rodar teste pra confirmar falha**

```bash
cd atlas-server && /opt/homebrew/bin/php artisan test --filter=AiChatCommandPasteImageTest 2>&1 | tail -15
```

Expected: FAIL — `ReflectionException: Method classifyBracketedPaste does not exist`.

- [ ] **Step 2.3: Adicionar `classifyBracketedPaste` ao command**

Em `AiChatCommand.php`, adicionar logo abaixo do método `pasteClipboardImageIntoComposer` (após linha ~1812):

```php
    /**
     * @return array{kind:'clipboard_image'|'image_path'|'text', path?:string}
     */
    private function classifyBracketedPaste(string $payload, string $workspace): array
    {
        $trimmed = trim($payload);

        if ($trimmed === '') {
            return ['kind' => 'clipboard_image'];
        }

        return ['kind' => 'text'];
    }
```

- [ ] **Step 2.4: Rodar teste — passa**

```bash
cd atlas-server && /opt/homebrew/bin/php artisan test --filter=AiChatCommandPasteImageTest 2>&1 | tail -10
```

Expected: 2 PASS.

- [ ] **Step 2.5: Teste do dispatch — bracketed paste vazio chama `pasteClipboardImageIntoComposer`**

Adicionar ao mesmo arquivo de teste, dentro da classe:

```php
    public function test_dispatch_bracketed_paste_empty_attaches_clipboard_image(): void
    {
        $imageFile = $this->workspace.'/clip.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=');
        File::put($imageFile, (string) $png);

        $images = Mockery::mock(AtlasImageAttachmentService::class);
        $images->shouldReceive('fromClipboard')
            ->once()
            ->with($this->workspace)
            ->andReturn([
                'path' => $imageFile,
                'source' => 'clipboard',
                'original_path' => 'clipboard',
                'mime_type' => 'image/png',
                'bytes' => filesize($imageFile),
                'sha256' => hash_file('sha256', $imageFile),
            ]);
        $images->shouldReceive('dedupe')->andReturnUsing(fn ($a) => $a);

        $command = new AiChatCommand();
        $command->setLaravel(app());
        $command->setOutput(new \Illuminate\Console\OutputStyle(new ArrayInput([]), new BufferedOutput()));
        $reflectIn = new \ReflectionProperty($command, 'input');
        $reflectIn->setAccessible(true);
        $reflectIn->setValue($command, new ArrayInput([], $command->getDefinition()));

        $applyPaste = new ReflectionMethod($command, 'applyBracketedPasteClassification');
        $applyPaste->setAccessible(true);

        $buffer = '';
        $label = 'atlas';
        $pending = [];

        $applyPaste->invokeArgs($command, [
            ['kind' => 'clipboard_image'],
            $images,
            $this->workspace,
            &$buffer,
            &$label,
            &$pending,
        ]);

        $this->assertCount(1, $pending);
        $this->assertSame($imageFile, $pending[0]['path']);
        $this->assertStringContainsString('imagem 1', $label);
    }
```

- [ ] **Step 2.6: Rodar teste — falha (`applyBracketedPasteClassification` não existe ainda)**

```bash
cd atlas-server && /opt/homebrew/bin/php artisan test --filter=AiChatCommandPasteImageTest 2>&1 | tail -10
```

Expected: FAIL — `Method applyBracketedPasteClassification does not exist`.

- [ ] **Step 2.7: Implementar `applyBracketedPasteClassification` e plumb no dispatch**

Em `AiChatCommand.php`, adicionar após `classifyBracketedPaste`:

```php
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

        $buffer .= $classification['path'] ?? '';
        $this->output->write($classification['path'] ?? '');
    }
```

(Note: o branch `image_path` chega no Task 3. Por ora, qualquer `kind != clipboard_image` cai no buffer-fallback. O texto comum vai pelo Step 2.8.)

E modificar `dispatchRawKey` (linha ~1765-1774) — substituir o bloco de bracketed paste:

ANTES:
```php
        if ($char === "\033") {
            $sequence = $char.$this->readAvailableTerminalSequence();
            if ($sequence === "\033[200~") {
                $paste = $this->readBracketedPastePayload();
                $buffer .= $paste;
                $this->output->write($paste);
            }

            return 'continue';
        }
```

DEPOIS:
```php
        if ($char === "\033") {
            $sequence = $char.$this->readAvailableTerminalSequence();
            if ($sequence === "\033[200~") {
                $paste = $this->readBracketedPastePayload();
                $classification = $this->classifyBracketedPaste($paste, $workspace);
                if ($classification['kind'] === 'text') {
                    $buffer .= $paste;
                    $this->output->write($paste);
                } else {
                    $this->applyBracketedPasteClassification($classification, $images, $workspace, $buffer, $label, $pendingImages);
                }
            }

            return 'continue';
        }
```

- [ ] **Step 2.8: Teste do path "texto comum não anexa"**

Adicionar ao arquivo de teste:

```php
    public function test_classify_bracketed_paste_text_payload_is_text(): void
    {
        $command = new AiChatCommand();
        $command->setLaravel(app());
        $method = new ReflectionMethod($command, 'classifyBracketedPaste');
        $method->setAccessible(true);

        $result = $method->invoke($command, 'git status', $this->workspace);

        $this->assertSame('text', $result['kind']);
    }
```

- [ ] **Step 2.9: Rodar todos os testes — passam**

```bash
cd atlas-server && /opt/homebrew/bin/php artisan test --filter=AiChatCommandPasteImageTest 2>&1 | tail -15
```

Expected: 4 PASS.

- [ ] **Step 2.10: Commit**

```bash
cd atlas-server && git add app/Console/Commands/AiChatCommand.php tests/Feature/AiChatCommandPasteImageTest.php
git commit -m "feat(cli): bracketed-paste empty payload attaches clipboard image

When Cmd+V hits the chat REPL with an image-only clipboard, the
terminal sends an empty bracketed-paste sequence. Classify it as
clipboard_image and reuse the existing pasteClipboardImageIntoComposer
flow, so users no longer need /paste-image or Ctrl+V."
```

---

## Task 3: Bracketed paste com path de imagem anexa por path

**Goal:** Quando o terminal cola um path absoluto ou `file://` URL apontando pra imagem (caso iTerm2 com screenshot temporário), anexar via `fromPaths`.

**Files:**
- Modify: `atlas-server/app/Console/Commands/AiChatCommand.php` (`classifyBracketedPaste`, `applyBracketedPasteClassification`)
- Modify: `atlas-server/tests/Feature/AiChatCommandPasteImageTest.php`

- [ ] **Step 3.1: Teste — payload com path absoluto de PNG vira `image_path`**

Adicionar ao arquivo de teste:

```php
    public function test_classify_bracketed_paste_absolute_path_to_png_is_image_path(): void
    {
        $imageFile = $this->workspace.'/screenshot.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=');
        File::put($imageFile, (string) $png);

        $command = new AiChatCommand();
        $command->setLaravel(app());
        $method = new ReflectionMethod($command, 'classifyBracketedPaste');
        $method->setAccessible(true);

        $result = $method->invoke($command, $imageFile, $this->workspace);

        $this->assertSame('image_path', $result['kind']);
        $this->assertSame($imageFile, $result['path']);
    }

    public function test_classify_bracketed_paste_file_url_is_image_path(): void
    {
        $imageFile = $this->workspace.'/screenshot 2.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=');
        File::put($imageFile, (string) $png);

        $command = new AiChatCommand();
        $command->setLaravel(app());
        $method = new ReflectionMethod($command, 'classifyBracketedPaste');
        $method->setAccessible(true);

        $url = 'file://'.rawurlencode($imageFile);
        $url = str_replace('%2F', '/', $url);
        $result = $method->invoke($command, $url, $this->workspace);

        $this->assertSame('image_path', $result['kind']);
        $this->assertSame($imageFile, $result['path']);
    }
```

- [ ] **Step 3.2: Rodar — falha (classifier ainda retorna text)**

```bash
cd atlas-server && /opt/homebrew/bin/php artisan test --filter=AiChatCommandPasteImageTest 2>&1 | tail -15
```

Expected: 2 FAIL — `Failed asserting that 'text' is identical to 'image_path'`.

- [ ] **Step 3.3: Estender `classifyBracketedPaste`**

Substituir o corpo de `classifyBracketedPaste` em `AiChatCommand.php` por:

```php
    /**
     * @return array{kind:'clipboard_image'|'image_path'|'text', path?:string}
     */
    private function classifyBracketedPaste(string $payload, string $workspace): array
    {
        $trimmed = trim($payload);

        if ($trimmed === '') {
            return ['kind' => 'clipboard_image'];
        }

        if (str_contains($trimmed, "\n")) {
            return ['kind' => 'text'];
        }

        $candidate = $trimmed;
        if (str_starts_with($candidate, 'file://')) {
            $candidate = rawurldecode(substr($candidate, strlen('file://')));
        }

        if (! str_starts_with($candidate, '/')) {
            return ['kind' => 'text'];
        }

        if (! is_file($candidate)) {
            return ['kind' => 'text'];
        }

        $imageInfo = @getimagesize($candidate);
        $mime = is_array($imageInfo) && is_string($imageInfo['mime'] ?? null) ? $imageInfo['mime'] : null;
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            return ['kind' => 'text'];
        }

        return ['kind' => 'image_path', 'path' => $candidate];
    }
```

- [ ] **Step 3.4: Estender `applyBracketedPasteClassification` para cobrir `image_path`**

Substituir o corpo do método:

```php
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
            $this->line('Imagem detectada no paste; anexando '.basename($classification['path']).'...');

            try {
                $attachments = $images->fromPaths([$classification['path']], $workspace);
            } catch (\Throwable $exception) {
                $this->warn('Nao consegui anexar imagem do paste: '.$exception->getMessage());
                $this->renderRawPrompt($label, $buffer);

                return;
            }

            $pendingImages = $this->mergeImageAttachments($pendingImages, $attachments, $images);
            $this->printPendingImages($pendingImages);
            $label = $this->labelWithImageCount($label, count($pendingImages));
            $this->renderRawPrompt($label, $buffer);

            return;
        }
    }
```

- [ ] **Step 3.5: Rodar testes**

```bash
cd atlas-server && /opt/homebrew/bin/php artisan test --filter=AiChatCommandPasteImageTest 2>&1 | tail -15
```

Expected: todos PASS.

- [ ] **Step 3.6: Commit**

```bash
cd atlas-server && git add app/Console/Commands/AiChatCommand.php tests/Feature/AiChatCommandPasteImageTest.php
git commit -m "feat(cli): bracketed-paste with image path attaches by path

iTerm2 with an image clipboard pastes the temp screenshot path; detect
absolute paths and file:// URLs that resolve to PNG/JPEG/WEBP/GIF and
route them through fromPaths() so the prompt picks them up automatically."
```

---

## Task 4: Label `[imagem 1, imagem 2]` com OSC 8

**Goal:** Trocar o formato `[img:N] Enter=analisar` por `[imagem 1, imagem 2] Enter=analisar` onde cada token é OSC 8 hyperlink pro `file://path` do anexo. Cmd+click no terminal abre direto no Preview.

**Files:**
- Modify: `atlas-server/app/Console/Commands/AiChatCommand.php` (`interactivePromptLabel`, `labelWithImageCount`, `wrapOsc8` novo)
- Modify: `atlas-server/tests/Feature/AiChatCommandPasteImageTest.php`

- [ ] **Step 4.1: Teste — `interactivePromptLabel` com 2 imagens emite OSC 8 correto**

Adicionar ao arquivo de teste:

```php
    public function test_interactive_prompt_label_renders_osc8_hyperlinks_for_each_image(): void
    {
        $command = new AiChatCommand();
        $command->setLaravel(app());
        $method = new ReflectionMethod($command, 'interactivePromptLabel');
        $method->setAccessible(true);

        $pending = [
            ['path' => '/tmp/atlas/clip-a.png'],
            ['path' => '/tmp/atlas/clip-b.png'],
        ];

        $label = $method->invoke($command, '0123456789ab', $pending);

        $this->assertStringContainsString('[', $label);
        $this->assertStringContainsString(']', $label);
        $this->assertStringContainsString('Enter=analisar', $label);
        $this->assertStringContainsString("\033]8;;file:///tmp/atlas/clip-a.png\033\\imagem 1\033]8;;\033\\", $label);
        $this->assertStringContainsString("\033]8;;file:///tmp/atlas/clip-b.png\033\\imagem 2\033]8;;\033\\", $label);
        $this->assertStringNotContainsString('[img:', $label);
    }

    public function test_interactive_prompt_label_without_images_omits_brackets(): void
    {
        $command = new AiChatCommand();
        $command->setLaravel(app());
        $method = new ReflectionMethod($command, 'interactivePromptLabel');
        $method->setAccessible(true);

        $label = $method->invoke($command, '0123456789ab', []);

        $this->assertStringNotContainsString('[', $label);
        $this->assertStringNotContainsString('imagem', $label);
    }

    public function test_label_with_image_count_replaces_existing_image_segment(): void
    {
        $command = new AiChatCommand();
        $command->setLaravel(app());
        $method = new ReflectionMethod($command, 'labelWithImageCount');
        $method->setAccessible(true);

        $pending = [
            ['path' => '/tmp/a.png'],
            ['path' => '/tmp/b.png'],
            ['path' => '/tmp/c.png'],
        ];

        $first = $method->invoke($command, 'atlas abcd1234 [imagem 1] Enter=analisar', $pending);
        $this->assertStringContainsString('imagem 1', $first);
        $this->assertStringContainsString('imagem 2', $first);
        $this->assertStringContainsString('imagem 3', $first);
        $this->assertStringNotContainsString('[imagem 1] Enter=analisar', $first);

        $second = $method->invoke($command, 'atlas abcd1234', $pending);
        $this->assertStringContainsString('imagem 3', $second);
    }
```

- [ ] **Step 4.2: Rodar — falha**

```bash
cd atlas-server && /opt/homebrew/bin/php artisan test --filter=AiChatCommandPasteImageTest 2>&1 | tail -25
```

Expected: 3 FAIL — `interactivePromptLabel` ainda retorna `[img:N]`, sem OSC 8, e `labelWithImageCount` recebe `int` em vez de `array`.

- [ ] **Step 4.3: Adicionar helper `wrapOsc8`**

Em `AiChatCommand.php`, adicionar após `labelWithImageCount` (próximo a linha 1862):

```php
    private function wrapOsc8(string $text, string $url): string
    {
        return "\033]8;;".$url."\033\\".$text."\033]8;;\033\\";
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
            $tokens[] = $this->wrapOsc8($label, 'file://'.$path);
        }

        return implode(', ', $tokens);
    }
```

- [ ] **Step 4.4: Reescrever `interactivePromptLabel`**

Substituir corpo (linhas 1659-1668):

```php
    /**
     * @param  array<int,array<string,mixed>>  $pendingImages
     */
    private function interactivePromptLabel(?string $threadId, array $pendingImages): string
    {
        $base = $threadId ? 'atlas '.$this->shortId($threadId) : 'atlas';

        if ($pendingImages !== []) {
            return $base.' ['.$this->imagemTokens($pendingImages).'] Enter=analisar';
        }

        return $base;
    }
```

- [ ] **Step 4.5: Reescrever `labelWithImageCount` para receber array de pending**

Substituir assinatura e corpo (linhas 1857-1862):

```php
    /**
     * @param  array<int,array<string,mixed>>  $pendingImages
     */
    private function labelWithImageCount(string $label, array $pendingImages): string
    {
        $stripped = preg_replace('/\s+\[(?:img:\d+|imagem [^\]]*)\]\s+Enter=analisar$/', '', $label) ?: $label;
        if ($pendingImages === []) {
            return $stripped;
        }

        return $stripped.' ['.$this->imagemTokens($pendingImages).'] Enter=analisar';
    }
```

- [ ] **Step 4.6: Atualizar callers de `labelWithImageCount`**

Buscar callers:

```bash
cd atlas-server && grep -n "labelWithImageCount(" app/Console/Commands/AiChatCommand.php
```

Cada caller hoje passa `count($pendingImages)`. Trocar pra passar `$pendingImages` direto. Em `pasteClipboardImageIntoComposer` (linha ~1808):

ANTES:
```php
        $label = $this->labelWithImageCount($label, count($pendingImages));
```

DEPOIS:
```php
        $label = $this->labelWithImageCount($label, $pendingImages);
```

E em `applyBracketedPasteClassification` no branch `image_path` (do Task 3):

ANTES:
```php
            $label = $this->labelWithImageCount($label, count($pendingImages));
```

DEPOIS:
```php
            $label = $this->labelWithImageCount($label, $pendingImages);
```

- [ ] **Step 4.7: Atualizar texto de ajuda do Ctrl+V**

Em `AiChatCommand.php` linha 1393, atualizar o tooltip:

ANTES:
```php
                    ['Ctrl+V', 'cola imagem do clipboard no composer e mostra [img:N] antes de enviar'],
```

DEPOIS:
```php
                    ['Ctrl+V', 'cola imagem do clipboard no composer e mostra [imagem 1, imagem 2] antes de enviar'],
```

- [ ] **Step 4.8: Confirmar que `[img:` não aparece mais nos paths de UI do prompt**

```bash
cd atlas-server && grep -n "\[img:" app/Console/Commands/AiChatCommand.php
```

Expected: a única ocorrência sobrando deve ser linha ~2208 dentro de `imageAttachmentCompactLine` que é texto de log de status (`'[img:N] pronta para enviar'`) — esse é separado do prompt, OK manter.

- [ ] **Step 4.9: Rodar testes**

```bash
cd atlas-server && /opt/homebrew/bin/php artisan test --filter=AiChatCommandPasteImageTest 2>&1 | tail -25
```

Expected: todos PASS.

- [ ] **Step 4.10: Smoke do command inteiro (suite mais larga)**

```bash
cd atlas-server && /opt/homebrew/bin/php artisan test --filter=AiChat 2>&1 | tail -15
```

Expected: tudo PASS.

- [ ] **Step 4.11: Commit**

```bash
cd atlas-server && git add app/Console/Commands/AiChatCommand.php tests/Feature/AiChatCommandPasteImageTest.php
git commit -m "feat(cli): chat REPL prompt shows [imagem N] OSC 8 hyperlinks

Replace [img:N] with [imagem 1, imagem 2, ...] where each token wraps
the attached file path in an OSC 8 hyperlink. Cmd+click / Ctrl+click
in iTerm2/Ghostty/WezTerm/Kitty opens the attachment in Preview.app
without typing /open-image."
```

---

## Task 5: Validação manual em três terminais

**Goal:** Confirmar funcional em Ghostty, iTerm2, e Apple Terminal.app antes de declarar pronto. Document outcomes — se Terminal.app não suportar OSC 8 (esperado), fica registrado.

**Files:**
- Append: `atlas-server/docs/superpowers/specs/2026-05-04-atlas-dev-paste-image-design.md` (seção "Validação manual" no fim)

- [ ] **Step 5.1: Iniciar dev interativo**

```bash
cd /Users/vitorepf/Develop/atlas/atlas-server
./bin/atlas cli:dev --workspace=/Users/vitorepf/Develop/atlas
```

(ou `atlas dev` se o atalho global aponta pro mesmo binário)

Confirmar que aparece o prompt `atlas <id>` sem `[img:N]`.

- [ ] **Step 5.2: Em Ghostty — paste de screenshot via Cmd+V**

1. Cmd+Shift+4, screenshot de qualquer região (vai pro clipboard)
2. Foco no terminal, Cmd+V
3. Verificar prompt vira `atlas <id> [imagem 1] Enter=analisar`
4. Cmd+click em "imagem 1" → Preview.app abre o PNG

- [ ] **Step 5.3: Em Ghostty — segundo paste mostra `[imagem 1, imagem 2]`**

1. Cmd+Shift+4 outra vez (diferente região)
2. Cmd+V
3. Verificar prompt vira `atlas <id> [imagem 1, imagem 2] Enter=analisar`
4. Cmd+click em "imagem 2" → abre o segundo PNG (não o primeiro)

- [ ] **Step 5.4: Em Ghostty — Ctrl+V continua funcionando**

1. Cmd+Shift+4 outra vez
2. Ctrl+V (não Cmd+V)
3. Verificar adiciona `imagem 3`

- [ ] **Step 5.5: Em Ghostty — `/clear-images` e re-paste**

1. Digitar `/clear-images` Enter
2. Verificar prompt volta a `atlas <id>` (sem `[`)
3. Cmd+V de novo → `[imagem 1]`

- [ ] **Step 5.6: Em iTerm2 — paste de imagem direto e via filename**

1. Mesma sequência do 5.2 e 5.3
2. Adicional: copiar um arquivo de imagem do Finder (Cmd+C no Finder), Cmd+V no terminal — verifica que detecta como `image_path` e anexa

- [ ] **Step 5.7: Em Terminal.app — paste detecta, mas hyperlink pode não clicar**

1. Mesma sequência do 5.2
2. Verificar `[imagem 1] Enter=analisar` aparece (texto ok)
3. Tentar Cmd+click — provavelmente não vai abrir (Terminal.app não suporta OSC 8 confiável)
4. Confirmar que `/open-image 1` ainda abre no Preview

- [ ] **Step 5.8: Documentar resultados**

Append em `atlas-server/docs/superpowers/specs/2026-05-04-atlas-dev-paste-image-design.md`:

```markdown

## Validação manual (2026-05-04)

| Terminal | Cmd+V cola | `[imagem N]` aparece | Cmd+click abre | `/open-image` fallback |
|---|---|---|---|---|
| Ghostty | <ok/falhou> | <ok/falhou> | <ok/falhou> | <ok/falhou> |
| iTerm2 | <ok/falhou> | <ok/falhou> | <ok/falhou> | <ok/falhou> |
| Terminal.app | <ok/falhou> | <ok/falhou> | <ok/falhou esperado> | <ok/falhou> |
```

Preencher os <ok/falhou> com os resultados reais.

- [ ] **Step 5.9: Commit final**

```bash
cd atlas-server && git add docs/superpowers/specs/2026-05-04-atlas-dev-paste-image-design.md
git commit -m "docs(cli): record manual paste-image validation across terminals"
```

---

## Self-Review

**Spec coverage:**
- Cmd+V em qualquer terminal anexa → Task 2 (clipboard_image) + Task 3 (image_path) ✅
- Prompt `[imagem 1, imagem 2]` → Task 4 ✅
- Cmd+click/Ctrl+click abre → Task 4 (OSC 8) + Task 5 (validação) ✅
- Polling rejeitado → Task 1 ✅
- Refator pra testabilidade → `dispatchRawKey` já extraído; novos helpers são privados puros, todos testados via Reflection ✅
- Slash commands existentes intactos → nenhum task altera `/paste-image`, `/image`, `/images`, `/open-image`, `/clear-images` ✅

**Placeholder scan:** Nenhum TBD/TODO/"adicionar tratamento adequado". Cada step tem comando ou bloco de código completo.

**Type consistency:**
- `classifyBracketedPaste` declarada com retorno `{kind, path?}` no Task 2 e estendida no Task 3 mantendo o shape.
- `applyBracketedPasteClassification` recebe a mesma estrutura.
- `labelWithImageCount` muda de `(string,int)` pra `(string,array)` no Task 4 — Task 4 explicitamente lista os callers a atualizar (`pasteClipboardImageIntoComposer`, `applyBracketedPasteClassification`).
- `imagemTokens` consistente com `interactivePromptLabel` e `labelWithImageCount`.
- `wrapOsc8` único helper de baixo nível, usado só por `imagemTokens`.
