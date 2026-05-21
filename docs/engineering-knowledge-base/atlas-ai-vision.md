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
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-vision

graph_title: Atlas AI Vision

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Vision
canonical_name: Atlas AI Vision
technical_name: atlas-ai-vision
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-ai-vision.md

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-vision.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - architecture

evidence:
  - docs/engineering-knowledge-base/atlas-ai-vision.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - architecture

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
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

## Resumo

Documento fundador curto que define Atlas AI como a inteligencia unica do produto, separando Core, Domains, Pipeline, Surfaces e Curation.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
