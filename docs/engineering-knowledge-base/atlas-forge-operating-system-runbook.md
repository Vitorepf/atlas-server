---
id: atlas-forge-operating-system-runbook
type: engineering_knowledge
title: Atlas Forge Operating System Runbook
status: active
category: programming-forge
priority: 98
summary: Runbook operacional do Forge OS: intake, modulos, fluxo integrado, DoD, evidence, repair loop, telemetry e cartografia.
tags:
  - atlas
  - forge
  - programming
  - runbook
  - software-factory
capabilities:
  - forge_runbook
  - forge_execution_flow
  - forge_repair_loop
  - forge_release_gate
decisions:
  - Forge OS so deve ser acionado quando a complexidade exige fabrica, nao por reflexo.
  - O fluxo completo e obrigatorio para multiagente, brownfield pesado, self-construction e alta criticidade.
  - Rerun e repair usam o mesmo contrato ou criam delta governado.
maintenance:
  - Atualize quando fluxo, modulos, DoD, telemetry ou cartografia do Forge mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system-contracts.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-operating-system-runbook
graph_title: Atlas Forge Operating System Runbook
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-forge-operating-system
graph_status: active
graph_source: repo
human_name: Atlas Forge Operating System Runbook
canonical_name: Atlas Forge Operating System Runbook
technical_name: atlas-forge-operating-system-runbook
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-forge-operating-system-runbook.md

owner: programming

repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-operating-system-runbook.md

allowed_changes:
  - Atualizar fluxo operacional do Forge quando comandos, gates ou modulos reais forem implementados.

forbidden_changes:
  - Tratar este runbook futuro como runtime entregue.
  - Rodar provider externo ou acao destrutiva sem contrato, approval e evidence.

depends_on:
  - atlas-forge-operating-system
  - atlas-forge-operating-system-contracts
  - atlas-programming-governance-system

flows_to:
  - atlas-code
  - atlas-cartographic-knowledge-os

unlocks:
  - forge-operational-readiness-context

governs:
  - programming.forge.runbook

evidence:
  - docs/engineering-knowledge-base/atlas-forge-operating-system-runbook.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - forge
  - runbook
  - release

ai_entrypoints:
  - Leia este doc antes de desenhar fluxo Forge, runners, integration queue ou release gate.

ai_usage_notes:
  - Este runbook descreve o alvo operacional futuro; nao declara execucao pronta.

quality_gates:
  - constitution-loaded
  - code-intelligence-ready
  - dependency-dag-valid
  - scope-validator-pass
  - model-routing-recorded
  - dry-run-clean
  - verification-complete
  - integration-clean
  - rollback-plan-present
  - ci-pipeline-green
  - learning-captured

failure_modes:
  - Forge acionado para patch pequeno.
  - Rerun sem evidence nova.
  - Cartografia publicada sem link para artifacts.
  - Telemetry ausente impede learning.

observability_signals:
  - forge run status
  - packet throughput
  - dependency DAG status
  - failed gates
  - retry counts
  - model routing decisions
  - dry-run receipts
  - cartography publications

next_actions:
  - Converter este runbook em AP executavel quando Forge runtime entrar no caminho critico.
---
# Atlas Forge Operating System Runbook

## Resumo

Este documento define o fluxo operacional do Forge OS. Contratos persistentes
vivem em `atlas-forge-operating-system-contracts.md`, o indice canonico vive em
`atlas-forge-operating-system.md` e a taxonomia completa de programacao pesada
vive em `atlas-programming-forge-flow.md`.

## Papel no Atlas

Converter os contratos do Forge em uma sequencia operacional verificavel para
trabalho multiagente, integracao, release, cartografia e learning.

## Onde Se Encaixa

Este runbook e filho de `atlas-forge-operating-system.md` e consome
`atlas-forge-operating-system-contracts.md`. Ele nao substitui Programming
Governance; ele descreve como a fabrica deve operar quando a governanca decidir
que Forge completo e necessario.

## Contratos

- Trabalho pequeno nao aciona Forge completo por reflexo.
- Trabalho complexo exige constitution pack, spec-mae, packets, reservas,
  permission gate, verification, evidence e release gate.
- Rerun e repair usam contrato existente ou criam repair delta governado.
- Cartografia e learning sao saidas de release quando afetados.

## Fluxo

