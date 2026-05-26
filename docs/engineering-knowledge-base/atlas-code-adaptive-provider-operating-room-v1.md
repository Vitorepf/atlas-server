---
id: atlas-code-adaptive-provider-operating-room-v1
type: engineering_knowledge
title: Atlas Code Adaptive Provider Operating Room v1
status: active
category: programming-forge
priority: 100
summary: Canonical contract for dynamic multi-provider role assignment inside Atlas Code: Atlas learns which available provider is best for each Project, Obra, work packet, role, risk and evidence regime, without hard-coding Claude/Codex/Gemini roles.
tags:
  - atlas-code
  - adaptive-provider-routing
  - provider-operating-room
  - programming-obras
  - atlas-decide
capabilities:
  - dynamic_provider_role_assignment
  - provider_performance_memory
  - multi_provider_work_packets
  - provider_operating_room
  - adaptive_subscription_safe_provider_use
decisions:
  - Atlas Code must become the provider operating-room surface above individual providers, not a fixed wrapper around Claude, Codex or Gemini.
  - Provider identity and provider role are separate concepts; every provider is a candidate for each role, subject to evidence, capacity, constraints and human override.
  - Interactive providers such as Claude Code must enter the Operating Room as observed sessions with packet export, terminal launch, result import, gates and evidence.
  - Atlas Decide owns provider assignment; providers never own scope, acceptance, merge, completion, evidence policy or final authority.
  - Defaults such as Codex-orchestrator or Claude-builder are bootstrap hints only; runtime selection must be dynamic when enough evidence exists.
  - Every provider assignment must produce a decision receipt with rationale, constraints, confidence and fallback behavior.
  - Every completed provider run must update a provider performance ledger or an explicit no-signal receipt.
  - The human sees one operating room view per Obra, not scattered terminal tabs, provider chats and hidden background loops.
maintenance:
  - Update before changing Provider Topology, Provider Capacity, Provider Arena, Work Packets, Atlas Decide, Claude subscription governance or Atlas Code multi-provider UX.
related_paths:
  - docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
  - docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
  - docs/engineering-knowledge-base/atlas-code-attention-control-plane-v1.md
  - docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
  - docs/engineering-knowledge-base/atlas-claude-code-subscription-governance-v1.md
  - docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md
  - docs/engineering-knowledge-base/atlas-forge-provider-capacity-continuity-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md
  - docs/engineering-knowledge-base/atlas-code-provider-arena-ui-v1.md
  - docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md
  - app/Services/Ai/Programming/AtlasForgeProviderTopologyService.php
  - app/Services/Ai/Programming/AtlasForgeProviderCapacityService.php
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php
  - ../atlas-desktop/apps/desktop/src/surfaces/code/panels/
owner: programming
layer: 2.4-provider-operating-room
line_limit: 520
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-adaptive-provider-operating-room-v1
graph_title: Atlas Code Adaptive Provider Operating Room v1
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-code-programming-obras-operating-system
graph_status: active
graph_source: repo
human_name: Atlas Code Adaptive Provider Operating Room v1
canonical_name: Atlas Code Adaptive Provider Operating Room v1
technical_name: atlas-code-adaptive-provider-operating-room-v1
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-code-adaptive-provider-operating-room-v1.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-code-adaptive-provider-operating-room-v1.md
allowed_changes:
  - Add provider roles, scoring dimensions, packet states, UI panels and read-model fields when implementation and tests follow.
  - Add new provider families as candidates through registry/capacity/ledger, not hard-coded role ownership.
forbidden_changes:
  - Hard-code Claude, Codex, Gemini or any provider as permanent owner of a role.
  - Let a provider decide its own authority, completion, merge, scope expansion or acceptance.
  - Run fallback silently or hide provider substitution from the human.
  - Treat provider performance memory as final authority instead of advisory signal for Atlas Decide.
  - Bypass subscription constraints, API/PAYG bans, privacy policy, evidence gates or human approval because a provider scored highly.
  - Force the human to monitor multiple provider sessions as live multitasking.
depends_on:
  - atlas-code-programming-obras-operating-system
  - atlas-code-attention-control-plane-v1
  - atlas-forge-provider-topology-and-fallback-v1
  - atlas-forge-provider-capacity-continuity-v1
  - atlas-forge-rivals-provider-performance-ledger-v1
