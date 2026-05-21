---
id: atlas-documentation-reality-implementation-blueprint
type: engineering_knowledge
title: Atlas Documentation Reality Implementation Blueprint
status: active
category: documentation-governance
priority: 100
summary: Receita executavel para implementar ADRS, ACRUI e AURC com qualidade nota 10, sem duplicar verdade canonica, sem iniciar por UI, e sem permitir que IA confunda doc, codigo, scaffold, legado ou cartografia.
tags:
  - atlas-ai
  - documentation
  - implementation-blueprint
  - code-reality
  - cartography
  - ai-safety
capabilities:
  - documentation_reality_implementation_blueprint
  - acrui_implementation_sequence
  - aurc_implementation_sequence
  - documentation_reality_scoring
  - ai_implementation_recipe
decisions:
  - Nome canonico/produto obrigatorio: Atlas Documentation Reality Implementation Blueprint.
  - Acronimo tecnico obrigatorio: ADRIB.
  - Nome interno de experiencia/superficie: Atlas Documentation Reality Build Recipe.
  - Runtime tecnico: nenhum; este documento e uma receita de implementacao, nao um runtime.
  - ADRS continua sendo o contrato mae; ADRIB define a ordem segura de execucao.
  - ACRUI deve vir antes de AURC produtiva, porque visual sem verdade operacional vira desenho enganoso.
  - AURC deve consumir fontes verificadas, nunca virar fonte de verdade propria.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando ADRS, ACRUI, AURC, Documentation OS, docs-authority, Code Intelligence ou Cartografia mudarem.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-block-upgrade-map.md
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
  - docs/engineering-knowledge-base/atlas-universal-reality-cartography.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - docs/engineering-knowledge-base/atlas-system-graph.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-documentation-reality-implementation-blueprint
graph_title: Atlas Documentation Reality Implementation Blueprint
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-documentation-reality-system
graph_status: active
graph_source: repo
owner: documentation-governance
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-implementation-blueprint.md
allowed_changes:
  - Refinar ordem de implementacao, gates, rubricas, DoD e comandos de certificacao da area ADRS.
forbidden_changes:
  - Transformar este blueprint em segunda fonte de verdade.
  - Autorizar implementacao mutativa antes de leitura e classificacao read-only.
  - Autorizar Cartografia visual sem source refs e evidence refs.
depends_on:
  - atlas-documentation-reality-system
  - atlas-code-reality-usage-intelligence
  - atlas-universal-reality-cartography
flows_to:
  - atlas-cartography
  - atlas-code
  - programming-dev
  - programming-forge
unlocks:
  - documentation-reality-implementation
  - human-readable-operational-truth
  - ai-safe-doc-navigation
governs:
  - documentation-governance
  - architecture-audit
  - atlas-cartography
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-reality-implementation-blueprint.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - system
  - implementation-blueprint
  - documentation-reality
ai_entrypoints:
  - Leia este blueprint antes de implementar ADRS, ACRUI, AURC, Cartografia documental ou qualquer gate que classifique documentacao/codigo como vivo, legado, scaffold ou morto.
ai_usage_notes:
  - Este doc e prescritivo para implementacao; se houver conflito, ADRS decide a arquitetura e ADRIB decide a ordem de execucao.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
  - "git diff --check"
failure_modes:
  - IA implementa UI de Cartografia antes de provar fontes.
  - IA cria runtime novo que duplica Code Intelligence ou docs-authority.
  - IA declara codigo morto por ausencia de grep simples.
observability_signals:
  - documentation_reality_score
  - acrui_classification_coverage
  - aurc_visual_coverage
  - context_minimality_score
  - stale_doc_count
  - duplicate_owner_count
next_actions:
  - Implementar as fases deste blueprint em ordem, com gates verdes por fase.
---
# Atlas Documentation Reality Implementation Blueprint

## Resumo

