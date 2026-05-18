---
id: atlas-dual-core-engineering-system
type: engineering_knowledge
title: Atlas Dual-Core Engineering System
status: active
category: programming
priority: 100
summary: Contrato canonico que separa Atlas Dev e Atlas Forge como dois sistemas completos e autonomos de engenharia de software, conectados por fronteira, roteamento, evidencia compartilhada e escalonamento Dev -> Forge sem fusao de identidade.
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
  - Atlas Dev e completo por si so como sistema leve, rapido, governado e auditavel de programacao assistida por IA.
  - Atlas Forge e completo por si so como sistema pesado, enterprise, continuo e automatizado de engenharia de software por Obras.
  - Atlas Dev nao e Forge mini, Forge nao e gerente do Dev, e nenhum dos dois deve ser fundido em um sistema unico.
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
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dual-core-engineering-system
graph_title: Atlas Dual-Core Engineering System
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-programming-forge-flow
graph_status: active
graph_source: repo
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
  - Permitir escalonamento silencioso sem packet, receipt e motivo auditavel.
  - Permitir que Dev execute Obra longa sem promover para Forge.
depends_on:
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
  - route_light_work_to_dev
  - route_heavy_work_to_forge
  - governed_interoperability
governs:
  - atlas.dual_core_engineering
  - atlas_dev_to_forge.boundary
  - atlas_programming.routing
  - atlas_programming.shared_evidence
evidence:
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
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
  - dev-fast-flow-authority-preserved
failure_modes:
  - IA funde Dev e Forge e cria arquitetura confusa.
  - IA transforma Forge em gerente do Dev e perde a ideia de Obra enterprise.
  - IA transforma Dev em Forge mini e deixa o fast path pesado demais.
  - IA manda tarefa pesada para Dev e gera patch incompleto sem SDD.
  - IA manda tarefa pequena para Forge e gera overhead desnecessario.
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

Atlas Dual-Core Engineering System e o contrato que define a convivencia entre
dois nucleos completos de engenharia de software:

```text
Atlas Dev   = nucleo rapido, leve, governado e auditavel para programacao diaria.
Atlas Forge = nucleo pesado, enterprise, continuo e automatizado para Obras.
```

Eles nao sao o mesmo produto. Eles nao devem ser fundidos. Eles compartilham
contratos, evidence e roteamento, mas preservam identidade, runtime, UX,
criterios de sucesso e autoridade operacional.

## Papel no Atlas

Atlas Dev e completo por si so. Atlas Forge tambem e completo por si so.

O patamar correto nao e juntar os dois. O patamar correto e fazer os dois
conversarem por contrato:

```text
Atlas AI Router
-> decide Dev, Forge ou Dev -> Forge
-> registra motivo
-> entrega ao nucleo correto
-> preserva evidence compativel
```

## Onde Se Encaixa

| Nucleo | Identidade | Nao e |
| --- | --- | --- |
| Atlas Dev | Fast path de programacao assistida superior a provider direto | Forge mini |
| Atlas Forge | Sistema completo de engenharia pesada por Obras | Gerente do Dev |

## Atlas Dev

Atlas Dev e o sistema para desenvolvimento rapido, workspace-bound e auditavel.
Ele deve ser usado quando o operador quer transformar uma demanda de programacao
em patch, plano, debug, teste, review ou resposta tecnica com baixo overhead.

Responsabilidades canonicas:

- interpretar prompt claro ou ambiguo de escopo limitado;
- montar plano curto ou compacto;
- gerar task contract proporcional ao risco;
- editar codigo com scope guard;
- executar testes ou verificacoes proporcionais;
- registrar verification receipt;
- registrar `senior_engineer_loop_execution` quando usar Senior Engineer Loop;
- registrar error ledger e failure capsule quando falhar;
- escalar para Forge quando virar Obra.

Atlas Dev deve parecer leve para o usuario, mesmo quando por baixo faz trabalho
senior. Ele deve ser mais confiavel que Claude Code/Codex direto porque adiciona
contrato, verificacao, evidence, scope guard e learning handoff.

## Atlas Forge

Atlas Forge e o sistema para engenharia pesada, enterprise, longa e
automatizada. Ele deve ser usado quando a demanda exige SDD completo,
arquitetura, decomposicao, work packets, multi-provider, revisao, repair,
governanca e continuidade por dias, semanas ou meses.

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

- `route=dev` quando a tarefa cabe em fast path governado.
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
- Transformar Dev em Forge mini e matar o fast path.
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