flows_to:
  - atlas-code
  - atlas-decide
  - provider-topology
  - provider-arena
  - attention-control-plane
unlocks:
  - adaptive-provider-routing
  - multi-provider-obra-execution
  - provider-performance-learning-loop
  - software-production-os-above-models
governs:
  - atlas_code.provider_operating_room
  - atlas_decide.dynamic_provider_assignment
  - programming_forge.work_packet_provider_roles
evidence:
  - docs/engineering-knowledge-base/atlas-code-adaptive-provider-operating-room-v1.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - atlas-code
  - provider-operating-room
  - adaptive-routing
ai_entrypoints:
  - Leia este doc antes de implementar multi-provider na mesma Obra, dynamic provider role assignment, provider board, work packets ou adaptive provider learning.
ai_usage_notes:
  - Nunca interprete provider roles como cargos fixos. Role slot e provider identity sao entidades separadas.
quality_gates:
  - role-provider-separation
  - atlas-decide-assignment-receipt
  - provider-capacity-checked
  - performance-ledger-advisory-only
  - no-silent-fallback
  - human-attention-serialized
failure_modes:
  - Voltar para "Claude builder, Codex reviewer, Gemini research" como politica fixa.
  - Criar uma tela com muitas IAs piscando e transformar o operador em supervisor multitarefa.
  - Aprender sinal errado por score pequeno, tarefa mal classificada ou sucesso sem testes.
  - Escolher provider disponivel mas sem capacidade real para a categoria/risco.
  - Fazer uma Obra depender de uma ferramenta externa como fonte da verdade.
observability_signals:
  - obra_id
  - project_id
  - work_packet_id
  - task_signature_hash
  - provider_role_assignment_id
  - role_slot
  - selected_provider
  - selected_model
  - invocation_mode
  - confidence
  - selection_reason_codes
  - capacity_snapshot_id
  - ledger_signal_id
  - fallback_event_id
  - human_override_receipt_id
next_actions:
  - Criar read-model `atlas.code.provider_operating_room.v1`.
  - Expandir Provider Topology para consumir assignment dinamico de Atlas Decide antes de qualquer static policy.
  - Implementar Provider Board no RightRail com roles, packets, confidence, capacity, ledger signal e estado interativo observado.
---
# Atlas Code Adaptive Provider Operating Room v1
## Resumo
O Atlas Code nao deve ser "Claude Code com uma UI melhor", "Codex com obras" ou "Gemini com cartografia". A tese correta e mais alta:
```text
Atlas Code e a surface/cockpit de producao de software do Atlas Agentic
Engineering OS.
Providers sao motores substituiveis dentro de uma Obra governada.
Atlas decide, mede, coordena, valida e aprende.
```
Isso e o caminho para sair mais forte de qualquer limitacao de provider. Se Claude muda billing, Codex muda capacidade, Gemini melhora em pesquisa ou surge outro motor superior, o Atlas nao quebra. Ele registra capacidade, compara evidencia, atribui papeis e aprende onde cada provider e melhor.
## Papel no Atlas
Este modulo e a camada de producao multi-provider dentro de Programming Obras. Ele fica acima de Provider Topology, Capacity, Arena e Governed Invocation, e abaixo da decisao humana final.
Papel pratico:
- transformar uma Obra em work packets governados;
- atribuir role slots a providers por evidencia e capacidade;
- mostrar a operacao da Obra sem virar painel de logs;
- alimentar ledger/memoria para que o Atlas melhore apos cada entrega.

## Principio Maximo

O objetivo maximo permanece:

```text
Atlas Code deve ser a forma mais poderosa do mundo para desenvolvimento
agentico de software em tarefas ultra-hard, contextos longos,
sessoes longas e entregas dificeis.
```

Multi-provider so importa se aumenta essa meta. Ele nao existe para ter varios logos na tela. Ele existe para produzir software melhor, com menos perda de contexto, menos multitarefa humana, mais evidencia e mais continuidade que usar Claude Code, Codex, Gemini ou qualquer provider diretamente.

## Tese Central

Separar duas coisas:

```text
Role Slot != Provider Identity
```

Role slot e a funcao operacional que a Obra precisa agora. Provider identity e o motor candidato para executar essa funcao.

