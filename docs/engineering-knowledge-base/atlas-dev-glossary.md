---
id: atlas-dev-glossary
type: engineering_knowledge
title: Atlas Dev Glossary
status: source_material
category: programming
priority: 100
summary: Glossario canonico de termos do Atlas Dev e termos vizinhos com risco alto de confusao para IA externa. Define com precisao: Atlas AI vs Atlas Dev vs Forge, engine vs wrapper, patamar vs versao tecnica, fluxo vs surface vs domain vs capability, R-levels, provider lock, e artefatos canonicos das 4 camadas (Plano/Contexto/Receipt/Telemetria). Termo nao definido aqui que aparecer em outro doc Atlas Dev e sinal de gap a corrigir.
tags:
  - atlas-dev
  - glossary
  - terminology
  - canonical-definitions
capabilities:
  - canonical_term_definitions
  - confusion_prevention_for_ai
  - cross_doc_terminology_alignment
decisions:
  - Toda IA que mexe em Atlas Dev deve ler este glossario antes de qualquer doc do conjunto Atlas Dev (apos o index).
  - Termos com risco de confusao tem entrada com "NAO confundir com..." explicito.
  - Termo usado em multiplos docs do conjunto Atlas Dev tem definicao unica AQUI; outros docs usam a referencia.
maintenance:
  - Adicione entrada quando um termo novo aparece em qualquer doc do conjunto Atlas Dev.
  - Atualize quando significado canonico de um termo mudar (raro).
  - Manter abaixo de 220 linhas.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-index.md
  - docs/engineering-knowledge-base/atlas-dev-policy.md
  - docs/engineering-knowledge-base/atlas-dev-patamares.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-ai-router-flow-routing-contract-v1.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-glossary
graph_title: Atlas Dev Glossary
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-dev-index
graph_status: active
graph_source: repo
human_name: Atlas Dev Glossary
canonical_name: Atlas Dev Glossary
technical_name: atlas-dev-glossary
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-dev-glossary.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-glossary.md
allowed_changes:
  - Adicionar entrada de termo novo com definicao canonica + NAO confundir com.
  - Refinar definicao quando confusao recorrente for detectada em IAs.
forbidden_changes:
  - Definir mesmo termo de forma diferente em outro doc do conjunto Atlas Dev sem atualizar aqui primeiro.
  - Inserir benchmark, Rivals, Opus challenge ou medicao competitiva.
  - Tornar Atlas Dev "produto", "substituto" ou "concorrente" de qualquer ferramenta externa.
depends_on:
  - atlas-dev-index
  - atlas-ai-documentation-operating-system
flows_to:
  - atlas-dev-policy
  - atlas-dev-efficient-programming-flow-v1
unlocks:
  - canonical_term_alignment
governs:
  - atlas_dev.terminology
evidence:
  - docs/engineering-knowledge-base/atlas-dev-glossary.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
visual_tags:
  - glossary
  - terminology
  - atlas-dev
ai_entrypoints:
  - Leia este glossario apos atlas-dev-index e antes de qualquer outro doc do conjunto Atlas Dev.
ai_usage_notes:
  - Quando encontrar termo confuso em outro doc, volte aqui antes de inventar interpretacao.
  - Entradas "NAO confundir com" sao parte essencial da definicao, nao decoracao.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - IA usa termo Atlas Dev com sentido de Atlas AI (produto) ou Atlas Forge (Obra). Resultado: arquitetura derrapa.
  - IA confunde patamar (identidade conceitual) com versao tecnica de arquivo (-v1).
observability_signals:
  - docs-health status ok
next_actions:
  - Adicionar termo novo conforme aparece em docs do conjunto Atlas Dev.
line_limit: 220
---
# Atlas Dev Glossary

Termos ordenados por relevancia (criticos primeiro). Entradas com "NAO confundir com" sao parte da definicao.

## Termos De Produto E Hierarquia

### Atlas AI

