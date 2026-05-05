> Cleanup status: superseded_source_material.
> Canonical replacement: docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md; docs/atlas-cli-final-product.md.
> Cleanup note: Setup remains useful for operators. Architecture/design authority should be promoted to a small CLI multimodal doc or the CLI final product doc.

# Paste de imagem — setup por terminal

O Atlas captura imagens da clipboard (Cmd+Shift+Ctrl+4 ou copia do Finder) e as anexa ao composer do `atlas chat`/`atlas dev`. O suporte é nativo via Ctrl+V e via `/paste-image`. Para Cmd+V funcionar nativamente em terminais que absorvem a tecla quando a clipboard contem apenas imagem, e necessario remapear Cmd+V para enviar `0x16` (byte de Ctrl+V). Esta página documenta como fazer isso por terminal.

## Pré-requisitos

```
brew install pngpaste
```

Sem `pngpaste` o Atlas cai em fallback (`osascript`+`sips`). Funciona, mas é mais lento e tem mais pontos de falha em apps Chromium (Chrome, Figma) que copiam imagem em `public.png` em vez de `«class PNGf»`.

## Por que remapear Cmd+V → 0x16

Quando voce aperta Cmd+V em qualquer terminal macOS com **imagem-only** na clipboard (sem fallback de texto), o terminal absorve o evento e nao envia byte algum ao processo. Resultado: o Atlas nao recebe nada e parece "morto". Mapeando Cmd+V para emitir `0x16`, o Atlas recebe o mesmo byte que receberia em Ctrl+V e roda o handler smart de paste, que decide: imagem -> captura, texto -> cola no buffer, vazio -> erro descritivo.

**Caveat global**: o keybind afeta o terminal inteiro. Em Vim modo insert e Bash readline, Cmd+V passa a virar `^V` (quoted-insert), nao o paste padrao. Se isso virar incomodo, use **profile dedicado** ao inves de keybind global.

## iTerm2

### Global (afeta todos os profiles)

1. Settings (Cmd+,) -> aba **Keys** -> sub-aba **Key Bindings**
2. Botao `+` no canto inferior esquerdo
3. Keyboard Shortcut: aperta **Cmd+V** (vai aparecer `⌘V`)
4. Action: **Send Hex Codes**
5. Campo de hex: `0x16`
6. **OK**

### Profile dedicado (recomendado)

1. Settings -> **Profiles** -> `+` -> nomeia "Atlas"
2. Aba **Keys** dentro do profile -> **Key Mappings**
3. Mesmo passo: `+`, Cmd+V, Send Hex Codes, `0x16`, OK
4. Use esse profile so quando rodar Atlas (Profiles menu ou Cmd+Option+N)

### Verificacao

```
cat
```

Aperta Cmd+V. Deve aparecer `^V`. Aperta Ctrl+C pra sair.

## Ghostty

`~/.config/ghostty/config`:

```
keybind = cmd+v=text:\x16
```

Salva e reinicia o Ghostty. **Para escopar so a um workspace/profile**, use Ghostty's `keybind` dentro de um config separado e use `--config-file` no atalho de abrir.

## Wezterm

`~/.wezterm.lua`:

```lua
local wezterm = require 'wezterm'
local config = wezterm.config_builder()

config.keys = {
  { key = 'v', mods = 'CMD', action = wezterm.action.SendString('\x16') },
}

return config
```

Salva e reinicia.

## Terminal.app

1. Settings -> **Profiles** -> seleciona o profile -> aba **Keyboard**
2. Botao `+`
3. Key: aperta Cmd+V
4. Action: **Send Text**
5. Campo: `\026` (octal de `0x16`)
6. OK

Reverter: remove a entrada da lista.

## Keybinds bonus pra editing power

Pra ativar o pacote de edicao moderna (selecao, navegacao, undo) com gestures macOS, configure os seguintes hex codes em **Settings -> Keys -> Key Bindings** (mesmo metodo do Cmd+V acima):

