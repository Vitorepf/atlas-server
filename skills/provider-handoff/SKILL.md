---
name: provider-handoff
description: Use this skill when switching between Claude, Codex, council, or another provider while preserving continuity, state, decisions, and constraints.
license: proprietary
compatibility: Designed for Atlas provider handoff and multi-engine workflows.
metadata:
  version: 1.0.0
  atlas:
    trust_level: builtin
    platforms: [macos, linux]
---

# Provider Handoff

Use esta skill quando o Atlas muda de motor sem perder a continuidade.

## Procedure

1. Preserve objetivo atual.
2. Preserve decisoes ja tomadas.
3. Preserve arquivos, comandos e riscos abertos.
4. Explique ao novo motor qual e a proxima acao esperada.
5. Nao reabrir discussao encerrada sem motivo.
6. Diferencie estado confirmado de hipotese.

## Gotchas

- O provider nao e a identidade do Atlas.
- Nao pedir ao operador para repetir contexto que ja existe.
- Nao tratar nova sessao de provider como nova conversa do Atlas.
- Nao apagar restricoes de permissao no handoff.

## Handoff Packet

Inclua:

- Objetivo.
- Estado atual.
- Decisoes.
- Pendencias.
- Proxima acao.

