---
id: atlas-dual-core-engineering-system
type: engineering_knowledge
title: Atlas Dual-Core Engineering System
status: active
category: programming
priority: 100
summary: Contrato canonico da convivencia Dev↔Forge (e do terceiro executor Autônomos) como runtimes user-space elite sob o mesmo bar mundial. Fronteira, roteamento, evidence compartilhada e escalonamento Dev→Forge sem fusao de identidade. Identidade dos tres executores owner em atlas-elite-executors-dev-forge-autonomos.md.
tags:
  - atlas
  - atlas-dev
  - atlas-forge
  - dual-core
  - programming
  - engineering-os
capabilities:
  - dual_core_engineering_system
  - atlas_dev_autonomous_fast_engineering
  - atlas_forge_autonomous_heavy_engineering
  - dev_forge_boundary_contract
  - dev_to_forge_escalation
  - shared_evidence_contract
decisions:
  - Dev · Forge · Autônomos são os três executores de engenharia de elite (mesmo bar mundial L0–L5). Diferença = presença humana no loop de engenharia + escala/duração + origem do trabalho — não ranking de ambição nem de qualidade.
  - Atlas Dev é fábrica elite com operador presente na intenção — não é "fast patch", "produto leve" nem qualidade inferior.
  - Atlas Forge é fábrica elite para obras enormes/longas — operador só no planejamento/soberania.
  - Autônomos é executor elite zero-operador no loop de engenharia (cérebro atlas:brain + músculo atlas:task); mesma barra L0–L5; default 24/7.
  - Owner da identidade dos tres: atlas-elite-executors-dev-forge-autonomos.md.
  - Atlas Dev e Forge são runtimes user-space completos sob Constitution, Mission Control, Policy Plane, Engineering Kernel, Spec Court, Verification Court e Governor.
  - Atlas Dev nao e Forge mini, Forge nao e gerente do Dev, e nenhum dos dois deve ser fundido em um sistema unico.
  - Na arquitetura v3, Dev e Forge sao runtimes user-space sob Constitution, Mission Control, Policy Plane, Engineering Kernel, Spec Court, Verification Court e Governor.
  - O roteamento Dev/Forge pertence a Mission Control/Policy Plane; o Dual-Core define identidade e contrato, nao cria um OS acima do governo.
  - Dev e Forge nao podem possuir provider direto, `verified=true`, main/release entry ou promocao de memoria canonica.
  - Autonomos / Self-Construction e o terceiro runtime user-space para Atlas 24/7 sem operador; ele nao deve ser chamado de Loop.
  - A relacao correta e interoperabilidade por contratos: roteamento, evidence, escalation packet, intake packet, receipts e governance refs.
  - Atlas Dev deve resolver tarefas claras, pequenas, medias ou ambiguas de escopo limitado com Senior Engineer Loop, teste e recibo.
  - Atlas Forge deve resolver obras grandes, longas, ambiguas, multi-modulo, multiagente, enterprise ou com SDD/governanca pesada.
  - Quando Atlas Dev detectar que a tarefa virou Obra, ele deve parar de crescer lateralmente e gerar Dev -> Forge Escalation Packet.
  - Quando Atlas Forge precisar de execucao tatica curta, ele pode produzir work packets compativeis com Dev, mas sem depender do Dev para ser completo.
  - Evidencia pode ser compartilhada; identidade, runtime, UX e criterios de sucesso continuam separados.
maintenance:
  - Atualize este doc antes de alterar a fronteira entre Atlas Dev e Atlas Forge.
  - Atualize este doc quando surgir novo artefato de escalonamento, roteamento, evidence ou intake.
  - Nao use este doc para substituir os docs-mae de Atlas Dev ou Atlas Forge; ele governa apenas a relacao entre os dois.
related_paths:
  - docs/engineering-knowledge-base/atlas-elite-executors-dev-forge-autonomos.md
  - docs/engineering-knowledge-base/atlas-autonomos-live-system.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system-contracts.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system-runbook.md
  - docs/engineering-knowledge-base/atlas-programming-self-construction-forge-map-v1.md
  - docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-ai-router-flow-routing-contract-v1.md
  - docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
  - docs/engineering-knowledge-base/atlas-programming-domain-adapter-integration-plan.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dual-core-engineering-system
