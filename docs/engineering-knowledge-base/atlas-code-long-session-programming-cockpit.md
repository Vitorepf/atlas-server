---
id: atlas-code-long-session-programming-cockpit
type: engineering_knowledge
title: Atlas Code SCOR-1 Long Session Programming Cockpit
status: future
category: surface
priority: 99
summary: Contrato canonico do Atlas Code SCOR-1, Software Construction Operating Room v1, para sessoes longas e dificeis de programacao assistida por IA, com contexto navegavel, spec/plan/tasks vivos, task contracts, checkpoints, scope guard, gates, evidence, repair e cartografia de execucao.
tags:
  - atlas-code
  - programming
  - long-session
  - ai-assisted-programming
  - cockpit
capabilities:
  - atlas_code_scor_1
  - software_construction_operating_room_v1
  - long_session_programming
  - governed_ai_coding_cockpit
  - programming_session_memory
  - evidence_driven_execution
decisions:
  - Esta versao canonica do Atlas Code se chama Atlas Code SCOR-1, Software Construction Operating Room v1.
  - Atlas Code deve suportar sessoes longas e dificeis como cockpit operacional, nao apenas conversa governada.
  - SDD, Plan, Verify e Evidence devem ser objetos operacionais navegaveis, versionados e auditaveis.
  - Sessao longa precisa preservar contexto, decisoes, checkpoints, escopo, gates, falhas e evidence entre pausas, retomadas e providers.
maintenance:
  - Atualize quando Atlas Code, Programming Governance, Forge, Code Intelligence, Evidence Ledger ou Cartography mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-code-scor-1-implementation-contract.md
  - docs/engineering-knowledge-base/atlas-desktop-code-surface.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/atlas-ai-continuity-session-state.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-code-long-session-programming-cockpit

graph_title: Atlas Code SCOR-1 Long Session Programming Cockpit

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-desktop-code-surface

graph_status: future

graph_source: repo

owner: atlas-ai

repo_paths:
  - docs/engineering-knowledge-base/atlas-code-long-session-programming-cockpit.md
  - docs/engineering-knowledge-base/atlas-code-scor-1-implementation-contract.md

allowed_changes:
  - Atualizar contrato quando a tela Atlas Code ganhar novos paineis, objetos ou gates de sessao longa.

forbidden_changes:
  - Tratar barra visual SDD como suficiente sem objetos reais por tras.
  - Declarar suporte a sessao longa sem checkpoint, scope guard, evidence e completion gate.

depends_on:
  - atlas-desktop-code-surface
  - atlas-programming-governance-system
  - code-intelligence

flows_to:
  - atlas-code
  - atlas-forge-operating-system

unlocks:
  - difficult-ai-programming-sessions
  - governed-long-running-coding

governs:
  - atlas-code.long-session

evidence:
  - docs/engineering-knowledge-base/atlas-code-long-session-programming-cockpit.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:engineering:knowledge sync --prune --json"

requires_evidence: true

risk_level: high

visual_tags:
  - scor-1
  - atlas-code
  - programming
  - long-session

ai_entrypoints:
  - Leia este doc antes de implementar Atlas Code para sessoes longas, SDD UI, gates, evidence, repair ou cartografia de execucao.

ai_usage_notes:
  - Esta especificacao define o que precisa existir para sessoes dificeis; nao declara runtime pronto.

quality_gates:
  - context-pack-visible
  - spec-plan-tasks-operational
  - task-contract-present
  - checkpoint-resume-ready
  - scope-guard-visible
  - gate-runner-operational
  - evidence-ledger-complete

failure_modes:
  - Atlas Code parecer cockpit, mas operar como chat sem objetos persistentes.
  - IA perder contexto em sessao longa e repetir leitura, alterar escopo ou esquecer decisao.
  - Verify/Evidence mostrar texto narrativo sem receipts verificaveis.

observability_signals:
  - context pack freshness
  - live task contract status
  - checkpoint count
  - scope violations
  - gate runs
  - evidence receipts
  - repair loop decisions