O fluxo detalhado esta nas secoes de modulos e no fluxo final integrado. A regra
curta e: intake decide, spec orienta, splitter divide, packet limita, runner
executa, verifier prova, integration queue converge e release gate fecha.

O fluxo inteiro de programacao pesada fica em `atlas-programming-forge-flow.md`.
Quando houver divergencia de nomenclatura, aquele doc vence a taxonomia; este
runbook vence a ordem operacional interna do Forge OS.

## Regras para IA

- Nao executar provider externo sem approval, custo e data boundary.
- Nao aceitar screenshot, log ou diff como evidence unica quando gates pedem
  comportamento, a11y, performance, docs ou cartografia.
- Nao continuar rerun infinito; falha repetida vira learning proposal.
- Nao publicar cartografia sem links para artifacts reais.

## Escopo de Implementacao

Este runbook orienta APs e runtime futuro de Forge. Ele pode ser usado para
desenhar comandos, services, tests e read models, mas nao autoriza execucao real
sem contrato implementado e gates verdes.

## Dependencias

- Forge index;
- Forge contracts;
- Programming Governance System;
- Code Intelligence;
- Tool Runtime;
- Evidence Ledger;
- Cartographic Knowledge OS.

## Evidencias

Evidencias aceitas: forge run status, packet receipts, tool run outputs,
verification reports, integration queue status, release receipts, telemetry,
learning proposals e cartography publications.

## Riscos

- Usar Forge para tarefa simples e aumentar friccao.
- Executar runner sem data boundary.
- Rerun sem evidence nova.
- Publicar release sem docs/index/cartography quando afetados.

## Exemplos

Exemplo valido: spec-mae divide tres packets sem conflito, cada packet roda
testes focados, integration queue aplica em ordem, release gate registra risco
residual e cartography publication aponta artifacts.

Exemplo invalido: abrir varias sessoes de agente no mesmo arquivo sem
reservation ledger e tentar reconciliar apenas por conversa.

## Sintese Das Ferramentas Dissecadas

| Ferramenta | Valor para Forge OS | Modulo Forge impactado |
|---|---|---|
| Spec Kit | Fluxo constitution -> spec -> plan -> tasks -> implement | Mother Spec Compiler, Packet Planner |
| OpenSpec | Delta specs para brownfield e propostas aplicaveis | Change Delta Compiler, Impact Queue |
| BMAD-METHOD | Time virtual, papeis, handoffs e coordenacao | Agent Role Router, Review Orchestrator |
| Goose | Runtime provider/tool/MCP agnostico | Tool Runtime Gateway, Provider Adapter |
| Aider | Edicao incremental, Git/diff discipline, repo context | Patch Executor, Repair Loop, Diff Guard |
| Tessl SDD Tile | Spec-anchored prompts e registry de conhecimento | Spec Anchor Store, External Contract Registry |
| Reqnroll | BDD executavel e living behavior specs | Behavior Verification Runner |
| Gauge | Markdown executable specs, runner/plugin/event system, rerun failed | Event Bus, Runner Gateway, Evidence Normalizer |

## Modulos Operacionais

### Forge Intake

Decide se uma tarefa precisa de Forge completo. Tipos:

- `small_patch`;
- `structural_change`;
- `brownfield_delta`;
- `multi_agent_project`;
- `repair_loop`;
- `refactor`;
- `documentation_cartography_update`;
- `release_integration`;
- `self_construction`.

Conclusao: tarefas pequenas usam governanca compacta; tarefas complexas entram
em fabrica.

### Constitution And Steering Loader

Carrega leis canonicas antes de qualquer provider:

- Programming Governance System;
- Spec Operating System;
- Engineering Blueprint;
- Code Intelligence;
- Self-Construction contracts;
- AGENTS/context files quando existirem;
- security/privacy policies;
- cartography contracts;
- dissection docs relevantes.

Saidas: `forge_constitution_pack`, `steering_context`,
`non_negotiable_rules`, `allowed_autonomy_level`, `required_gates` e
`context_loading_policy`.

### Mother Spec Compiler

Transforma intencao grande em spec-mae com mission, contexto canonico, dominios
afetados, delta model, acceptance, riscos, packet strategy, validation,
evidence, cartography e completion definition.

### Spec Anchor Store And Template Registry

Guarda specs, templates, prompts, rules e skills de governanca. Detecta drift
entre spec, codigo, testes, docs e cartografia.

