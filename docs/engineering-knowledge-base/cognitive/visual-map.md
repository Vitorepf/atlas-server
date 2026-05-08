---
id: atlas-ai-cognitive-plane-visual-map
type: engineering_knowledge
title: Atlas AI Cognitive Plane Visual Map
status: active
category: architecture-visual
priority: 97
summary: Especificacao visual canonica do Cognitive Development Plane, alinhando dominios envolvidos, 4 pilares, 5 movimentos, pipeline overlay, capabilities, evidence, Curator e multiplier edge.
tags:
  - atlas-ai
  - cognitive
  - visual-map
  - architecture
capabilities:
  - cognitive_plane_visual_map
  - cognitive_onboarding
  - visual_flow_governance
decisions:
  - Este e o visual canonico do Cognitive Development Plane.
  - A imagem representa o estado final enterprise do plano cognitivo, nao apenas o estado atual de implementacao.
  - O visual orienta humanos e IAs; specs e APs continuam sendo contrato executavel.
  - Qualquer novo diagrama cognitivo deve preservar 4 pilares, 5 movimentos, pipeline overlay, evidence e human review.
maintenance:
  - Manter abaixo de 180 linhas.
  - Atualizar antes de redesenhar imagem, slide, onboarding visual ou diagrama cognitivo.
related_paths:
  - docs/engineering-knowledge-base/assets/cognitive-plane-visual-map-v1.png
  - docs/engineering-knowledge-base/cognitive/README.md
  - docs/engineering-knowledge-base/cognitive/implementation-briefing.md
  - docs/engineering-knowledge-base/cognitive/overview.md
  - docs/engineering-knowledge-base/cognitive/principles.md
  - docs/engineering-knowledge-base/cognitive/pipeline-overlay.md
  - docs/engineering-knowledge-base/cognitive/multiplier-edge.md
  - docs/engineering-knowledge-base/atlas-ai-flow-visual-map.md
owner: atlas-ai
layer: 2-and-3
line_limit: 180
---

# Atlas AI Cognitive Plane Visual Map

Este documento governa a imagem visual do **Cognitive Development Plane** para
humano e IA entenderem a mesma arquitetura antes de implementar AP, capability,
surface, memory artifact ou Curator flow.

## Asset Canonico

Imagem aprovada: `../assets/cognitive-plane-visual-map-v1.png`.

![Arquitetura do Cognitive Development Plane do Atlas AI](../assets/cognitive-plane-visual-map-v1.png)

Se houver conflito entre imagem e texto, vencem, nesta ordem:

1. `cognitive/principles.md`
2. `cognitive/pipeline-overlay.md`
3. AP especifico em `docs/ap/AP-###-cognitive-*.md`
4. este documento
5. a imagem

## Regras Visuais Obrigatorias

1. Mostrar Cognitive Plane como sub-arquitetura, nao produto paralelo.
2. Mostrar 4 pilares: teorico, pratico, cognitivo, transferencial.
3. Mostrar 5 movimentos: declarar, gerar erro, praticar, provar, revisar.
4. Explicitar erro preditivo calibrado, nao frustracao aleatoria.
5. Usar o pipeline canonico de 17 etapas como overlay cognitivo.
6. Separar domains envolvidos de capabilities cognitivas horizontais.
7. Mostrar Evidence Ledger, Read Models, Learning Signals, Curator e Human Review.
8. Marcar que learning nao autoaltera comportamento critico sem proposal/review.
9. Mostrar Multiplier Edge como capacidades arquiteturalmente unicas do Atlas.
10. Incluir docs de referencia: `cognitive/`, `docs/ap/AP-163..170` e `canonical-architecture-index.md`.

## Texto De Referencia

Titulo recomendado:

```text
Arquitetura do Cognitive Development Plane do Atlas AI
```

Subtitulo recomendado:

```text
Estado final enterprise: trajetoria cognitiva governada — Pareto, 4 pilares,
erro preditivo calibrado, evidencia, mastery e multiplier edge
```

Rodape obrigatorio:

```text
Surface nao decide · Provider nao decide · Tool nao decide · Domain nao burla policy · Tudo repetido vira Core
```

## Nao Fazer

1. Nao desenhar Cognitive Plane como app de estudo isolado.
2. Nao trocar evidence/mastery por resumo passivo.
3. Nao esconder Human Review quando houver mudanca de curriculo ativo.
4. Nao colocar gurus, IA externa ou conteudo bruto como fonte canonica.
5. Nao remover APs, status ou docs de referencia do visual.