Role slots canonicos incluem `architecture_lead`, `implementation_lead`, `critical_reviewer`, `context_scout`, `test_writer`, `repair_agent`, `ui_polish`, `security_reviewer`, `performance_reviewer`, `integration_owner`, `documentation_writer` e `challenger`.

Provider identities incluem `codex_cli`, `claude_cli`, `gemini_cli`, `claude_codex`, `atlas-local` e futuros providers.

Regra:

```text
Nenhum provider e dono permanente de um papel.
Todo papel e atribuido por Atlas Decide a partir de evidencia, contexto,
capacidade, risco, restricoes e decisao humana.
```

## Por Que Isso Supera Usar Providers Direto

Claude Code, Codex e Gemini trabalham dentro da sessao deles. Atlas trabalha acima das sessoes:

- preserva Projeto, Obra, escopo, plano, evidencias e historico;
- divide uma entrega longa em work packets rastreaveis;
- escolhe provider por papel e por evidencias, nao por preferencia;
- cria revisao cruzada entre motores;
- registra onde cada provider foi bom ou ruim;
- impede fallback silencioso;
- protege o humano de acompanhar cinco conversas ao mesmo tempo;
- transforma resultados em memoria operacional reutilizavel.

Essa e a diferenca de categoria:

```text
Provider direto gera resposta.
Atlas governa producao.
```

## Onde Se Encaixa

```text
Project/Workspace
-> Programming Obra
-> Work Packet
-> Task Signature
-> Dynamic Provider Role Assignment
-> Provider Topology
-> Governed Invocation / Interactive Observed Session
-> Evidence + Review + Performance Ledger
-> Atlas Decide Learning Loop
```

Provider Topology continua existindo, mas vira read-model de uma decisao mais forte. A camada nova e o assignment dinamico antes da topologia.

## Contratos

### `atlas.code.dynamic_provider_role_assignment.v1`

```json
{
  "schema_version": "atlas.code.dynamic_provider_role_assignment.v1",
  "assignment_id": "dpra_<ulid>",
  "project_id": "<project>",
  "obra_id": "<obra>",
  "work_packet_id": "<packet|null>",
  "decision_receipt_id": "<receipt>",
  "task_signature": {
    "task_category": "frontend|backend|bugfix|refactor|feature|test|docs|devops|security|architecture|performance|unknown",
    "framework": "react|laravel|swift|python|null",
    "risk_band": "low|medium|high|critical",
    "scope_hash": "sha256",
    "context_pack_hash": "sha256",
    "required_capabilities": ["implementation", "review", "long_context"]
  },
  "role_slots": [
    {
      "role_slot": "implementation_lead",
      "selected_provider": "codex_cli",
      "selected_model": "selected-by-decide",
      "invocation_mode": "interactive_observed|governed_cli|local|manual_import",
      "confidence": "low|medium|high",
      "selection_reason_codes": ["best_recent_project_score", "capacity_ok"],
      "fallback_chain": ["claude_cli", "gemini_cli"],
      "human_review_required": true
    }
  ],
  "constraints": {
    "subscription_only": true,
    "api_fallback_allowed": false,
    "silent_fallback_allowed": false,
    "operator_presence_required": true
  },
  "memory_inputs": {
    "capacity_snapshot_id": "<id>",
    "ledger_signal_id": "<id|null>",
    "failure_memory_refs": []
  }
}
```

### `atlas.code.provider_performance_memory.v1`

Performance memory e uma projecao acima do ledger. Ela nunca decide sozinha.

Campos minimos:

- `provider`;
- `model`;
- `role_slot`;
- `project_id`;
- `task_category`;
- `framework`;
- `risk_band`;
- `sample_count`;
- `quality_score_avg`;
- `gate_pass_rate`;
- `repair_rate`;
- `scope_violation_rate`;
- `human_intervention_avg`;
- `duration_p50_ms`;
- `latest_evidence_age_days`;
- `confidence`;
- `valid_for_decide`.

Fallback hierarquico quando ha poucos dados:

```text
project + role + category + risk
-> project + role + category
-> framework + role + category
-> global role + category
-> insufficient_evidence
```

## Fluxo