### Code Intelligence Loader

Monta contexto real antes de dividir ou executar:

- context pack;
- affected file map;
- symbol map;
- test impact map;
- doc link map;
- risk map;
- forbidden zone map.

### Work Splitter

Divide por dominio, camada, arquivo/simbolo, risco, fase, provider ou ordem
serial quando houver dependencia forte.

### Dependency DAG And Scheduler

Ordena packets por dependencia, prioridade, risco, custo e caminho critico.
Define `parallel`, `serial`, `shadow` ou `hold`; sem DAG valido nao ha
paralelismo.

### Packet Contract Engine

Gera contratos executaveis para cada packet usando o modelo definido em
`atlas-forge-operating-system-contracts.md`.

### Context Budget And Model Router

Seleciona provider/modelo por packet e registra contexto, tool calls, runtime,
custo, retry, compressao, privacy boundary e escalation.

### Dry Run Simulator

Para alto risco, simula split, DAG, claims, permissoes, modelo, CI, integracao,
rollback e cartografia. Falha vira replan ou escalation.

### Tool Runtime Gateway

Executa ferramentas e agentes via interface comum:

- shell;
- git;
- tests;
- browser;
- code index;
- docs health;
- BDD runner;
- cartography renderer;
- LSP/code intelligence;
- MCP servers/resources/prompts/UI/artifacts;
- external research quando permitido;
- AI provider call quando aprovado.

### Patch Executor And Diff Guard

Aplica mudancas pequenas, revisaveis e rastreaveis; compara diff com packet
contract; preserva mudancas existentes que nao pertencem ao agente.

### Scope Validator And Completion Reporter

Classifica mudancas como allowed, adjacent, needs replan, forbidden ou unknown;
todo packet termina com receipt de status, gaps, evidence e handoff.

### Behavior Verification Runner

Roda unit, feature/integration, browser/e2e, BDD/Gherkin, executable Markdown,
CLI gates e docs-health/code-index conforme risco.

### Event Bus And Evidence Normalizer

Registra eventos como `mother_spec_created`, `constitution_loaded`,
`packet_created`, `reservation_created`, `permission_requested`,
`checkpoint_saved`, `runner_started`, `patch_submitted`,
`verification_failed`, `evidence_received`, `integration_queued`,
`release_gate_passed`, `learning_proposed` e `cartography_published`.

### Checkpoint, Resume And Cancellation Manager

Persistencia minima:

- mother spec id;
- active packet ids;
- claims/reservations;
- last tool calls;
- current artifacts;
- pending approvals;
- failed gates;
- integration queue state;
- cost/telemetry snapshot;
- next safe action.

### Branch, Worktree And CI Pipeline Adapter

Escolhe direct patch, patch artifact, branch/worktree, integration ou shadow.
Roda lint, typecheck, tests, build, docs-health, code-index e gates de risco.

### Review, Quality And Rollback Managers

Review revisa tecnica, arquitetura, security, tests, docs, cartografia, scope e
migration. Quality consolida gates; Rollback/Migration declara reversao,
rollout, blast radius e monitoramento.

### Learning And Self-Improvement Loop

Extrai missing context, missing gate, repeated repair, weak spec, poor split,
provider mismatch, test gap, docs drift, cartography gap e cost anomaly.

### Failure Taxonomy, Prompt Recipes And Evals

Classifica falhas e decide repair, retry, replan ou escalation. Versiona
prompts/recipes/skills/templates e mede providers, prompts, splits e gates.

### Cartography Publisher

Publica mapa por obra/projeto, dominio, modulo, packet, arquivo/simbolo,
teste/evidence e decision/learning.

## Fluxo Final Integrado

Ordem canonica: Intake -> Constitution/Steering -> Sovereign/Epistemic
Preflight -> Mother Spec -> Spec Lookup -> Code Intelligence -> Delta ->
Splitter -> Dependency DAG -> Packet Contract -> Reservation -> Role/Model
Router -> Capability/Permission/Dry-Run -> Branch/Worktree quando necessario ->
Runtime -> Patch -> Scope Validator -> Verification -> Completion Evidence ->
Event/Evidence -> Artifact/Checkpoint -> Review -> Integration -> Quality/CI ->
Rollback/Migration -> Release -> Learning -> Prompt Recipes/Evals ->
Docs/Code Intelligence/Cartography.

## Definition Of Done Canonico

