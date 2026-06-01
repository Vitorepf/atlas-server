---
id: atlas-code-programming-obras-operating-system
type: engineering_knowledge
title: Atlas Code Programming Obras Operating System
status: active
category: programming-forge
priority: 100
summary: Canonical doctrine for Programming Obras in Atlas Code: the Forge specialization of Obras that turns software intent into governed, verified, evidence-backed delivery while protecting human attention.
tags:
  - atlas-code
  - programming
  - obras
  - forge
  - attention-control-plane
capabilities:
  - programming_obras
  - programming_obras_atlas_code
  - programming_obras_forge_workspace
  - programming_obras_attention_control
  - ai_governed_software_production
decisions:
  - O termo Operating System neste doc e escopo local de Programming Obras dentro da surface Atlas Code; ele nao compete com Atlas Agentic Engineering OS, Programming Governance ou Forge Continuum.
  - Atlas Code exists primarily for heavy programming, ultra-hard software problems and extremely long AI-assisted development sessions; this is the maximum product priority.
  - Programming Obras must belong to a selected software Project/Workspace; Atlas, Blackink and other products are Projects, not Obras.
  - Programming Obras are a specialization of Obras, not a separate product primitive.
  - Atlas Code is the desktop cockpit for Programming Obras; it is not the Obra itself and not a provider session.
  - A Programming Obra is complete only when delivery, gates, evidence and human approval agree.
  - Multiple Programming Obras may run in parallel, but human decisions must be serialized through an attention layer.
  - Interactive providers such as Claude Code must be used through observed provider sessions: Atlas prepares packet, terminal, prompt, import, gates and evidence while the human performs required interactive actions.
  - The market-level gap is no longer code generation alone; it is governed production from intent to trusted delivery.
maintenance:
  - Update before changing Atlas Code, Forge Workspace, Programming Governance, Obra Command Center, Attention Control Plane, completion gates or multi-Obra UX.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
  - docs/engineering-knowledge-base/atlas-code-adaptive-provider-operating-room-v1.md
  - docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
  - docs/engineering-knowledge-base/atlas-code-attention-control-plane-v1.md
  - docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
  - docs/engineering-knowledge-base/obras/patamares-l0-l5.md
  - docs/engineering-knowledge-base/obras/contracts-and-invariants.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-code-obra-command-center-v1.md
  - docs/engineering-knowledge-base/atlas-code-forge-human-first-ux-orchestrator-v1.md
  - docs/engineering-knowledge-base/atlas-code-forge-work-intake-spec-governance-v1.md
  - docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md
  - docs/engineering-knowledge-base/atlas-code-scor-1-implementation-contract.md
  - ../atlas-desktop/apps/desktop/src/surfaces/code/
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
owner: programming
layer: 2.2-obras-programming
line_limit: 520
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-programming-obras-operating-system
graph_title: Atlas Code Programming Obras Operating System
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-programming-forge-flow
graph_status: active
graph_source: repo
human_name: Atlas Code Programming Obras Operating System
canonical_name: Atlas Code Programming Obras Operating System
technical_name: atlas-code-programming-obras-operating-system
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
allowed_changes:
  - Refine the Programming Obra doctrine when runtime, evidence, UX or governance changes.
  - Add child docs for Attention Control Plane, portfolio queue, Obra economics or multi-provider workspace when implemented.
forbidden_changes:
  - Treat this doc as the mother OS for Agentic Software Engineering, Programming Governance or Forge.
  - Dilute Atlas Code into a generic IDE, generic chat product, lightweight coding assistant or broad productivity surface.
  - Treat a product/workspace/repository as a single Programming Obra.
  - Treat a Programming Obra as a chat, provider session, branch, ticket, terminal tab or generic project card.
  - Declare completion without review gate, evidence, output and human approval.
  - Let the human become a live monitor for parallel agent work.
  - Let Atlas make value, risk, scope or final acceptance decisions without explicit human authority.
depends_on:
  - atlas-code-multi-project-workspace-os
  - atlas-code-adaptive-provider-operating-room-v1
  - atlas-ai-obras-operating-system
  - atlas-programming-forge-flow
  - atlas-code-obra-command-center-v1
flows_to:
  - atlas-code
  - forge-workspace
  - attention-control-plane
  - atlas-foundry
unlocks:
  - governed-ai-software-production
  - multi-obra-attention-routing
  - programming-obras-portfolio