1. Atlas classifica o work packet: categoria, framework, risco, escopo, contexto, gates e sensibilidade.
2. Atlas cria role slots necessarios para aquele pacote.
3. Atlas consulta Provider Capacity: disponivel, degradado, indisponivel, cooldown, blocker.
4. Atlas consulta Failure Memory para evitar repetir falha recente.
5. Atlas consulta Provider Performance Memory como sinal advisory.
6. Atlas aplica restricoes por fase: sem API/PAYG silencioso, headless apenas quando aprovado para Rivals/teste antes do cutoff/flag, privacidade, budget, presenca humana quando o modo exigir.
7. Atlas calcula ranking por role slot.
8. Se houver baixa confianca, Atlas usa exploracao controlada: reviewer alternativo, mini-packet, challenger ou arena local.
9. Atlas emite Decision Receipt com selected provider, razoes, constraints e fallback.
10. Runtime executa via modo permitido: interativo observado, CLI governado, manual import ou local.
11. Atlas valida diff, testes, evidencias, relatorio e review.
12. Atlas atualiza ledger/memoria com resultado ou registra `no_valid_signal`.

## Work Packets

Work packet e a unidade que permite varias IAs cooperarem sem caos.

Cada packet deve ter:

- objetivo curto;
- contexto minimo suficiente;
- arquivos permitidos e proibidos;
- interfaces/contratos afetados;
- restricoes;
- acceptance criteria;
- comandos de verificacao;
- formato de relatorio;
- regra de parada;
- role slot esperado;
- provider assignment;
- evidence required.

Estados:

```text
draft
-> ready_for_assignment
-> assigned
-> running_observed
-> waiting_provider_report
-> report_imported
-> diff_detected
-> gates_running
-> review_required
-> accepted
-> repair_required
-> rerouted
-> blocked
```

## Operating Room UX

A tela nao deve mostrar "varias IAs trabalhando" como entretenimento. Deve mostrar o minimo operacional para governar uma Obra dificil.

Blocos canonicos:

- **Obra Header**: objetivo, fase, risco, progresso provado, proximo passo seguro.
- **Provider Board**: role slots, provider atribuido, modo, confianca, motivo, capacity e status.
- **Work Packets**: pacote ativo, pacote em revisao, pacote bloqueado e pacote pronto.
- **Observed Sessions**: provider interativo aguardando operador, rodando, aguardando relatorio ou importado.
- **Cross Review**: quem construiu, quem revisou, divergencias e verdict.
- **Evidence Strip**: testes, lint, diff, build, docs, receipts e gaps.
- **Human Decision Queue**: uma unica decisao atual, nunca varias.

Acoes permitidas:

- gerar packet;
- copiar/open packet no provider;
- importar relatorio;
- analisar diff;
- rodar gates;
- pedir reviewer/challenger;
- reroute governado;
- override humano com motivo;
- atualizar score do provider;
- bloquear por capacidade/risco.

Acoes proibidas:

- "run all providers" sem escopo e sem receipt;
- fallback automatico invisivel;
- completion por texto do provider;
- UI que exige olhar varios terminais ao mesmo tempo;
- provider escolher seu proprio sucessor.

## Read-Model e API

O Operating Room deve ser um read-model agregado, sem substituir os blocos atuais de Topology, Capacity, Arena ou Attention.

Endpoint esperado:

```text
GET /api/atlas-code/works/{project}/forge/operating-room
```

Shape minimo: `obra`, `lifecycle`, `provider_board`, `work_packets`, `cross_reviews`, `evidence_spine`, `attention`, `safety_summary`, `health`.

`provider_board` contem providers, roles, observed sessions, capacity, topology e learning signals. `work_packets` contem packets, dependencies, collision map e integration queue. `attention` contem active decision, queue e allowed actions.

Acoes mutantes devem ser endpoints separados e sempre produzir receipt:

```text
POST /works/{project}/forge/work-packets
POST /works/{project}/forge/work-packets/{packet}/dispatch
POST /works/{project}/forge/work-packets/{packet}/review
POST /works/{project}/forge/work-packets/{packet}/repair
POST /works/{project}/forge/decisions/{decision}/act
```

## Estados Canonicos

Provider: `eligible`, `assigned`, `queued`, `running`, `streaming`, `producing_artifact`, `waiting_provider_confirmation`, `waiting_budget_confirmation`, `waiting_runtime_dispatch_confirmation`, `blocked_capacity`, `cooldown`, `stale`, `failed`, `completed`, `quarantined`.