next_actions:
  - Implementar primeiro a fatia one-shot definida em atlas-code-scor-1-implementation-contract.md.
---
# Atlas Code SCOR-1 Long Session Programming Cockpit

Nome canonico desta versao:

```text
Atlas Code SCOR-1
Software Construction Operating Room v1
```

SCOR-1 e o primeiro patamar enterprise do Atlas Code: a sala operacional onde
software e especificado, contratado, executado, verificado, evidenciado e
aprendido por IA.

## Resumo

Este documento define o que falta para Atlas Code suportar programacao
assistida por IA em sessoes longas, dificeis e de alto risco. A tela atual ja
tem a base certa: Obras, sessao, SDD steps, provider receipt, budget, core
local, fila, docs, workspace, assinatura e evidence. O proximo salto e fazer
cada etapa visual virar objeto operacional profundo.

## Papel no Atlas

Atlas Code deve ser o cockpit onde um humano consegue dirigir uma obra de
programacao longa sem depender da memoria fragil de uma conversa. A IA precisa
ver contexto real, spec viva, plano, tasks, escopo, checkpoints, gates, falhas,
repair e evidence. O humano precisa auditar tudo visualmente.

## Onde Se Encaixa

Este contrato e filho de `atlas-desktop-code-surface` e materializa, na UI, o
runtime definido por Programming Governance. Ele prepara o caminho para Forge
OS, mas nao exige fabrica multiagente completa para entregar valor.

## Contratos

### 0. Obras Como Primitiva De Producao

Atlas nao precisa copiar a ideia generica de "workspace, nao chat". A
documentacao canonica de Obras ja define Obra como primitiva operacional de
producao: ela transforma intencao em delivery/asset por meio de contexto,
estrutura, fontes, decisoes, tarefas, versoes, gates, evidence e output.

O workspace compartilhado e uma camada dentro desse fluxo:

```text
Obra = unidade/production graph
Obras Shared Workspace = escritorio compartilhado para trabalho longo/multiagente
Forge Workspace = especializacao de programming
Chat/terminal/SDD panes = interfaces/projecoes vinculadas a Obra
```

A cadeia principal e:

```text
Intencao -> Obra -> production graph -> sessoes/artefatos/contratos
-> execucoes -> evidence -> cartografia -> delivery -> asset
```

Portanto, Atlas Code SCOR-1 deve renderizar trabalho a partir de `obra_id`,
node, contexto, output e evidence, nao a partir de uma thread solta. Conversa
sem vinculo a esses elementos e rascunho/loose chat; trabalho governado vive na
Obra e pode usar chat como uma interface.

### 1. Context Pack Visivel E Navegavel

A tela deve mostrar exatamente qual contexto foi carregado: docs canonicas,
arquivos, simbolos, rotas, comandos, migrations, testes, decisions, receipts e
links de Code Intelligence. Cada item precisa indicar freshness, origem, hash,
status stale/missing e motivo de inclusao.

### 2. Spec, Plan E Tasks Como Objetos Vivos

`SPEC`, `PLAN` e `TASKS` nao podem ser apenas etapas na barra SDD. Devem ser
objetos editaveis ou revisaveis, versionados, hashados, linkados ao receipt e
comparaveis contra docs canonicas. Mudanca em spec invalida plano/tasks ate
revalidacao.

### 3. Task Contract Por Etapa

Cada task precisa declarar objetivo, allowed files, forbidden files, comandos de
validacao, acceptance, riscos, evidence esperada, docs afetadas, cartografia
afetada e criterio de completion. Sem task contract, a IA nao executa trabalho
estrutural.

### 4. Checkpoint E Resume

Sessao longa precisa salvar onde parou, proxima acao segura, arquivos em risco,
decisoes tomadas, contexto ja lido, gates falhos, approvals pendentes, comandos
rodados, gaps e risco residual. Resume deve reconstruir estado operacional, nao
apenas abrir o historico do chat.