governs:
  - atlas_code.programming_obras
  - programming.forge.obra_boundary
evidence:
  - docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
evidence_refs:
  - symbol: AtlasCodeAttentionControlPlaneService
  - command: atlas:code:obra-command-center
  - test: AtlasCodeAttentionControlPlaneTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - atlas-code
  - programming
  - obras
ai_entrypoints:
  - Leia este doc antes de explicar ou alterar Obras de Programacao, Atlas Code, Forge Workspace, Attention Control Plane ou UX multi-Obra.
ai_usage_notes:
  - Este doc separa Obra geral de Obra de Programacao. Use os docs filhos para runtime executavel e evidencia.
  - Se houver duvida de hierarquia, o Authority Map vence: este doc e local a Atlas Code/Programming Obras.
quality_gates:
  - docs-health
  - evidence-backed-completion
  - human-approval-boundary
  - attention-protection-boundary
failure_modes:
  - Transformar Obras de Programacao em chat agrupado por projeto.
  - Confundir Atlas Code com provider executor.
  - Criar paralelismo operacional que vira multitarefa humana.
  - Usar progresso, gates ou evidence fake para parecer maduro.
observability_signals:
  - obra_id
  - work_item_id
  - spec_hash
  - plan_hash
  - gate_run_ids
  - evidence_refs
  - review_packet_id
  - human_decision_receipt
  - attention_queue_item_id
next_actions:
  - Implementar Provider Operating Room com role slots dinamicos, work packets e Provider Board.
  - Projetar read-model global de decisoes humanas por Obra.
  - Mapear status de Programming Obra para fila anti-multitarefa.
---
# Atlas Code Programming Obras Operating System

## Resumo

Nota de autoridade: este nome historico usa `Operating System` para o escopo
local de Programming Obras dentro do Atlas Code. Ele nao e o sistema-mae da
area; a hierarquia completa vive em
`atlas-agentic-software-engineering-authority-map.md`.

Obras de Programacao sao a especializacao de Obras para software. A unidade de trabalho deixa de ser chat, prompt, ticket, branch, PR ou terminal, e passa a ser uma entrega governada que preserva objetivo, contexto, plano, execucao, revisao, prova e aceite humano.

Obras de Programacao vivem dentro de um Projeto/Workspace selecionado. `Atlas` e `Blackink` sao Projetos; uma Obra e um trabalho governado dentro deles.

Prioridade maxima:

```text
Atlas Code existe para programacao pesada:
problemas ultra-hard, contextos longos, sessoes longas,
entregas dificeis e software assistido por IA no limite superior.
```

Se o Atlas Code tivesse que ser reconhecido mundialmente por uma unica coisa, seria por ser a ferramenta mais poderosa para desenvolvimento de software assistido por IA em tarefas extremamente dificeis e contextos longos. Qualquer feature, tela, UX ou runtime deve servir a esse objetivo.

Tese central:

```text
Sem Obra, IA programa por sessao.
Com Obra, IA programa por producao.
Com Attention Control Plane, o humano governa sem multitarefa.
```

## Papel no Atlas

O sistema geral de Obras define o primitivo de producao do Atlas. Programming Obras aplica esse primitivo ao dominio de programacao:

```text
Obra
-> Obras Shared Workspace
-> Forge Workspace
-> programming.forge
-> Atlas Code
```

Atlas Code e a cabine desktop da Obra de Programacao. Ele mostra a Obra, conduz intake, prepara Forge, acompanha execucao, traduz bloqueios, apresenta provas e pede decisoes humanas. Ele nao e a Obra em si, nao e um provider, nao e apenas chat e nao e apenas terminal.

## Onde Se Encaixa

Programming Obras conecta quatro sistemas:

- Obras OS: identidade, lifecycle, workspace, output intent e estado persistente.
- Programming Forge Flow: execucao pesada, repair loop, provider topology, harness, code graph e gates.
- Atlas Code: superficie humana para comando, foco, revisao, provas e decisoes.
- Attention Control Plane: fila global de atencao humana entre varias Obras.
- Adaptive Provider Operating Room: atribuicao dinamica de providers por work packet, role slot, risco, capacidade e evidencia.

A diferenca essencial:

```text
Atlas trabalha em paralelo.
O humano decide em serie.
```

## Contratos