Produto. Superficie unica do programador. Substitui o uso combinado de Claude Code, Cursor, Codex CLI, browser de pesquisa, terminal de logs, etc. Usa engines externos internamente via provider lock / Atlas Decide. Programador interage SOMENTE com Atlas AI; engines ficam invisiveis.

**NAO confundir com**: Atlas Dev (fluxo dentro do produto), Atlas Forge (outro fluxo), Atlas AI Router (camada interna de decisao).

### Atlas Dev

Fluxo especializado de desenvolvimento em workspace dentro do Atlas AI. Cobre patch, repair, refactor leve, code generation em workspace, review de diff, frontend pontual, multi-file edit em scope contract, e perguntas workspace-bound. flow_id canonico: `atlas_dev`.

**NAO confundir com**: Atlas AI (produto que contem Atlas Dev), Atlas Forge (fluxo Obra-driven), Atlas Research (pesquisa conceitual), Atlas Explain (explicacao sem patch), Atlas Debug (logs sem patch obrigatorio), Atlas Review (review profundo de PR).

### Atlas Forge

Fluxo Obra-driven dentro do Atlas AI. Producao longa (semanas/mes), multiagente, governance pesada com replay/evidence/topology, workspace persistente. Entra quando o trabalho vira **Obra** (entrega longa, auditavel, com necessidade de maximo poder de fogo). flow_id canonico: `atlas_forge`.

**NAO confundir com**: Atlas Dev mais pesado (Forge nao e Atlas Dev escalado; e outro produto operacional).

### Atlas AI Router

Camada interna do Atlas AI que decide qual fluxo serve cada pedido. Recebe envelope da surface, decide `flow_id`, devolve `RouterDecision` com handoff payload. Router decide, fluxo executa.

**NAO confundir com**: surface (Desktop/CLI/App/API), fluxo (Atlas Dev/Forge/Research/etc.), Atlas Decide (camada de escolha de engine).

### Atlas Decide

Camada do Atlas AI que escolhe engine/provider/modelo para cada tarefa. Pode operar em 3 modos: `manual_override` (operador escolhe), `auto_best_allowed` (Atlas escolhe respeitando policy), `auto_best_available` (sem teto). Atlas Dev hoje opera em `manual_override` (provider_lock fixo `claude_cli` + Sonnet).

**NAO confundir com**: Atlas AI Router (Router escolhe fluxo, Atlas Decide escolhe engine).

## Termos De Engine E Wrapper

### Engine

Provider externo de IA (Claude Code CLI, Codex CLI, Cursor, Gemini, etc.). Ficam **internos** ao Atlas AI; programador nao os abre diretamente. Evoluem independentemente; Atlas AI herda saltos automaticamente via Atlas Decide.

**NAO confundir com**: Atlas Dev (wrapper governance, nao engine), provider lock (configuracao de qual engine usar).

### Wrapper Governance Multiplicador

Padrao arquitetural do Atlas. Cada fluxo (Atlas Dev, Atlas Forge, futuros) e um wrapper governance que envolve o engine com contexto, spec, contrato, verification, repair, receipt. Equacao: `resultado = engine x governance`. Quando engine salta N vezes, wrapper herda automaticamente.

**NAO confundir com**: produto-concorrente (Atlas nao concorre com engines; usa eles).

### Provider Lock

Configuracao fixa de provider+modelo durante UM run. Atlas Dev hoje tem `provider_lock = claude_cli + Sonnet, fallback_allowed = false`. Lock pode mudar entre runs via Atlas Decide; nao muda dentro de um run.

## Termos De Patamar E Versao

### Patamar

Identidade conceitual de salto evolutivo de uma engrenagem do Atlas. Tem nome proprio descritivo + frase de capability (ex: L0 "Tela Obras", L3 "ObraOS"). NAO usa "v1/v2". Mudanca de patamar = novo doc com identidade propria, nao "v+1".

Exemplo Obras: L0 "Tela Obras" -> L1 "Workspace Vivo" -> L2 "Obras Enterprise" -> L3 "ObraOS" -> L4 "Atlas Foundry" -> L5 "Atlas Sovereign OS".

