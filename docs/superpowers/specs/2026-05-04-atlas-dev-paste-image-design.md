> Cleanup status: superseded_source_material.
> Canonical replacement: docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md; docs/paste-image-setup.md; docs/atlas-cli-final-product.md.
> Cleanup note: Historical design source. Preserve for audit, but do not use as live architecture without checking the canonical replacement.

# Atlas dev REPL — paste-image clicável

Spec para deixar `atlas:cli:dev` interativo aceitar Cmd+V de imagem em qualquer terminal e mostrar os anexos como `[imagem 1, imagem 2]` clicáveis.

## Contexto

`atlas:cli:dev` sem argumentos delega pro REPL `atlas:ai:chat --dev --new-thread --cockpit` em TTY. Esse REPL (`AiChatCommand`, arquivo `app/Console/Commands/AiChatCommand.php`) já tem plumbing pra anexar imagens via clipboard:

- `AtlasImageAttachmentService::fromClipboard()` captura via `pngpaste` ou AppleScript
- `pasteClipboardImageIntoComposer()` (linha 1747) faz o ciclo anexar → atualizar label → redesenhar prompt
- `attachInlineImagePaths()` (linha 1822) anexa por path
- `interactivePromptLabel()` (linha 1633) e `labelWithImageCount()` (linha 1769) montam o label
- `openImageAttachment()` (linha 2122) abre via `open` no macOS
- Slash commands `/paste-image`, `/image <path>`, `/images`, `/open-image`, `/clear-images`

Hoje só `Ctrl+V` (`\x16`) dispara captura. Cmd+V no macOS Terminal/Ghostty/iTerm2 manda bracketed paste com payload vazio (clipboard só tem imagem) ou com path do screenshot temporário (iTerm2). Nenhum dos dois dispara nada. Label mostra `[img:N] Enter=analisar` mas não tem affordance de abrir — usuário precisa lembrar de digitar `/open-image N`.

## Objetivo

1. Cmd+V de imagem anexa sem precisar de slash command, em Ghostty/iTerm2/Terminal.app.
2. Prompt mostra `[imagem 1, imagem 2]` em vez de `[img:N]`.
3. Cada `imagem N` é hyperlink OSC 8 apontando pro arquivo, então Cmd+click ou Ctrl+click no terminal abre direto no app default (Preview pra PNG/JPG).

Não-objetivos:

- Não vai ter keybinding nova de teclado pra abrir imagem (`Ctrl+O`/Tab Tab descartados — clicar no link é a affordance).
- Não vai polling de clipboard.
- Não vai mexer em providers, gateway, persistence, governance — só na camada de input/render do REPL.

## Arquitetura

Tudo dentro de `AiChatCommand`. Sem service novo, sem migration, sem mudança de schema. Três frentes:

### 1. Detecção de Cmd+V via bracketed paste

No `readInteractiveLine()` (linha 1648), o branch `\033` que hoje chama `readBracketedPastePayload()` recebe o payload e só faz `$buffer .= $paste`. Vira um classificador:

- **Payload vazio ou só whitespace** → assume "clipboard só tem imagem". Chama `pasteClipboardImageIntoComposer()` (mesmo caminho do Ctrl+V atual). Se `fromClipboard()` falhar com "clipboard não tem imagem", silenciosamente trata como paste vazio normal e segue.
- **Payload de uma única linha que casa com path absoluto ou `file://` URL** apontando pra arquivo existente com MIME `image/png|jpeg|webp|gif` → trata como `/image <path>`, chama `attachInlineImagePaths()` ou `images->fromPaths([$path])` direto. Cobre iTerm2 que cola filename do screenshot temporário.
- **Qualquer outro payload** → mantém comportamento atual: vai pro `$buffer` como texto.

`Ctrl+V` (`\x16`) explícito continua funcionando intacto como atalho.

### 2. Label `[imagem 1, imagem 2]` com OSC 8

`interactivePromptLabel()` e `labelWithImageCount()` reescritos pra emitir:

```
atlas <id> [<OSC8 file://path1>imagem 1<end>, <OSC8 file://path2>imagem 2<end>] Enter=analisar
```

onde `<OSC8 url>` = `\033]8;;url\033\\` e `<end>` = `\033]8;;\033\\`.

URL é `file://` + path absoluto do attachment (já presente como `path` no array de anexo retornado por `AtlasImageAttachmentService::fromPath()`).

`renderRawPrompt()` continua redesenhando a linha inteira após cada mutação de `$pendingImages`, então o label se mantém consistente quando o user cola, dá `/clear-images`, manda mensagem (limpa pendings), etc.

Clicabilidade confirmada em iTerm2, Ghostty, WezTerm, Kitty. Apple Terminal.app não suporta OSC 8 de forma confiável até o macOS atual; sequência é ignorada e a string `[imagem 1, imagem 2]` aparece sem clicabilidade — slash command `/open-image N` é o fallback documentado pra esse caso.