### 5. Diff E Scope Guard

A tela deve mostrar arquivos alterados, diff, ownership, status in-scope,
adjacent, needs-replan, forbidden ou unknown. Alteracao fora de scope bloqueia
completion ou exige replan/approval.

### 6. Gate Runner Real

A aba `VERIFY` deve rodar e mostrar gates: docs-health, sync, index-code,
testes, lint/build quando existir, scope validation, CI, secret/privacy check,
cartography impact e completion gate. Gate pulado precisa de motivo.

### 7. Evidence Ledger Detalhado

A aba `EVIDENCE` deve listar receipts por comando, teste, diff, decisao, falha,
retry, review, assinatura e publicacao. "Pronto" sem evidence e estado
invalido.

### 8. Long Session Memory Operacional

Memoria de sessao nao e memoria generica. Ela registra decisoes, hipoteses
descartadas, arquivos ja lidos, riscos conhecidos, alternativas rejeitadas,
motivo da abordagem escolhida, provider usado e handoff para proxima sessao.

### 9. Failure E Repair Loop

Falha deve virar decisao operacional: retry, repair, replan, pedir contexto,
mudar provider, rodar teste especifico, escalar para review ou bloquear. Retry
sem evidence nova nao conta.

### 10. Cartografia De Execucao

Para trabalho dificil, Atlas Code deve renderizar o grafo:
spec -> plan -> tasks -> files/symbols -> tests -> evidence -> gates -> release.
Zoom em uma task deve esconder o resto e mostrar fluxo completo da engrenagem.

### 11. Streaming UI Como Fundamento

Streaming de UI deve ser comportamento basico do Atlas Code. Quando a IA cria
spec, plano, task, gate, evidence, diff ou cartografia, a tela nao deve esperar
um texto final para depois interpretar. Ela deve receber eventos/partes
estruturadas e atualizar a Obra em tempo real.

Exemplos:

- spec aparece enquanto esta sendo compilada;
- plano ganha fases e riscos incrementalmente;
- task contract mostra allowed/forbidden files assim que sao decididos;
- Verify mostra gates em `pending`, `running`, `passed`, `failed` ou `skipped`;
- Evidence anexa receipts enquanto comandos terminam;
- Cartografia cria nos/arestas progressivamente.

Streaming UI no Atlas nao e animacao cosmetica. E a forma de tornar trabalho
agente observavel enquanto acontece.

### 12. Artefatos Persistentes Da Obra

Artefato persistente e qualquer objeto operacional produzido ou alterado dentro
de uma Obra que precisa sobreviver ao chat, reload, troca de provider,
checkpoint e retomada.

Artefatos basicos de Atlas Code SCOR-1:

- `ContextPackArtifact`;
- `SpecArtifact`;
- `PlanArtifact`;
- `TaskContractArtifact`;
- `DecisionReceiptArtifact`;
- `GateRunArtifact`;
- `DiffScopeArtifact`;
- `EvidenceReceiptArtifact`;
- `CheckpointArtifact`;
- `RepairDecisionArtifact`;
- `CartographyArtifact`;
- `LearningProposalArtifact`.

Cada artefato precisa de id, obra id, sessao id, tipo, status, origem, hash,
versao, timestamps, links para pais/filhos, sensitivity, evidence refs e
rendering state. Se nao e persistente, nao e confiavel para sessao longa.

### 13. Atlas Operational Artifact DSL

Atlas pode precisar de uma DSL propria para IA gerar artefatos operacionais.
Essa DSL nao serve para a IA gerar HTML/React livre. Ela serve para a IA emitir
uma linguagem pequena, tipada e validavel que o Atlas transforma em paineis
seguros.

Nome canonico candidato:

```text
Atlas Operational Artifact DSL
```

O que a DSL permitiria gerar:

- cards de spec, plano, task e receipt;
- tabelas de gates, comandos, arquivos e riscos;
- timelines de execucao;
- forms de approval/clarification;
- diffs e scope status;
- grafo de cartografia;
- listas de evidence;
- paineis de provider/agent status;
- alertas de failure/repair.