graph_title: Atlas Dual-Core Engineering System
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-programming-forge-flow
graph_status: active
graph_source: repo
human_name: Atlas Dual-Core Engineering System
canonical_name: Atlas Dual-Core Engineering System
technical_name: atlas-dual-core-engineering-system
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
allowed_changes:
  - Refinar criterios de roteamento Dev vs Forge.
  - Adicionar schemas de packets e receipts quando forem implementados.
  - Atualizar matriz de testes quando a integracao Dev/Forge evoluir.
forbidden_changes:
  - Declarar que Dev e apenas uma feature interna do Forge.
  - Declarar que Forge e apenas um orquestrador que manda tarefas para Dev.
  - Fundir Dev e Forge em um unico runtime sem boundary contract.
  - Fazer Dev/Forge bypassarem Engineering Kernel, Spec Court, Verification Court, Governor ou Policy Plane.
  - Tratar o Dual-Core como arquitetura superior ao Atlas Autonomous Engineering Government.
  - Permitir escalonamento silencioso sem packet, receipt e motivo auditavel.
  - Permitir que Dev execute Obra longa sem promover para Forge.
depends_on:
  - atlas-elite-executors-dev-forge-autonomos
  - atlas-hyperflow-operation
  - atlas-dev-efficient-programming-flow-v1
  - atlas-forge-continuum-os
  - atlas-programming-self-construction-forge-map-v1
  - atlas-ai-router-flow-routing-contract-v1
flows_to:
  - atlas_dev
  - atlas_forge
  - dev_forge_boundary
  - dev_to_forge_escalation
  - shared_programming_evidence
unlocks:
  - clear_dev_forge_identity
  - no_product_confusion
  - route_session_intent_to_dev
  - route_long_obra_to_forge
  - governed_interoperability
  - elite_same_bar_dev_forge_autonomos
governs:
  - atlas.dual_core_engineering
  - atlas_dev_to_forge.boundary
  - atlas_programming.routing
  - atlas_programming.shared_evidence
evidence:
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
evidence_refs:
  - symbol: AtlasDualCoreEngineeringSystemService
  - command: atlas:aaeos:dual-core-engineering-system
  - test: AtlasDualCoreEngineeringSystemTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia este doc quando precisar explicar a diferenca entre Atlas Dev e Atlas Forge.
  - Leia este doc antes de implementar roteamento, escalonamento ou evidencia compartilhada entre Dev e Forge.
ai_usage_notes:
  - Se a tarefa for pequena/media, pense Atlas Dev primeiro.
  - Se a tarefa for obra pesada, longa, enterprise ou exigir SDD completo, pense Atlas Forge primeiro.
  - Se houver duvida, use a matriz de roteamento e produza um motivo auditavel.
quality_gates:
  - dev-forge-identity-preserved
  - no-runtime-fusion
  - escalation-reason-recorded
  - evidence-contract-compatible
  - forge-heavy-work-authority-preserved
  - dev-executor-identity-preserved
  - elite-same-bar-preserved
failure_modes:
  - IA funde Dev e Forge e cria arquitetura confusa.
  - IA transforma Forge em gerente do Dev e perde a ideia de Obra enterprise.
  - IA transforma Dev em Forge mini e deixa o executor Dev com overhead de Obra.
  - IA trata Dev como "fast patch" de qualidade inferior (viola elite same-bar).
  - IA trata Autônomos como qualidade pior que Dev/Forge (viola elite same-bar).
  - IA manda tarefa pesada para Dev e gera patch incompleto sem SDD/escalation.
  - IA manda tarefa de sessão trivial para Forge e gera overhead desnecessario.
observability_signals:
  - route_decision
  - route_reason
  - workload_size
  - ambiguity_level
  - risk_level
  - expected_duration
  - modules_touched_count
  - sdd_required
  - escalation_triggered
  - escalation_packet_ref
  - shared_evidence_refs