**NAO confundir com**: versao tecnica de arquivo (-v1, -v2 no nome do .md sao convencao tecnica, nao patamar).

### Versao Tecnica De Arquivo

Sufixo `-v1`, `-v2`, etc. no nome do arquivo. Indica snapshot versionado dentro do **mesmo patamar**. Coexiste no mesmo diretorio. Quando muda patamar, cria-se arquivo novo com identidade propria, nao "-v2" do anterior.

## Termos De Fluxo, Surface, Domain, Capability

### Fluxo (flow_id)

Especializacao funcional dentro do Atlas AI. flow_ids canonicos: `atlas_dev`, `atlas_research`, `atlas_explain`, `atlas_debug`, `atlas_review`, `atlas_conversation`, `atlas_forge`. Cada um tem responsabilidade unica e contracts proprios.

### Surface

Ponto de entrada do operador no Atlas AI. surface_ids canonicos: `atlas_desktop_ai` (Atlas AI Desktop Mac), `atlas_cli_dev` (CLI), `atlas_app` (mobile), `atlas_api_interaction` (API), `atlas_mcp` (MCP — futuro).

**NAO confundir com**: fluxo (cada surface pode acionar qualquer fluxo via Router).

### Surface Adapter

Thin translator (< 200 LOC) entre payload nativo da surface e `OperationEnvelope` canonico. Vive em `app/Services/Ai/Programming/AtlasDev/Surface/`. Unico lugar autorizado a conhecer surface especifica.

### Domain

Vertical cognitiva do Atlas AI. Domains canonicos: Programming, Finance, Marketing, Personal Development, Strategic Decision, Research/Learning, Self-Improvement, Health/Writing. Atlas Dev e fluxo do domain Programming.

### Capability / Harness

Motor horizontal que serve multiplos domains. Capabilities: Programming Harness, Frontend Design Harness, Scenario Simulation Harness, Content Intelligence, Tool Synthesis Sandbox, Dynamic Compute Market, Continuous Multimodal Context.

**NAO confundir com**: domain (vertical), fluxo (especializacao funcional).

## Termos De Workspace E Contexto

### Workspace

Path absoluto do repositorio onde o operador trabalha (ex: `/Users/op/code/atlas-server`). Atlas Dev opera **workspace-bound**: exige workspace resolvido antes de qualquer write.

### Workspace-Bound

Pedido cujo escopo concreto envolve arquivos/simbolos/testes daquele workspace especifico. Distingue Atlas Dev (workspace-bound) de Atlas Research (conceitual, sem workspace).

### Business Context

Camada que identifica organizacao + projeto + ambiente + cliente. Vive no `OperationEnvelope.business_context`. Determina privacy_class, cost_budget e governance.

**NAO confundir com**: workspace (path tecnico), domain (vertical cognitiva).

## Termos De Risco E Governance

### R-Level

Classificacao de risco da tarefa: R0 (read-only), R1 (typo, 1 arquivo reversivel), R2 (bug pequeno 1-2 arquivos), R3 (3-5 arquivos, refactor leve), R4 (auth/billing/migration, >5-6 arquivos — escala para Forge), R5 (multiagente, replay obrigatorio — Forge-only).

### Decision Receipt v2

Tripla `(envelope_hash, prompt_projection_hash, task_contract_hash)` co-validados antes do Run executar. Nenhum runtime executa sem essa tripla. Estagio 11 do Kernel Pipeline canonico.

## Termos De Artefatos Atlas Dev (14 Canonicos)

### Camada Plano

- **OperationEnvelope**: entrada normalizada do intake (surface, workspace, intent, attachments, business_context, preflight).
- **CompactSDD**: classificacao + risco + scope mode + context budget + hashes.
- **MiniProgrammingSpec**: behavior contract (goal, assumptions, expected_files, acceptance, rollback). Obrigatorio para todo write.
- **LightTaskContract**: execution contract (allowed_tools, allowed/forbidden files, validation_commands, repair_policy, escalation_triggers, provider_lock, policy_profile).