ADRIB e a receita de implementacao da area ADRS. Ele existe para transformar a
visao macro em ordem executavel: o que fazer primeiro, quais gates bloqueiam,
qual Definition of Done prova qualidade e quais atalhos sao proibidos.

Problema que resolve:

- IAs implementam olhando docs antigas, incompletas ou duplicadas.
- Humanos nao conseguem entender o Atlas pela Cartografia.
- Codigo vivo, scaffold, legado e morto ficam misturados.
- Context packs ficam grandes demais ou incompletos.

Regra central:

```text
ACRUI prova a realidade. AURC mostra a realidade. ADRS governa a area.
ADRIB define a ordem para construir isso sem improviso.
```

## Papel no Atlas

ADRIB e um blueprint de execucao, nao uma nova camada de runtime. Ele deve ser
lido por qualquer IA antes de implementar Documentation Reality, ACRUI, AURC,
Cartografia documental, context-pack documental ou gates de classificacao de
codigo/documentacao.

Responsabilidades:

- transformar ADRS em backlog implementavel;
- impedir que UI venha antes de fonte e evidencia;
- impedir que classificacao operacional vire opiniao;
- garantir que humano e IA usem a mesma verdade canonica;
- manter a area dentro de gates automatizaveis.

Nao responsabilidades:

- nao e fonte canonica acima do ADRS;
- nao substitui ACRUI, AURC, Code Intelligence ou docs-authority;
- nao autoriza delecao automatica;
- nao define visual final da Cartografia.

## Onde Se Encaixa

```text
Documentation OS / Knowledge Governance
        ↓
ADRS - contrato mae da area
        ↓
ADRIB - receita de implementacao
        ↓
ACRUI - verdade operacional read-only
        ↓
ADRS score - agregacao de qualidade
        ↓
AURC schema - grafo visual verificavel
        ↓
Cartografia - superficie humana
        ↓
Context Pack - projecao minima para IA
```

Se uma implementacao pular ACRUI e ir direto para Cartografia, ela esta fora de
ordem. Se uma implementacao gerar contexto para IA sem owner/evidence refs, ela
esta incompleta.

## Contratos

### Contrato de fonte

Toda fonte consumida por ACRUI/AURC deve declarar:

- `source_type`;
- `authority_tier`;
- `path_or_table`;
- `owner`;
- `freshness`;
- `read_policy`;
- `source_refs`;
- `evidence_refs`.

### Contrato de classificacao operacional

Classificacoes minimas:

- `active_runtime`;
- `active_doc`;
- `implemented_used`;
- `implemented_unused_candidate`;
- `headless_runtime`;
- `scaffold_parked`;
- `legacy_adapter`;
- `duplicate_candidate`;
- `contradiction_candidate`;
- `quarantine_candidate`;
- `unknown_requires_review`.

Nenhum resultado pode ser `dead_code_confirmed` sem quarantine plan, grep
global, evidencia forte e aprovacao humana.

### Contrato visual

Todo node da AURC deve carregar:

- id, label, level, kind, status e risk;
- owner, authority tier, freshness e usage state;
- source refs, evidence refs e drilldown target;
- resumo humano curto.

Node sem evidence refs nao pode aparecer como pronto.

## Fluxo

### Fase 0 - Freeze De Contratos

Entregas:

- ADRS ativo e abaixo de 520 linhas.
- ACRUI, AURC e ADRIB linkados.
- Documentation OS e Knowledge Governance apontando para ADRS.

Gates:

- docs-health;
- docs-authority-audit;
- architecture-validate;
- git diff --check.

DoD:

- qualquer IA sabe qual doc ler primeiro;
- nenhum runtime novo foi criado;
- nomes canonicos estao estaveis.

### Fase 1 - Source Registry Read-Only

Entregas:

- `AtlasDocumentationSourceRegistry`;
- DTO `DocumentationRealitySourceRef`;
- testes de authority tier.