next_actions:
  - Implementar Dev/Forge Boundary Contract como schema executavel.
  - Implementar Escalation Detector no Atlas Dev.
  - Implementar Forge Intake Packet compativel com receipts do Dev.
  - Implementar Dual-Core Router na surface Atlas AI.
  - Implementar painel Desktop que mostre Dev, Forge ou Dev -> Forge com motivo.
line_limit: 900
---
# Atlas Dual-Core Engineering System

## Resumo

Atlas Dual-Core Engineering System e o contrato da **convivencia Dev↔Forge**
(boundary, escalation, evidence) dentro do conjunto dos **tres executores elite**:

```text
Atlas Dev      = executor elite; humano presente na INTENCAO; cadencia de sessao; L0–L5.
Atlas Forge    = executor elite; humano so no plano/soberania; obra longa multi-packet; L0–L5.
Autonomos      = executor elite; ZERO humano no loop de eng; brain+task 24/7; L0–L5.
```

**Identidade completa dos tres (matriz, ladder, anti-padroes):**  
`docs/engineering-knowledge-base/atlas-elite-executors-dev-forge-autonomos.md`  
(este Dual-Core **nao** rebaixa Dev a "leve" nem Autonomos a "pior").

Dev e Forge nao sao o mesmo produto e nao devem ser fundidos. Compartilham
contratos, evidence e roteamento; preservam identidade, runtime, UX e criterios
de sucesso. Autonomos e o terceiro runtime user-space (nao e "filho" do Dual-Core
e nao e o Loop/ACDE morto).

Na arquitetura v3, o Dual-Core nao e o governo inteiro:

```text
Constitution / Mission Control / Policy Plane / AAEOS
-> Engineering Kernel / Courts / Governor
-> User-space runtimes (elite same-bar)
   -> Atlas Dev
   -> Atlas Forge
   -> Autonomos / Self-Construction (brain + task)
```

## Tres executores elite (ponte)

| | Dev | Forge | Autonomos |
|---|---|---|---|
| Humano no loop de eng | Presente na **intencao** | So plano / soberania | **Zero** (default 24/7) |
| Escala | Sessao | Obra longa multi-packet | Fila continua |
| Origem | "faz X" ao vivo | Spec / obra | brain:next → seed → task |
| Dificuldade | L0–L5 | L0–L5 | L0–L5 |

Mesma barra mundial. NUNCA "Dev = fast patch". NUNCA "Autonomos = qualidade pior".  
Detalhe: `atlas-elite-executors-dev-forge-autonomos.md`.

## Papel no Atlas

Atlas Dev e completo por si so. Atlas Forge tambem e completo por si so.
Autonomos tambem e completo no operate path 24/7 (brain+task).

O patamar correto nao e juntar Dev e Forge. O patamar correto e:

```text
Mission Control / AAEOS / Atlas AI Router
-> decide Dev, Forge, Autonomos, ou Dev -> Forge
-> registra motivo
-> entrega ao modo correto
-> preserva evidence compativel (same-bar)
-> recebe Court/Governor receipts quando houver codigo/release
```

## Onde Se Encaixa

| Nucleo | Identidade | Nao e |
| --- | --- | --- |
| Atlas Dev | Executor elite (operador na intencao); L0–L5; superior a provider direto | Forge mini · fast patch · produto leve |
| Atlas Forge | Executor elite de engenharia por Obras; L0–L5 | Gerente do Dev · "so multiagente cosmestico" |
| Autonomos / Self-Construction | Executor elite zero-operador no loop; brain+task; L0–L5 | Loop/ACDE morto · qualidade inferior · so trivial |

Dev, Forge e Autonomos nao chamam provider direto nem fazem merge por conta
propria no estado governado. Eles pedem mecanismo ao Engineering Kernel,
aceitam politica da Policy Plane, passam pelos tribunais e recebem landing do
Governor. Autonomos no caminho vivo committa **escopado na main** via TaskServing
(nao merge de obra no path comum).

## Atlas Dev

Atlas Dev e o executor elite de programacao com **operador presente na intencao**,
workspace-bound e auditavel. Cobre do L0 (tipografia) ao L5 (frontier) em
**cadencia de sessao** — nao e "so patch facil".