O que a DSL nao pode permitir:

- HTML arbitrario;
- script livre;
- tool call escondido;
- escrita em arquivo;
- leitura de segredo;
- componente fora da biblioteca aprovada;
- estado nao persistido na Obra.

Exemplo conceitual:

```text
artifact SpecCard id=spec_123 title="Programming Governance"
section "Escopo" status=ready
gate "Code Intelligence" status=running evidence=pending
task "Implement intake" allowed=["app/Services/..."] risk=medium
```

O renderer do Atlas validaria isso contra schemas e componentes permitidos. A
IA gera intencao de interface; Atlas decide se aquilo vira UI.

## Fluxo

Fluxo de sessao longa:

```text
obra -> intent -> context pack -> streaming spec/plan/tasks -> task contract
-> persistent artifacts -> checkpoint -> execution -> diff/scope guard
-> verify -> evidence -> repair/replan quando necessario
-> completion gate -> cartography -> learning
```

## Regras Para IA

- Nao tratar barra SDD como contrato.
- Nao executar mudanca estrutural sem task contract.
- Nao continuar sessao longa sem checkpoint/restauracao de estado.
- Nao declarar completion se Verify ou Evidence estao vazios.
- Nao esconder contexto stale, missing ou degradado.
- Nao transformar repair em patch solto sem revalidar spec, scope e evidence.
- Nao renderizar UI gerada por IA fora da biblioteca/DSL permitida.
- Nao tratar artefato nao persistido como fonte confiavel.

## Escopo De Implementacao

Implementar em Atlas Code:

- painel de Context Pack;
- editor/visor de Spec, Plan e Tasks;
- painel de Task Contract;
- Checkpoint/Resume;
- Diff/Scope Guard;
- Verify Gate Runner;
- Evidence Ledger;
- Long Session Memory;
- Failure/Repair Loop;
- Execution Cartography.
- Streaming UI de artefatos operacionais;
- Atlas Operational Artifact DSL.

## Dependencias

- Atlas Desktop Code Surface;
- Atlas Programming Governance System;
- Code Intelligence;
- Evidence Ledger;
- Continuity Session State;
- Cartographic Knowledge OS;
- Forge OS para evolucao posterior.

## Definition Of Done

Atlas Code esta pronto para sessoes longas quando uma IA consegue retomar uma
obra dificil depois de pausa, entender contexto carregado, ver spec/plano/tasks,
executar task contract, provar gates/evidence, reparar falhas e publicar mapa
de execucao sem depender da memoria do chat.

## Evidencias

Evidencias aceitas:

- capturas da UI mostrando paineis preenchidos por dados reais;
- receipts de context pack, task contract, gate run, diff/scope, evidence e
  checkpoint;
- testes de API/servico quando houver backend;
- docs-health e sync apos alteracoes canonicas.

## Riscos

- UI bonita com objetos falsos.
- IA repetir trabalho por falta de checkpoint.
- Spec/plano/task driftarem sem invalidador.
- Diff fora de scope passar como melhoria.
- Evidence virar resumo narrativo em vez de ledger.

## Exemplos

Correto: uma obra longa mostra context pack com freshness, spec hash, tasks com
allowed files, checkpoint atual, gates executados e receipts de evidence.

Incorreto: a barra SDD marca `VERIFY` como concluido porque o agente escreveu
"testado", mas nao existe gate run, comando, diff scope ou evidence receipt.

## Proximas Acoes

1. Implementar a thin slice do `atlas-code-scor-1-implementation-contract.md`.
2. Ligar a UI a comandos reais `atlas:programming:*` quando o backend expuser endpoints seguros.
3. Criar AP para Context Pack Panel e Task Contract Panel avancados.
4. Criar AP para Verify/Evidence como objetos reais com streaming.
5. Criar AP para checkpoint/resume e execution cartography.