Fontes:

- repo docs, codigo, migrations, rotas, comandos, testes;
- Evidence Ledger, receipts, Code Intelligence;
- docs-authority-audit, architecture-validate, implemented-vs-scaffold matrix;
- Cartography graph e provider projections como baixa autoridade.

DoD:

- fonte sem tier vira `unknown_authority`;
- Obsidian/chat/projection nunca vence repo docs.

### Fase 2 - ACRUI Classifier MVP

Entregas:

- `AtlasCodeRealityUsageIntelligenceService`;
- `php artisan atlas:code-reality audit --json`;
- fixtures para vivo, scaffold, legado, duplicado e headless.

DoD:

- IA pergunta "isso esta vivo?" e recebe classificacao com evidence refs;
- nenhum arquivo e removido ou movido.

### Fase 3 - ADRS Score

Entregas:

- `AtlasDocumentationRealitySystemService`;
- `php artisan atlas:documentation-reality score --json`;
- schema `atlas.documentation_reality_score.v1`.

Dimensoes:

1. authority_correctness;
2. operational_reality_coverage;
3. human_visual_comprehension;
4. ai_context_minimality;
5. evidence_strength;
6. drift_resistance;
7. duplication_resistance;
8. stale_context_resistance;
9. cross_project_boundary_safety;
10. maintenance_cost.

Status:

- `blocked`: dimensao critica abaixo de 7.
- `attention`: media abaixo de 8.5.
- `ready`: media >= 9.0 e zero blocker.
- `excellent`: media >= 9.6 e simuladores verdes.

### Fase 4 - AURC Visual Schema

Entregas:

- `atlas.cartography.visual_node.v1`;
- `atlas.cartography.visual_edge.v1`;
- `atlas.cartography.visual_layer.v1`;
- builder que converte ACRUI/ADRS em grafo visual.

DoD:

- grafo serializado e testavel existe antes da UI;
- relacoes sem fonte ficam incertas, nao prontas.

### Fase 5 - Semantic Zoom

Niveis:

1. Universe;
2. Organization;
3. Project;
4. System;
5. Domain;
6. Flow;
7. Runtime;
8. Component;
9. File;
10. Evidence.

DoD:

- zoom troca o mundo visual, nao empilha texto;
- humano entende o macro sem abrir doc;
- modal detalha somente quando solicitado.

### Fase 6 - AI Context Projection

Entregas:

- `DocumentationRealityContextPackBuilder`;
- `ContextMinimalityLedger`;
- regression tests de pack minimo por tarefa.

DoD:

- tarefa pequena recebe owner doc + source refs + riscos locais;
- tarefa macro recebe ADRS + filho certo + paths impactados;
- contexto stale bloqueia ou marca risco.

### Fase 7 - Gates Permanentes

Gates:

- Documentation Drift Gate;
- Duplicate Owner Gate;
- Runtime Reality Gate;
- Cartography Source Gate;
- Context Sufficiency Gate;
- Stale Projection Gate;
- Quarantine Safety Gate.

Comandos futuros:

```bash
php artisan atlas:documentation-reality score --json
php artisan atlas:code-reality audit --json
php artisan atlas:cartography visual-coverage --json
php artisan atlas:context-pack regression --suite=documentation-reality --json
```

### Fase 8 - Synthetic Reader Tests

Cenarios:

- IA acha owner correto para feature nova.
- IA evita duplicacao porque encontra runtime existente.
- IA classifica legado sem apagar.
- humano entende Atlas Dev por Cartografia.
- humano entende projeto externo sem copiar docs para Atlas.
- humano acha gargalo visual sem ler doc longa.

DoD:

- teste falha se a doc existe mas nao orienta acao correta.

### Fase 9 - Telemetria E SLO

Sinais:

- sessoes que leram ADRS/ACRUI/AURC;
- implementacoes bloqueadas por duplicacao;
- docs stale detectados;
- nodes sem evidencia;
- context packs grandes demais;
- correcoes humanas causadas por doc confusa.

SLOs:

- 95% das tarefas acham owner doc em uma chamada;
- 95% dos nodes visuais tem source refs;
- 90% das tarefas usam context pack menor que o bruto;
- 0 delecoes sem quarantine plan;
- 0 nodes ready sem evidencia.

### Fase 10 - Multi-Projeto

Regra:

- cada organizacao tem sua verdade canonica no proprio local;
- Atlas consome, indexa, visualiza e gera context pack;
- Atlas nao copia documentacao canonica externa como doc Atlas.

DoD:

- Universe mostra Atlas, Blackink e outros mundos separados;
- cada mundo tem authority root proprio.

## Regras para IA

- Leia ADRS, ACRUI, AURC e ADRIB antes de implementar.
- Implemente somente a proxima fase aberta.
- Nao crie UI antes de source registry, ACRUI classifier e visual schema.
- Nao apague nem mova arquivos.
- Todo resultado precisa de schema, source refs, evidence refs e testes.
- Se encontrar duplicacao, classifique e reporte; nao corrija fora do escopo.
- Se docs-health falhar por mudanca nova, corrija antes de continuar.

## Escopo de Implementacao

Dentro do escopo:

- services read-only;
- comandos JSON auditaveis;
- schemas de node/edge/layer;
- gates de drift, duplicacao, sufiencia e stale context;
- context pack minimo;
- simuladores de leitura humana/IA;
- telemetria de qualidade documental.

Fora do escopo inicial:

- UI bonita sem grafo verificavel;
- delecao automatica;
- migracao de docs externas para dentro do Atlas;

## Dependencias

- ADRS para contrato mae.
- ACRUI para classificacao operacional.
- AURC para cartografia visual.
- Documentation OS para formato e linha limite.
- Knowledge Governance para autoridade.
- Code Intelligence para mapa mecanico de codigo.
- docs-authority-audit para conflito e duplicacao.
- architecture-validate para coerencia de arquitetura.

## Evidencias

Evidencias minimas por fase:

- docs alteradas com frontmatter valido;
- comandos artisan novos com JSON deterministico;
- testes focados por classificacao/gate;
- source refs e evidence refs em payloads;
- docs-health, docs-authority-audit, architecture-validate e diff-check.

Evidencias proibidas como conclusao unica:

- print de tela;
- opiniao do agente;
- grep isolado;
- chat antigo;
- doc externa copiada.

## Riscos

- Cartografia virar tela bonita sem verdade.
- ACRUI virar ferramenta de delecao perigosa.
- ADRS virar doc gigante e ilegivel.
- Context pack economizar tokens demais e perder restricao critica.
- Projeto externo contaminar docs canonicos do Atlas.
- IA tratar scaffold estacionado como produto pronto.

Mitigacao:

- read-only primeiro;
- score com evidence refs;
- quarantine antes de delete;
- synthetic reader tests;
- source refs obrigatorios em node visual.

## Exemplos

- Feature nova: IA quer criar runtime YouTube; ACRUI acha service existente
  `unused_candidate`; IA nao duplica e cria plano de reuso.
- Cartografia: humano abre Universe -> Atlas -> Atlas AI -> Atlas Dev; AURC
  mostra fluxos, gargalos, status e riscos; click abre doc humana.
- Projeto externo: humano abre Blackink; Atlas consome o repo Blackink, mas a
  documentacao canonica continua no proprio projeto.

## Proximas Acoes

1. Manter ADRIB linkado no ADRS.
2. Implementar Fase 1 antes de qualquer UI.
3. Implementar ACRUI MVP read-only.
4. Criar score ADRS depois que ACRUI classificar realidade.
5. Criar AURC visual schema antes da superficie visual.
6. Evoluir Cartografia com semantic zoom so depois dos gates.
