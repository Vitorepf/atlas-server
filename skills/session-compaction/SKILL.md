---
name: session-compaction
description: Use this skill for long sessions that need compaction, continuation, summary, state preservation, or recovery after idle/provider switch.
license: proprietary
compatibility: Designed for Atlas long-running conversations and dev sessions.
metadata:
  version: 1.0.0
  atlas:
    trust_level: builtin
    platforms: [macos, linux]
---

# Session Compaction

Use esta skill para preservar performance em conversas e implementacoes longas.

## Procedure

1. Resuma objetivo atual.
2. Liste decisoes confirmadas.
3. Liste estado operacional: arquivos, comandos, traces, testes ou fontes.
4. Liste pendencias e proximo passo.
5. Preserve restricoes e preferencias relevantes.
6. Remova repeticao, exploracao encerrada e contexto fraco.

## Gotchas

- Nao compactar skill ativa como se fosse fala normal.
- Nao transformar resumo em historico completo.
- Nao perder resposta curta que depende do turno anterior.
- Nao promover memoria incerta sem evidencia.

## Compact State

Um bom estado compacto permite retomar em menos de dois minutos sem pedir recap ao operador.