### 3. Refator pra testabilidade

Extrair o switch de keystrokes do `readInteractiveLine` pra um método privado:

```php
private function dispatchRawKey(string $char, RawComposerState $state): RawKeyOutcome
```

`RawComposerState` (DTO interno, classe privada ou array tipado) carrega `buffer`, `pendingImages`, `label`. `RawKeyOutcome` é enum com `CONTINUE`, `SUBMIT`, `EOF`, `INTERRUPT` (espelhando os return points atuais do loop).

Loop principal vira ~15 linhas:

```php
while (true) {
    $char = fread(STDIN, 1);
    $outcome = $this->dispatchRawKey($char, $state);
    match ($outcome) {
        Submit  => return [$state->buffer, $state->pendingImages],
        Eof     => throw new ConsoleRuntimeException('EOF'),
        Interrupt => throw new ConsoleRuntimeException('Interrupted'),
        Continue => null,
    };
}
```

Isso permite teste unitário injetando uma sequência de chars e validando outcome+state, sem precisar fingir STDIN.

## Mudanças por arquivo

- `app/Console/Commands/AiChatCommand.php`
  - `readInteractiveLine()`: extração do switch + branch de bracketed paste reclassificado
  - `interactivePromptLabel()`, `labelWithImageCount()`: novo formato com OSC 8
  - `dispatchRawKey()`: método privado novo
  - `RawComposerState`, `RawKeyOutcome`: tipos privados (classe interna ou DTO)
  - Helper `bracketedPasteClassification(string $payload, string $workspace): array{kind: 'empty'|'image_path'|'text', path?: string}`
  - Helper `wrapOsc8(string $text, string $url): string`
- `tests/Feature/AiChatCommandPasteImageTest.php` (novo)
  - Cobre os 4 cenários da seção 3 + render do label com OSC 8

## Plano de testes

Testes unitários no novo arquivo:

1. **Ctrl+V dispara captura de clipboard** — mock `AtlasImageAttachmentService::fromClipboard` retornando attachment fake, dispatcher recebe `\x16`, state termina com 1 pending.
2. **Bracketed paste vazio dispara captura de clipboard** — sequência `\033[200~\033[201~`, mock `fromClipboard`, idem.
3. **Bracketed paste com path de PNG válido anexa por path** — mock `fromPaths` recebendo path correto, state termina com 1 pending.
4. **Bracketed paste com texto comum vai pro buffer** — payload `"git status"` termina como `buffer="git status"`, sem chamar nenhum método de imagem.
5. **Label com 2 imagens emite OSC 8 correto** — `interactivePromptLabel(threadId, [img1, img2])` retorna string contendo `\033]8;;file:///abs/path1\033\\imagem 1\033]8;;\033\\` e idem pro 2.
6. **Label sem imagem retorna formato anterior limpo** — `atlas <id>` sem `[...]`.
7. **Bracketed paste vazio sem imagem no clipboard cai pra paste vazio** — mock `fromClipboard` lança, state mantém buffer/pending originais, sem erro propagado.

Verificação manual em três terminais antes de declarar completo: Ghostty, iTerm2, Terminal.app — colar screenshot, conferir `[imagem 1]` aparece, Cmd+click abre Preview.

## Riscos e mitigações

- **OSC 8 quebra cursor positioning em raw-mode**: hyperlinks são zero-width nos terminais que suportam, e ignorados nos que não suportam. Risco baixo, mas validar manualmente que o cursor após colar fica no lugar certo.
- **Payload de bracketed paste com path que tem espaço/aspas**: classificador deve aceitar path bruto com espaços; nada de quebrar em palavras. Trim só whitespace leading/trailing.
- **iTerm2 colando file:// com percent-encoding**: classificador resolve `file://` URL via `rawurldecode` antes de chamar `fromPath`.
- **Apple Terminal sem OSC 8**: fallback é `/open-image N` — documentar no banner inicial do REPL.
- **Outras imagens lá fora do allowed_roots**: `fromPath()` já valida raízes; se cair fora, lança e mensagem aparece. Comportamento idêntico ao já existente.

## Definition of Done

- Cmd+V em Ghostty cola screenshot e atualiza prompt pra `[imagem 1]` em < 1s
- Cmd+click em `imagem 1` abre Preview no macOS
- Colar segunda imagem mostra `[imagem 1, imagem 2]`, ambos clicáveis abrindo arquivos diferentes
- Ctrl+V continua funcionando como atalho
- Slash commands existentes (`/paste-image`, `/image`, `/images`, `/open-image`, `/clear-images`) continuam funcionando
- Testes do plano todos verdes
- Validação manual em iTerm2 e Terminal.app documentada (mesmo que com fallback de OSC 8)