Use quando o operador quer transformar demanda de programacao em plano, patch,
debug, teste, review ou resposta tecnica **com rumo humano vivo**.

Responsabilidades canonicas:

- interpretar prompt claro ou ambiguo (sessao; escalar se virar Obra);
- montar plano proporcional ao risco e a L*;
- gerar task contract proporcional ao risco;
- editar codigo com scope guard;
- executar testes ou verificacoes proporcionais **sem relaxar leis de governance**;
- registrar verification receipt;
- registrar `senior_engineer_loop_execution` quando usar Senior Engineer Loop;
- registrar error ledger e failure capsule quando falhar;
- escalar para Forge quando virar Obra (packet auditavel).

**Operator Rebate (UX, nao barra):** Dev deve manter **overhead de processo**
agil o bastante para o operador nao fugir para Claude/Codex cru — receipts
proporcionais, nao leis de governance mais fracas. Parecer fluido na superficie
≠ qualidade inferior por baixo.

"Fast path" / "fast lane" em docs legados = **sinonimo historico do fluxo Dev**,
nao "qualidade barata". Preferir "executor Dev".

## Atlas Forge

Atlas Forge e o executor elite para engenharia **longa**, enterprise e
automatizada (L0–L5 em horizonte de Obra). Mesma barra que Dev/Autonomos;
diferenca = escala multi-packet + operador so no plano/soberania.

Use quando a demanda exige SDD completo, arquitetura, decomposicao, work
packets, multi-provider, revisao, repair, governanca e continuidade por dias,
semanas ou meses — nao porque "so Forge e elite".

Responsabilidades canonicas:

- transformar prompt ambiguo grande em SDD;
- definir arquitetura, fases, riscos, criterios de aceite e work packets;
- operar Obra, Forge Workspace e artifact bus;
- usar Atlas Decide, provider topology e fallback governado;
- coordenar execucao longa, multiagente ou multiprovider;
- normalizar evidence;
- aplicar review/completion gate;
- gerar release/promotion decision;
- manter continuidade de engenharia por longo periodo.

Forge nao depende de Dev para ser completo. Se usar Dev como executor tatico em
algum momento, isso e uma opcao de interoperabilidade, nao uma subordinacao.

Forge paga mais custo de governanca porque o risco e o horizonte sao maiores:
SDD, work packets, continuidade, multiagente, rollback, review e receipts mais
fortes. Isso nao autoriza bypass de Court/Governor.

## Fluxo

| Sinal | Rota padrao | Motivo |
| --- | --- | --- |
| Bug pequeno/local | Dev | Corrigir rapido com teste/receipt |
| Endpoint, comando, DTO, teste ou refactor pequeno | Dev | Escopo limitado e verificavel |
| Prompt ambiguo mas pequeno/medio | Dev Senior Engineer Loop | Pode inferir, planejar, executar e auditar |
| Mudanca em muitos modulos | Forge | Exige arquitetura e plano de fases |
| Sistema novo ou subsistema grande | Forge | Exige SDD e governanca |
| Trabalho de dias/semanas/meses | Forge | Exige continuidade e Obra |
| Alto risco, dados, seguranca, billing, auth, compliance | Forge | Exige risk register, rollback e gates |
| Necessidade de multi-provider/multiagente | Forge | Exige workspace, packets e integration queue |
| Dev detecta escopo expandindo | Dev -> Forge | Escalonamento honesto |
| Forge identifica subpatch curto isolado | Forge opcionalmente gera work packet compativel com Dev | Interoperabilidade tatica |

## Contratos

Todo roteamento entre Dev e Forge deve produzir uma decisao audivel.

Campos minimos:

```json
{
  "schema": "atlas.dual_core.route_decision.v1",
  "route": "dev|forge|dev_to_forge",
  "reason": "short_human_reason",
  "intent_summary": "what_the_user_wants",
  "ambiguity_level": "low|medium|high",
  "risk_level": "low|medium|high|critical",
  "expected_duration": "minutes|hours|days|weeks|months",
  "modules_touched_estimate": 1,
  "sdd_required": false,
  "evidence_required": ["plan", "receipt", "verification"],
  "operator_visible": true
}
```