Uma Obra de Programacao deve conter no minimo:

- objetivo;
- tipo de entrega;
- escopo dentro e fora;
- regra que nao pode quebrar;
- criterios de aceite;
- contexto tecnico;
- plano atual;
- status e fase;
- proximo passo seguro;
- bloqueios traduzidos;
- gates;
- provas;
- decisao humana final.

Completion law:

```text
Uma Obra de Programacao nao termina quando o codigo muda.
Ela termina quando entrega, gates, provas e aceite humano concordam.
```

Evidence law:

```text
Toda alegacao de progresso precisa apontar para evidencia.
Sem evidencia, e estado de trabalho; nao e conclusao.
```

Attention law:

```text
Varias Obras podem executar ao mesmo tempo.
O humano nao deve acompanhar varias Obras ao mesmo tempo.
O sistema deve trazer apenas a proxima decisao humana relevante.
```

## Fluxo

Lifecycle canonico aplicado a programacao:

1. Intake: humano define objetivo, restricoes, escopo, regra que nao pode quebrar e aceite.
2. Architecture: Atlas entende repo, riscos, dependencias, contratos e estrategia.
3. Forge Prep: Atlas prepara plano, capacidade, provider, budget, runtime e dispatch.
4. Build: Forge executa mudancas, testes, reparos e iteracoes.
5. Review: Atlas revisa pacote, riscos, diffs, gates e regressao.
6. Proofs: sistema organiza evidencia de execucao, testes, auditorias e artefatos.
7. Decision: humano aprova, rejeita, pede reparo, amplia escopo ou pausa.
8. Learning: Obra vira memoria operacional, padrao, doc, regra ou ativo reaproveitavel.

O Command Center deve responder em ate 30 segundos:

- o que esta sendo construido;
- qual fase esta ativa;
- o que ja esta provado;
- o que ainda nao esta provado;
- o que bloqueia;
- qual decisao humana e necessaria;
- qual e o proximo passo seguro.

## Regras para IA

- Nunca chame thread, chat, PR, branch ou provider session de Obra.
- Nunca declare pronto sem prova e aceite.
- Nunca transforme o humano em monitor de terminal.
- Nunca esconda bloqueio tecnico; traduza para acao humana.
- Nunca misture provas desta Obra com certificacoes globais do Atlas.
- Nunca use progresso global unico quando readiness e delivery comprovada divergem.
- Sempre preserve a fronteira: Atlas recomenda e executa; humano governa valor, risco, escopo e aceite.

## Escopo de Implementacao

Escopo atual:

- Obra Command Center no Atlas Code.
- Intake governado para programming.forge.
- Lifecycle de 8 fases.
- Chat composer com papeis semanticos.
- Paineis de decisao, verificacao, provas e avancado.
- Separacao entre prova da Obra e certificacao do sistema.

Escopo recomendado a seguir:

- Attention Control Plane como nova surface/top tab ao lado de Cartografia e Atlas Code.
- Fila global de decisoes humanas por Obra.
- Modo foco com uma unica Obra que precisa de atencao.
- Politica anti-multitarefa para pessoas com carga cognitiva alta ou TDAH.
- Portfolio de Obras com status, risco, valor e capacidade.
- Foundry layer para reaproveitar entregas como ativos.

Contrato implementavel para IA:

- Nao criar um novo app; implementar no Atlas Desktop existente.
- Nao substituir Atlas Code; criar uma surface de atencao complementar.
- Manter Atlas Code como cockpit avancado de uma Obra.
- Manter a tela normal de Atlas Code capaz de mostrar varias Obras, execucoes e estado geral.
- Usar Attention Control Plane para mostrar somente o que precisa de acao humana agora.
- Nunca mostrar terminal como objeto principal da surface de atencao.
- Toda decisao exibida deve apontar para uma Obra e para uma acao permitida.
- Toda acao humana deve gerar receipt, mesmo quando for rejeitar, pausar ou pedir reparo.

Top-level navigation esperada:

```text
Cartografia | Atlas Code | Atencao
```

Responsabilidades das telas:

- Cartografia: mapa cognitivo, relacoes, conhecimento e contexto estrutural.
- Atlas Code: cockpit profundo da Obra de Programacao, com intake, Forge, provas, revisao, avancado e terminal dock.
- Atencao: fila humana serializada, com uma decisao por vez ou poucas decisoes agrupadas por prioridade.

