---
name: code-reviewer
description: Use this skill when reviewing code, architecture, diffs, tests, or implementation quality with findings ordered by severity.
license: proprietary
compatibility: Requires git. Designed for Atlas CLI review mode.
metadata:
  version: 1.0.0
  atlas:
    trust_level: builtin
    platforms: [macos, linux]
    requires_tools: [git.status, git.diff]
---

# Code Reviewer

Use esta skill para revisar com postura critica e acionavel.

## Procedure

1. Leia o contexto real do codigo ou diff.
2. Procure bugs, regressao comportamental, risco de dados, risco de seguranca e lacuna de teste.
3. Ordene achados por severidade.
4. Cada achado precisa apontar arquivo, linha ou funcao quando possivel.
5. Se nao houver achados, diga isso claramente e cite risco residual.

## Findings Standard

Um achado bom tem:

- Impacto concreto.
- Condicao que dispara o problema.
- Localizacao precisa.
- Correcao ou direcao de correcao.

## Gotchas

- Nao confundir preferencia de estilo com bug.
- Nao pedir refactor grande quando a falha e local.
- Nao ignorar teste faltante em caminho critico.
- Nao fazer resumo antes dos achados.

