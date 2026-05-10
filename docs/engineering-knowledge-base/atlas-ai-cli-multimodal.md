---
id: atlas-ai-cli-multimodal
type: engineering_knowledge
title: Atlas AI CLI Multimodal
status: active
category: cli
priority: 82
summary: Contrato canonico para input multimodal no Atlas CLI, incluindo paste de imagem, anexos clicaveis, fallback por terminal e relacao com capability registry.
tags:
  - atlas-ai
  - cli
  - multimodal
  - image-paste
capabilities:
  - atlas_input_image_paste
  - cli_context_injection
  - surface_adapter
decisions:
  - Paste de imagem e capability de input da surface CLI, nao mudanca de provider ou runtime core.
  - Setup operacional fica em docs/paste-image-setup.md; este doc governa arquitetura e limites.
  - Planos e specs antigos sao source material preservado, nao instrucao viva.
maintenance:
  - Atualizar quando atlas chat/dev, image attachment, terminal REPL ou capability registry de anexos mudarem.
related_paths:
  - docs/paste-image-setup.md
  - docs/atlas-cli-final-product.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/superpowers/specs/2026-05-04-atlas-dev-paste-image-design.md
  - docs/superpowers/plans/2026-05-04-atlas-dev-paste-image.md
---

# Atlas AI CLI Multimodal

Este documento define o contrato canonico de input multimodal no CLI. O caso
atual coberto e paste/anexo de imagem em `atlas chat` e `atlas dev`.

## Autoridade

| Assunto | Autoridade |
|---|---|
| Arquitetura e limites de input multimodal CLI | Este documento |
| Setup por terminal e troubleshooting | `../paste-image-setup.md` |
| Produto CLI final | `../atlas-cli-final-product.md` |
| Capability registry e anexos como input | `atlas-ai-kernel-architecture.md` |
| Specs/plans historicos | `../superpowers/*` |

## Principios

- Input multimodal pertence a surface CLI.
- Attachment nao pode bypassar permission, provider-safety ou context budget.
- O mesmo anexo deve ser rastreavel no trace/evidence quando influenciar uma
  decisao.
- Terminal-specific setup e operacional; nao deve virar contrato de Kernel.
- Fallback textual/slash command deve existir quando o terminal nao entrega
  key events ou OSC 8.

## Comportamento Esperado

| Entrada | Resultado |
|---|---|
| Ctrl+V com imagem | Captura clipboard e adiciona imagem pendente. |
| Cmd+V remapeado para `0x16` | Mesmo comportamento de Ctrl+V. |
| Bracketed paste vazio com imagem-only clipboard | Captura imagem se suportado pela implementacao. |
| Bracketed paste com path de imagem | Anexa arquivo se path for valido e permitido. |
| `/paste-image` | Captura clipboard sem depender de keybind. |
| `/image <path>` | Anexa arquivo local permitido. |
| `/images` | Lista anexos pendentes. |
| `/open-image N` | Abre anexo N como fallback a OSC 8. |
| `/clear-images` | Remove anexos pendentes. |

## UI De Terminal

- Prompt deve mostrar anexos como `[imagem 1]`, `[imagem 1, imagem 2]`.
- Quando o terminal suportar OSC 8, cada label deve apontar para o arquivo.
- Apple Terminal.app pode ignorar OSC 8; `/open-image N` permanece fallback.
- O label nao deve mudar layout ou duplicar texto quando o prompt redesenhar.

## Safety

- `pngpaste`, AppleScript e `sips` sao mecanismos locais de captura; falha deve
  gerar erro claro, nao envio silencioso de mensagem incompleta.
- Paths anexados devem respeitar allowed roots e MIME permitido.
- Imagens pendentes nao devem sobreviver indevidamente apos envio/clear.
- Metadata persistida deve evitar copiar conteudo bruto desnecessario.
- Provider recebe anexos apenas pelo caminho normal de mensagem/context pack.

## Relation To Capability Registry

O Kernel pode representar esta feature como capability de input, por exemplo:

```yaml
id: atlas.input.image_paste
surface: cli
attachment_kinds:
  - image
requires:
  - local_clipboard_access
  - allowed_roots_validation
  - provider_attachment_support
```

Esse manifesto declara capacidade; a implementacao concreta continua na surface
CLI e nos services de attachment.

`SurfaceCapabilityParityService` valida esse contrato contra os adapters reais:
`atlas_cli` precisa estar coberto por `atlas_cli_dev`, `atlas_cli_chat` e
`atlas_cli_forge`; app/API podem satisfazer a capability por upload/anexo
normalizado. Assim, uma evolucao multimodal nao pode ficar presa so em `ask` ou
so em `dev` sem quebrar a validacao arquitetural.

## Input Boundary Contract

`architecture-validate` publica
`atlas.input.surface_capability_boundary.v1` dentro de AP-33 e do bloco
`capabilities.surface_adapter_parity`. Esse contrato e a regra executavel para
qualquer IA futura:

- texto, imagem, arquivo e voz pertencem ao `Atlas Input`;
- surface adapter apenas coleta/expoe input, nao cria capability propria;
- capability multimodal presa em uma unica surface e violacao arquitetural;
- provider nunca recebe anexo direto fora do pipeline normalizado;
- domain nao possui parser de attachment; domain so consome contexto ja
  normalizado.

Padroes proibidos pelo contrato: `surface_only_image_paste`,
`ask_only_attachment_flow`, `provider_direct_attachment_bypass` e
`domain_owned_attachment_parser`.

## Source Material

- `docs/paste-image-setup.md`
- `docs/superpowers/specs/2026-05-04-atlas-dev-paste-image-design.md`
- `docs/superpowers/plans/2026-05-04-atlas-dev-paste-image.md`