| Tecla | Hex Code | O que faz no Atlas |
|---|---|---|
| **Cmd+A** | `0x01` | Seleciona todo o texto do composer |
| **Cmd+Z** | `0x1A` | Undo |
| **Cmd+Y** ou **Cmd+Shift+Z** | `0x19` | Redo |
| **Cmd+R** | `0x12` | Reverse history search (estilo bash Ctrl+R) |
| **Cmd+G** | `0x07` | Cancela operacao atual / limpa composer |
| **Cmd+L** | `0x0C` | Limpa tela / refaz layout (Ctrl+L tradicional) |
| **Cmd+W** | `0x17` | Apaga ultima palavra (Ctrl+W tradicional) |
| **Cmd+U** | `0x15` | Limpa linha de texto |
| **Cmd+X** | `0x18` | Remove ultima imagem anexada |

Setas com Shift / Alt funcionam **out-of-the-box no iTerm2** (envia sequencias `\x1b[1;2D` / `\x1b[1;4D` que o Atlas detecta):

- **Shift+Left/Right** estende selecao char por char
- **Shift+Alt+Left/Right** (Shift+Option+Arrow) estende por palavra
- **Shift+Home/End** estende ate inicio/fim da linha
- **Alt+Left/Right** (Option+Arrow) navega por palavra (sem selecionar)
- **Setas comuns** com selecao ativa: colapsam pro inicio (←) ou fim (→) da selecao

Atalhos emacs/readline tambem funcionam direto (sem keybind):

- **Ctrl+A** seleciona tudo
- **Ctrl+E** vai pro fim da linha
- **Ctrl+B / Ctrl+F** = ← / →

## Comportamento esperado depois do setup

| Clipboard tem | Cmd+V no atlas chat |
|---|---|
| Imagem (PNG/TIFF/JPEG/etc) | `Lendo imagem do clipboard...` -> `[img:1] pronta para enviar` |
| Texto | texto aparece no buffer (sem mensagem extra) |
| Vazio | `Clipboard vazio. Copie um texto ou capture uma imagem...` |

Adicional sem clipboard:
- **Drag-and-drop**: arrasta arquivo de imagem do Finder pro terminal -> anexa direto.
- **`/paste-image`** (aliases: `/paste`, `/p`, `/img`): captura clipboard sem keybind.
- **Ctrl+X**: remove a ultima imagem anexada (preserva o texto que voce digitou).
- **Ctrl+U**: limpa o texto digitado na linha (preserva as imagens anexadas).
- **Backspace com prompt vazio**: remove a ultima imagem do composer.
- **Cmd+Click no `[imagem N]`**: abre a imagem no Preview do macOS.

## Reverter o keybind

- iTerm2: Settings -> Keys -> Key Bindings -> seleciona `⌘V` -> `−`.
- Ghostty: remove a linha `keybind = cmd+v=text:\x16`.
- Wezterm: remove a entrada de `config.keys`.
- Terminal.app: Settings -> Profiles -> Keyboard -> remove a linha Cmd+V.

## Troubleshooting

**`Clipboard nao contem imagem (..., string, ...)` mesmo com imagem**: voce copiou imagem de app Chromium (Chrome, Figma) que usa UTI `public.png` em vez de `PNGf`. Cole no Preview e copie de la, ou use Cmd+Shift+Ctrl+4 para criar uma nova captura.

**`Nao consegui inspecionar o clipboard via osascript`**: macOS bloqueou Automation pro terminal. Sistema -> Privacidade e Seguranca -> Automacao -> autoriza o terminal.

**Cmd+V cola texto mas nao captura imagem**: o keybind nao foi aplicado ou o terminal precisa restart. Confirme com `cat` que `^V` aparece.

**Imagem em modo Docker/sandbox nao chega**: o `--add-dir` aponta pro path do host, que o container nao enxerga. Limitacao conhecida; use modo host ou monte o diretorio `storage/app/ai/attachments` no container.