Regras:

- `route=dev` quando a tarefa cabe no executor Dev (operador presente, escopo contido).
- `route=forge` quando a tarefa ja nasce como Obra.
- `route=dev_to_forge` quando o Dev comecou ou analisou e descobriu que passou
  do limite dele.
- A decisao deve ser visivel para operador, API e Desktop.
- A decisao nao pode ser escondida em prompt de provider.

## Dev -> Forge Escalation Packet

Quando Dev escalar para Forge, ele deve gerar um pacote para o Forge continuar
sem perder contexto.

Campos minimos:

```json
{
  "schema": "atlas.dev_to_forge.escalation_packet.v1",
  "source": "atlas_dev",
  "target": "atlas_forge",
  "intent": "original_user_intent",
  "dev_interpretation": "what_dev_understood",
  "why_escalated": ["scope_too_large", "sdd_required"],
  "workspace": {
    "root": "/path",
    "relevant_paths": []
  },
  "evidence_refs": {
    "plan": null,
    "senior_loop_audit": null,
    "senior_loop_execution": null,
    "verification_receipt": null,
    "error_ledger": null,
    "failure_capsules": []
  },
  "known_risks": [],
  "open_questions": [],
  "recommended_forge_mode": "sdd_intake|obra_intake|architecture_review|long_run"
}
```

O packet nao e uma implementacao parcial disfarçada. Ele e handoff honesto:
contexto, motivo, evidence e recomendacao de modo Forge.

## Forge Intake A Partir Do Dev

Quando Forge receber um Escalation Packet, ele deve:

1. Validar schema e refs de evidence.
2. Reclassificar risco e escopo.
3. Produzir SDD ou Obra Intake.
4. Definir fases e criterios de aceite.
5. Decidir providers, autonomy, budget e fallback.
6. Registrar Decision Receipt.
7. Comecar a Obra ou bloquear honestamente.

**Call-site de producao (vivo):** `DevToForgePromotionService` anexa
`escalation_packet.v1` e chama `ForgeIntakeService::intakeFromEscalationPacket()`
no caminho de promocao Dev→Forge (fail-open: erro vira `forge_intake_error`
no candidate, sem derrubar a promocao). Intake a partir de packet nao e mais
somente teste.

Forge pode discordar do Dev. Se discordar, deve registrar:

```text
dev_route_rejected
forge_reason
final_route
operator_visible_decision
```

## Shared Evidence Contract

Dev e Forge podem compartilhar evidence, mas cada nucleo preserva seus artefatos
proprios.

Artefatos Dev:

- `plan.json`
- `task_contract.json`
- `verification_receipt.json`
- `senior_engineer_loop_audit.json`
- `senior_engineer_loop_execution.json`
- `error_ledger.v1.json`
- `failure_capsule.*.json`

Artefatos Forge:

- `sdd`
- `obra_intake`
- `work_packets`
- `decision_receipt`
- `provider_topology`
- `execution_receipts`
- `review_completion_gate`
- `repair_receipts`
- `evidence_pack`
- `release_or_promotion_decision`

Artefatos compartilhados:

- `route_decision`
- `evidence_refs`
- `risk_register`
- `blockers`
- `verification_summary`
- `learning_candidate`
- `operator_decision`

## UX Esperada

O operador deve conseguir entender em uma tela:

```text
Esta tarefa esta no Dev porque e pequena/media.
Esta tarefa esta no Forge porque e Obra.
Esta tarefa comecou no Dev e foi escalada porque virou Obra.
```

Desktop/API deve expor:

- nucleo escolhido;
- motivo da escolha;
- nivel de risco;
- evidence gerada;
- proximo passo;
- bloqueios;
- se houve escalonamento;
- link para packet ou receipt.

## Escopo de Implementacao

