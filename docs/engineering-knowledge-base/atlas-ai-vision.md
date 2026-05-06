---
id: atlas-ai-vision
type: engineering_knowledge
title: Atlas AI Vision
status: active
category: architecture
priority: 100
summary: Documento fundador curto que define Atlas AI como a inteligencia unica do produto, separando Core, Domains, Pipeline, Surfaces e Curation.
tags:
  - atlas-ai
  - vision
  - architecture
capabilities:
  - atlas_ai_vision
  - unified_intelligence
  - surface_independence
decisions:
  - Atlas AI e a inteligencia unica do produto.
  - Comandos, telas, APIs e workers sao superficies, nao donos de fluxo.
  - Capacidades horizontais pertencem ao Core e devem ser herdadas pelas superficies.
  - Atlas e canal multiplicador soberano sobre providers — nao competidor (ver atlas-ai-thesis-multiplier-channel.md).
  - Atlas precisa ser canal UNICO; uso direto de provider quebra o ciclo Evidence -> Curator -> Multiplicador.
maintenance:
  - Manter este documento curto; detalhes vivem nos docs especializados.
  - Atualizar quando o conceito raiz de Atlas AI mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
---

# Atlas AI Vision

Atlas AI e a inteligencia unica do Atlas.

Ele nao e `atlas ask`, `atlas dev`, `atlas forge`, `atlas decide`, uma tela do
app ou um provider. Esses elementos sao portas de entrada, motores ou surfaces.
O produto real e a inteligencia que orquestra tudo.

## Tese Central

Atlas e **canal multiplicador soberano** sobre providers de IA. Nao compete
com Claude/GPT/Gemini — usa todos. Cada melhoria de provider alimenta Atlas;
nunca ameaca. Antifragil por construcao.

Output_Atlas = Output_Provider × Multiplicador_Ecossistema

A tese exige duas propriedades inseparaveis:

1. **Multiplicador**: cada feature do Atlas amplifica output bruto de provider
2. **Canal unico**: Atlas precisa ser a UNICA via de interacao com IA do
   Vitor. Uso direto de provider quebra o ciclo virtuoso e estagna o
   multiplicador

Ver [atlas-ai-thesis-multiplier-channel.md](atlas-ai-thesis-multiplier-channel.md)
para detalhamento completo. Esta tese e ponto fixo do Atlas; toda decisao
arquitetural e auditada contra ela.

## Estrutura Mae

```text
Atlas AI
├── Core
├── Domains
├── Pipeline
├── Surfaces
└── Curation
```

## Core

Core contem capacidades compartilhadas que so podem existir uma vez:

- input multimodal;
- intent;
- contexto;
- memoria;
- policy;
- providers;
- executores;
- gates;
- tools;
- evidencia;
- learning.

Se uma capacidade serve para mais de uma surface ou mais de um domain, ela vive
no Core.

## Domains

Domains sao verticais especializadas. Eles usam o Core, mas definem seus
proprios criterios, ferramentas, harnesses e evidencias.

Domains planejados:

- `Atlas AI Programming`;
- `Atlas AI Personal Development`;
- `Atlas AI Finance`;
- `Atlas AI Curator`.

## Pipeline

Todo trabalho relevante passa pelo mesmo pipeline:

```text
Input -> Intent -> Domain -> Context -> Policy -> Executor -> Gate
-> Repair/Escalation -> Evidence -> Learning -> Output
```

Domain pode customizar o conteudo de cada etapa, mas nao deve pular etapas sem
justificativa auditavel.

## Surfaces

Surfaces sao portas:

- CLI;
- app;
- API;
- workers;
- IDE/MCP futuro;
- automacoes.

Surfaces nao devem implementar logica de negocio que pertence ao Core ou a um
Domain. Uma surface coleta input, passa hints e chama o pipeline.

## Curation

Curation observa o proprio Atlas:

- detecta duplicacao;
- detecta capacidade solta;
- propoe evolucoes;
- mede resultado;
- atualiza memoria e docs.

O objetivo e impedir que o Atlas volte a virar uma colecao de comandos fortes
mas desalinhados.

## Regra Final

```text
Nada nasce em uma surface se pode nascer no Core.
Nada vira domain se ainda e capability horizontal.
Nada declara sucesso sem evidence.
```