Forge OS so esta concluido como sistema quando:

- existe intake que decide quando acionar Forge;
- constitution/steering context e carregado antes de agentes;
- mother spec e obrigatoria para trabalho Forge;
- spec anchor store consulta specs/templates existentes;
- work splitter gera packets com escopo claro;
- dependency DAG/scheduler ordena por dependencia, risco e prioridade;
- packet contracts incluem arquivos permitidos/proibidos;
- reservation ledger detecta colisoes;
- agent role router separa papel, provider e permissao;
- context budget/model router registra modelo, custo e janela por packet;
- capability registry filtra tools por packet;
- permission/sandbox gate protege comandos, escrita, rede e segredos;
- dry-run simulator existe para alto risco;
- branch/worktree/CI strategy isola execucao paralela quando necessario;
- tool runtime gateway executa runners com output estruturado;
- patch executor valida diff contra contrato;
- scope validator classifica toda mudanca relevante;
- behavior verification runner roda gates proporcionais;
- completion reporter fecha cada packet com receipt estruturado;
- evidence normalizer unifica outputs de providers;
- artifact store registra proveniencia, hashes e relacoes;
- checkpoint manager permite retomar/cancelar com seguranca;
- review orchestrator revisa por risco;
- integration queue governa convergencia;
- quality gate runner/CI adapter consolida gates;
- rollback/migration manager define contencao e reversao;
- release gate bloqueia conclusao incompleta;
- failure taxonomy define rotas de repair/escalation;
- learning extractor cria propostas pos-execucao;
- prompts/recipes/templates sao versionados;
- evals medem providers, prompts, splits e custo quando aplicavel;
- cartography publisher mostra o trabalho visualmente;
- telemetry mede custo, duracao, qualidade e retrabalho.

## Gaps Que Nao Podem Ser Esquecidos

- Spec Kit: mother spec, plan e tasks como base dos packets.
- OpenSpec: delta model para brownfield.
- BMAD: papeis e handoffs.
- Goose: runtime provider/tool/MCP agnostico, permission inspector, context management, recipes e sessions.
- Aider: diff pequeno, repo-aware e reparo iterativo.
- Tessl: spec anchoring, rules, skills e knowledge registry.
- Reqnroll: behavior specs executaveis.
- Gauge: event bus, runner/plugin lifecycle, rerun failed, executable Markdown, concepts, formatter, refactor e LSP.
- Atlas proprio: cartografia operacional e self-construction.
- Self-Construction OS: durable reservations, packet queue, assignment/claim,
  scope validator, completion gate, dry-run e evidence receipts.
- Agent Control Plane: scheduler, heartbeat, work products, cost tracking,
  process supervision e resumability.
- Enterprise operation: CI, rollback, migration safety, retention, compliance e
  provider evals.

## Quality Gate Matrix

Gates obrigatorios por perfil: constitution, mother spec, existing spec lookup,
delta, Code Intelligence, packet scope, dependency DAG, reservation, scope
validator, model routing, permission, secret safety, dry-run, patch scope,
behavior verification, completion receipt, evidence, integration, CI, rollback,
docs, code index, cartography, prompt recipe version, eval, learning e release.

Cada gate precisa de evidence id. Gate pulado sem motivo vira falha.

## Rerun Failed E Repair Loop

Forge deve suportar:

```text
rerun failed packet
rerun failed tests
rerun failed docs gate
rerun failed cartography publish
rerun failed integration queue item
```

Regras:

- rerun usa o mesmo packet contract ou cria repair delta;
- rerun registra nova evidence;
- retry automatico tem limite;
- falha repetida vira learning proposal;
- high-risk rerun exige review ou escalacao.

## Telemetry E Economia Operacional

Forge deve medir:

- tempo por packet;
- custo por provider;
- retrabalho;
- taxa de falha;
- quantidade de retries;
- gates mais falhos;
- arquivos mais conflituosos;
- qualidade por provider/papel;
- evidence completeness.
- scope violation rate;
- first-pass acceptance;
- time to integration;
- rollback rehearsal pass rate;
- prompt/provider eval results.

Sem telemetry, Forge nao aprende a usar melhor agentes e ferramentas.

## Proximas Acoes

1. Criar AP especifica antes de transformar este runbook em runtime.
2. Implementar primeiro read models e gates, depois execucao.
3. Exigir evidence real antes de declarar Forge OS pronto.