| Bloco | DoD resumido | Horas |
| --- | --- | ---: |
| Boundary Contract | `route_decision` persistido com rota, motivo, risco, ambiguidade, duracao e `sdd_required` | 4-6h |
| Escalation Detector | Dev escala por escopo, risco, SDD, multiagente, arquitetura central ou falha repetida | 6-10h |
| Dev -> Forge Packet | Packet persistido com intent, interpretacao, riscos, refs, open questions e modo Forge recomendado | 6-8h |
| Forge Intake | Forge valida packet, cria SDD/Obra Intake, aceita/rejeita/reclassifica e registra receipt | 8-14h |
| Shared Evidence | Dev e Forge cruzam refs sem apagar artefatos nativos; refs quebradas falham em strict mode | 8-12h |
| Dual-Core Router | Decide `dev`, `forge` ou `dev_to_forge` antes da execucao, sem chamar provider diretamente | 10-16h |
| Desktop/API Surface | Mostra nucleo, motivo, evidence, bloqueios e escalonamento sem silencio | 10-18h |
| Forge Long-Run Loop | Obra com fases, checkpoints, fallback visivel, completion gate e retomada | 16-28h |
| Governance Layer | Approval gates, rollback/mitigation, risk tiers, audit trail e policy | 12-20h |
| Test Matrix | Dev-only, Forge-only, Dev -> Forge, rejeicao/reclassificacao, refs quebradas e Desktop/API | 12-18h |
| Docs/Runbooks | Uma IA consegue implementar sem conversa anterior; START_HERE aponta para este doc; docs-health passa | 5-8h |

## Estimativa Total

| Nivel | Entrega | Horas |
| --- | --- | ---: |
| MVP forte | Boundary, detector, packet, intake basico, evidence refs, testes principais | 40-60h |
| Enterprise-interna | Router robusto, Desktop/API, governance, matriz ampla | 80-120h |
| Outro patamar completo | Dual-Core maduro com Forge long-run, interoperabilidade total e evidence auditavel | 140-220h |

## Regras para IA

- Nao diga "Forge manda pacotes para Dev" como regra geral.
- Diga "Forge pode usar Dev taticamente quando fizer sentido".
- Nao diga "Dev e uma versao leve do Forge".
- Diga "Dev e um sistema completo de programacao rapida".
- Nao diga "Forge e so para tarefas que Dev nao consegue".
- Diga "Forge e o sistema completo para Obras pesadas".
- Nao crie um terceiro OS acima dos dois para programacao.
- Use Dual-Core apenas como contrato de relacao, nao como substituto dos dois.

## Dependencias

- Atlas Dev Efficient Programming Flow.
- Atlas Forge Continuum OS.
- Atlas Programming Self-Construction Forge Map v1.
- Atlas AI Router Flow Routing Contract.
- Atlas AI Conversation Surface And Atlas Dev.

## Evidencias

- Este contrato em `docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md`.
- Links em `START_HERE.md`, `README.md` e `atlas-programming-self-construction-forge-map-v1.md`.
- Validacao esperada: `php artisan atlas:engineering:knowledge docs-health --json`.

## Riscos

- Fundir Dev e Forge e perder a clareza operacional.
- Transformar Forge em gerente do Dev e enfraquecer Obras.
- Transformar Dev em Forge mini e perder a identidade dos três executores elite.
- Escalar em silencio sem packet, motivo e evidence.
- Mandar trabalho pesado para Dev ou trabalho pequeno para Forge.

## Exemplos

- "Corrige esse bug local" -> Dev.
- "Implementa este endpoint com teste" -> Dev.
- "Reestrutura billing enterprise" -> Forge.
- "Cria uma plataforma completa" -> Forge.
- "Dev percebeu muitos modulos e SDD required" -> Dev -> Forge.

## Proximas Acoes

1. Implementar schema `atlas.dual_core.route_decision.v1`.
2. Implementar detector de escalonamento no Atlas Dev.
3. Implementar packet `atlas.dev_to_forge.escalation_packet.v1`.
4. Implementar Forge Intake desse packet.
5. Expor rota/evidence no Desktop e API.

Frase canonica:

```text
Atlas Dev e o nucleo completo de programacao rapida e auditavel.
Atlas Forge e o nucleo completo de engenharia pesada por Obras.
O Atlas Dual-Core Engineering System conecta os dois por roteamento, evidencia e
escalonamento, sem fundir suas identidades.
```