Schema minimo de item de atencao:

```text
attention_queue_item {
  id
  obra_id
  obra_title
  kind
  severity
  human_question
  why_now
  allowed_actions
  recommended_action
  risk_if_ignored
  evidence_refs
  expires_at
  created_at
}
```

Tipos minimos de atencao:

- intake_needed: Atlas precisa que o humano defina melhor objetivo, regra ou aceite.
- scope_decision: escopo mudou ou ha risco de expandir a Obra.
- risk_approval: Atlas detectou risco tecnico, produto, custo ou contrato.
- provider_approval: execucao exige provider/modelo/custo que precisa de autorizacao.
- runtime_approval: execucao exige ferramenta, terminal, filesystem, rede ou runtime sensivel.
- review_needed: ha pacote pronto para revisao humana.
- repair_decision: entrega falhou gate e precisa de reparo, rollback ou rejeicao.
- final_acceptance: todas as provas existem e falta aceite humano.

Estados minimos da Obra para roteamento:

```text
no_obra -> intake_required -> ready_to_define -> ready_to_prepare
-> ready_to_execute -> running -> waiting_review
-> repair_required | final_acceptance | completed | rejected | rolled_back
```

Criterios de aceite para implementacao:

- Uma IA deve conseguir localizar este doc a partir de Obras OS, Forge Flow ou Obra Command Center.
- Uma IA deve saber que Obra de Programacao nao e chat, PR, branch, terminal nem provider session.
- Uma IA deve implementar a nova surface como `Atencao`, sem destruir Atlas Code.
- Uma IA deve preservar a fronteira Atlas/humano.
- Uma IA deve criar actions somente a partir de `allowed_actions`.
- Uma IA deve exigir evidence refs para review, completion e final acceptance.
- Uma IA deve manter o humano em fluxo serial, nao em monitoramento paralelo.

## Dependencias

Depende de:

- `atlas-code-attention-control-plane-v1`;
- `atlas-ai-obras-operating-system`;
- `obras/shared-workspace-and-forge`;
- `obras/patamares-l0-l5`;
- `atlas-programming-forge-flow`;
- `atlas-code-obra-command-center-v1`;
- `atlas-code-forge-human-first-ux-orchestrator-v1`;
- `atlas-code-forge-review-completion-gate-v1`.

## Evidencias

Evidencias atuais:

- `AtlasCodeObraCommandCenterService` ja modela fases, status, decision inbox, blockers, trust summary e progressos separados.
- Atlas Code desktop ja tem slots para Obra, rail esquerdo, palco, rail direito e terminal dock.
- `ForgeHumanPanel`, `ForgeWorkIntakePanel`, `VerifyPanel` e `EvidencePanel` ja separam comando humano, intake, revisao e provas.
- `atlas-programming-forge-flow` ja declara Obra como unidade produtiva obrigatoria do Forge.

## Riscos

Riscos principais:

- UI virar painel de tudo rodando e aumentar ansiedade cognitiva.
- Obra virar apenas agrupamento visual de chats.
- Atlas assumir decisoes humanas de valor ou risco.
- Progresso parecer completo sem gates e provas.
- Attention Control Plane virar inbox generica, em vez de roteador de decisoes humanas.

## Exemplos

Exemplo de Obra de Programacao:

```text
Objetivo: implementar completion audit profissional.
Regra que nao pode quebrar: nao promover completion sem evidencia.
Escopo dentro: service, testes, docs e command output.
Escopo fora: UI desktop e providers reais.
Aceite: testes verdes, docs-health verde, output auditavel e review aprovado.
```

Exemplo de decisao humana correta:

```text
Atlas: ha risco de alterar contrato publico.
Humano: aprova mudanca de contrato porque o ganho compensa o risco.
Atlas: executa, prova e apresenta evidencias.
```

## Proximas Acoes

1. Implementar `atlas-code-attention-control-plane-v1.md` no runtime.
2. Definir schema persistente de `attention_queue_item` com Obra, decisao, risco, prazo e acao permitida.
3. Mapear fases e status atuais do `AtlasCodeForgeUxOrchestratorService` para tipos de atencao humana.
4. Desenhar top-level tab `Atencao` no Atlas Desktop sem substituir Atlas Code.
5. Validar fluxo multi-Obra: Atlas paralelo, humano serializado, evidencia sempre rastreavel.