Work packet: `draft`, `ready`, `claimed`, `running`, `blocked_scope`, `blocked_collision`, `waiting_cross_review`, `repair_requested`, `verified`, `integrated`, `accepted`, `rejected`, `rolled_back`.

Evidence/review: `missing`, `partial`, `strong`, `invalid`, `stale`, `conflict_needs_human`.

## Regras para IA

- Nunca fixe Claude, Codex, Gemini ou qualquer provider como dono permanente de um papel.
- Nunca deixe provider decidir escopo, merge, completion, aceite ou proprio fallback.
- Nunca use performance memory como autoridade final; ela e sinal advisory.
- Nunca esconda capacity, cooldown, fallback, baixa confianca ou falta de evidencia.
- Nunca force o humano a acompanhar varias sessoes como multitarefa.
- Em subscription-only, respeite Claude Code interativo observado para uso diario e preserve `claude -p`/Agent SDK apenas quando explicitamente aprovados para Rivals/teste antes do cutoff/flag. API/PAYG continua sem fallback silencioso.

## Escopo de Implementacao

Inclui Dynamic Provider Role Assignment, Provider Board, Work Packet Board, Observed Sessions, Cross Review Lane, Evidence Spine e read-model `atlas.code.provider_operating_room.v1`.

Nao inclui benchmark competitivo da Arena, billing/API extra, IA local como fallback default, completion automatica ou substituicao da Attention Control Plane.

## Dependencias

- Programming Obras e Project/Workspace ativo.
- Provider Topology, Capacity, Failure Memory e Governed Invocation.
- Provider Performance Ledger e Decide Signal Projection.
- Attention Control Plane para serializar decisoes humanas.
- Work Packet Builder, Context Compiler, gates e Evidence Ledger.

## Evidencias

Cada assignment ou execucao deve registrar: `obra_id`, `project_id`, `work_packet_id`, `provider_role_assignment_id`, selected provider/model, invocation mode, confidence, selection reasons, capacity snapshot, ledger signal, fallback event e human override receipt.

## Riscos

- Overload humano se a UI virar feed de agentes.
- Overfitting se poucos runs criarem falsa certeza.
- Provider forte mas indisponivel ser escolhido por reputacao antiga.
- Bootstrap virar politica fixa.
- Subscription-only ser violado por caminho headless produtivo silencioso.
- Rivals/testes programaticos serem quebrados antes do cutoff por bloqueio prematuro.
- Completion nascer de texto do provider, sem gates e sem aceite.

## Exemplos

Correto: Atlas cria packet de frontend, classifica risco medio, escolhe provider por role slot, manda builder executar, manda reviewer independente revisar, roda gates e so entao pede aceite humano.

Errado: "Claude sempre implementa, Codex sempre revisa" ou "rode todos e veja quem resolve" sem packet, receipt, capacity, evidence e attention gate.

## Criterios de Perfeicao

Atlas esta vencendo os providers diretos quando:

- uma Obra longa sobrevive a troca de provider sem perder contexto;
- o humano entende o estado em 30 segundos;
- a melhor IA para cada papel muda conforme evidencias reais;
- bugs pequenos nao viram Obras pesadas;
- Obras grandes nao viram chats soltos;
- revisao cruzada encontra falhas que um provider sozinho perderia;
- cada decisao importante tem receipt;
- cada alegacao tem evidencia;
- a atencao humana permanece serializada;
- o sistema melhora depois de cada entrega.

## Proximas Acoes

P0: contrato canonico e doc.

P1: read-model `provider_operating_room` agregando Topology, Capacity, Ledger, Work Packets e Attention.

P2: Provider Board no Atlas Code RightRail usando tokens visuais existentes.

P3: Work Packet Builder com export/import para providers interativos observados.

P4: Performance Memory projection por Projeto, categoria, role slot, risco e framework.

P5: Atlas Decide passa a emitir Dynamic Provider Role Assignment antes da Provider Topology.

P6: Cross Review e Challenger mode por packet critico.

P7: adaptive routing com exploracao controlada, decay temporal e anti-gaming.

P8: Operating Room completo: varias IAs cooperam, mas o humano recebe uma unica decisao por vez.

## Lei Final

```text
Atlas nao deve depender de qual provider e melhor hoje.
Atlas deve ser o sistema que descobre, governa e usa o melhor provider
para cada parte de cada Obra, preservando contexto, evidencia e atencao.
```
