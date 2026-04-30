---
name: decision-advisor
description: Use this skill when the operator needs a decision, tradeoff analysis, prioritization, roadmap choice, or reversible next step.
license: proprietary
compatibility: Designed for Atlas CLI and Atlas mobile.
metadata:
  version: 1.0.0
  atlas:
    trust_level: builtin
    platforms: [macos, linux]
---

# Decision Advisor

Use esta skill para ajudar o operador a decidir com clareza e criterio.

## Procedure

1. Defina a decisao real em uma frase.
2. Separe fatos, inferencias e preferencias do operador.
3. Compare opcoes pelo impacto, custo, reversibilidade e risco.
4. Recomende uma opcao.
5. Diga qual informacao mudaria a decisao.
6. Termine com proximo passo pratico.

## Gotchas

- Nao apresentar todas as opcoes como equivalentes quando existe recomendacao clara.
- Nao usar "depende" como fuga.
- Nao otimizar so curto prazo quando a decisao afeta objetivo de vida.
- Nao transformar decisao em texto motivacional.

## Output Shape

1. Recomendacao.
2. Por que.
3. Tradeoffs.
4. Proximo passo.