### Camada Contexto

- **ContextRetrievalPlan**: tier selection + budget + missing sources.
- **CodeDiscoveryManifest**: likely files/symbols/tests com confidence levels.
- **OpenBrainProgrammingProjection**: projection compacta do Open Brain.
- **ProviderPromptProjection**: prompt deterministico projetado dos contratos. NAO improvisa.

### Camada Receipt

- **ScopeGuardReceipt**: diff vs LightTaskContract allowed/watched/forbidden.
- **VerificationReceipt**: gates + tests + cost + completion + escalation.
- **FailureCapsule**: input deterministico para repair.
- **EscalationDecision**: quando vira Forge (target, reasons, signals, score).

### Camada Telemetria

- **FastPathTelemetry**: sinal operacional emitido 1x por run.
- **FastPathErrorLedgerEntry**: registro append-only de falhas/missed escalations.

## Termos Legados Aceitos

### Fast Path

Sinonimo tecnico aceito para "fluxo Atlas Dev". Originalmente designava Atlas Dev em oposicao a Forge ("heavy path"). Termo legado mas mantido em decisoes locked, capabilities e graph paths.

**NAO confundir com**: produto leve (Atlas Dev nao e "Forge mais barato"; e fluxo de desenvolvimento workspace-bound).

### Heavy Path

Sinonimo legado para Atlas Forge. Designava governance pesada vs fast path. Hoje preferir "Forge" ou "fluxo Obra-driven".

## Resumo

Glossario canonico de termos do Atlas Dev e vizinhos com risco alto de confusao. Toda IA que mexer em Atlas Dev deve consultar este glossario antes de inventar interpretacao.

## Papel no Atlas

Garante alinhamento terminologico entre todos os docs do conjunto Atlas Dev e impede que IA externa confunda Atlas AI (produto) com Atlas Dev (fluxo) ou patamar (identidade conceitual) com versao tecnica.

## Onde Se Encaixa

Filho de `atlas-dev-index`. Irmao de `atlas-dev-policy` e `atlas-dev-patamares`. Pai conceitual dos demais docs do conjunto Atlas Dev (eles devem usar termos definidos aqui).

## Contratos

- Cada termo tem definicao unica AQUI; outros docs referenciam.
- Entradas "NAO confundir com" sao parte essencial da definicao.
- Termo novo em qualquer doc do conjunto Atlas Dev aparece aqui primeiro.

## Fluxo

IA encontra termo confuso -> consulta este glossario -> usa definicao canonica.

## Regras para IA

- Nao inventar significado de termo Atlas Dev sem consultar aqui.
- Nao definir termo de outra forma em outro doc do conjunto sem atualizar aqui primeiro.
- Reportar gap quando termo importante aparece sem entrada aqui.

## Escopo de Implementacao

Manutencao deste glossario. Significado canonico vive aqui.

## Dependencias

- `atlas-dev-index` (entrypoint)
- `atlas-ai-canonical-architecture-index` (hierarquia de autoridade)
- `atlas-ai-router-flow-routing-contract-v1` (fluxos canonicos)
- `atlas-ai-obras-operating-system` (patamares como modelo)

## Evidencias

- Existencia deste glossario.
- Uso consistente dos termos nos demais docs do conjunto Atlas Dev.

## Riscos

- Glossario ficar stale enquanto significados evoluem.
- IA usar termo sem consultar aqui (risco que este doc reduz, nao elimina).
- Adicao de termo aqui sem propagar pros docs donos.

## Exemplos

IA encontra "fast path" no doc principal Atlas Dev. Consulta aqui. Aprende: sinonimo tecnico aceito para "fluxo Atlas Dev". Nao confunde com "produto leve". Segue com interpretacao correta.

## Proximas Acoes

- Adicionar entrada para termos que aparecerem em docs novos do conjunto.
- Refinar "NAO confundir com" quando confusao recorrente for detectada.
- Promover entrada aqui para definicao operacional quando termo virar core.
