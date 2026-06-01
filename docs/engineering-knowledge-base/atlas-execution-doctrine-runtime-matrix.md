---
id: atlas-execution-doctrine-runtime-matrix
type: engineering_knowledge
title: Atlas Execution Doctrine Runtime Matrix
status: active
category: product-delivery
priority: 98
summary: Matriz tecnica filha do AEDPDS com blocos, runtimes, comandos, riscos, provas e fronteiras futuras da entrega de produto por IA.
human_summary: Lista os blocos reais que fazem o AEDPDS funcionar e o que cada um recebe, entrega e prova.
human_what: Matriz operacional dos runtimes de entrega de produto.
human_purpose: Permitir que IAs implementem ou auditem AEDPDS sem misturar doutrina mae com detalhes de runtime.
human_input: Pedido humano, Product Truth Contract, delivery contract, proof report, evidencia, repair packet e outcome memory.
human_output: Matriz de blocos, estado, prova, risco, comando e criterio de pronto.
human_change_when: Atualize quando APTC, APDR, APFPR, enforcement, repair bridge, certification ou outcome memory mudarem.
human_block_when: Bloqueie se algum bloco for declarado implementado sem service, teste, comando ou evidencia.
human_name: Atlas Execution Doctrine Runtime Matrix
canonical_name: Atlas Execution Doctrine Runtime Matrix
technical_name: atlas-execution-doctrine-runtime-matrix
product_name: Atlas Execution Doctrine Runtime Matrix
internal_product_name: AEDPDS Runtime Map
technical_runtime: AtlasExecutionDoctrineRuntimeMatrixDocument
runtime_acronym: AEDPDS-MATRIX
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-execution-doctrine-runtime-matrix.md
tags:
  - atlas
  - product-delivery
  - runtime-matrix
  - aedpds
capabilities:
  - product_delivery_runtime_mapping
  - implementation_status_tracking
  - proof_mapping
decisions:
  - A doc mae AEDPDS governa doutrina; esta doc governa matriz tecnica.
  - Nenhum bloco pode subir de estado sem service, teste, comando ou evidencia equivalente.
  - Patch externo nasce de Patch Request Contract e passa por Proposal Gate antes do executor mutativo.
  - Blocos apex podem ser planejados, mas nao podem virar claim implementada sem service, teste e certification.
maintenance:
  - Rodar docs-health apos alteracoes.
  - Sincronizar com product certification quando checks mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md
  - docs/engineering-knowledge-base/atlas-product-falsification-proof-runtime.md
  - app/Services/Ai/Product/AtlasProductTruthCompilerService.php
  - app/Services/Ai/Product/AtlasAutonomousProductDeliveryRuntimeService.php
  - app/Services/Ai/Product/AtlasProductFalsificationProofRuntimeService.php
  - app/Services/Ai/Product/AtlasProductDeliveryEnforcementService.php
  - app/Services/Ai/Product/AtlasProductDeliveryRepairBridgeService.php
  - app/Services/Ai/Product/AtlasProductDeliveryPatchRequestContractService.php
  - app/Console/Commands/Ai/Product/AtlasProductDeliveryPatchRequestCommand.php
  - app/Services/Ai/Product/AtlasProductDeliveryPatchProposalGateService.php
  - app/Services/Ai/Product/AtlasProductDeliveryMutativeRepairExecutorService.php
  - app/Console/Commands/Ai/Product/AtlasProductDeliveryRepairExecuteCommand.php
  - app/Services/Ai/Product/AtlasProductDeliveryOutcomeMemoryService.php
  - app/Services/Ai/Product/AtlasProductDeliveryRuntimeReceiptService.php
  - app/Services/Ai/Product/AtlasProductTwinSimulationService.php
  - app/Console/Commands/Ai/Product/AtlasProductTwinSimulateCommand.php
  - app/Services/Ai/Product/AtlasProductDeliveryRiskGovernorService.php
  - app/Console/Commands/Ai/Product/AtlasProductDeliveryRiskGovernorCommand.php
  - app/Services/Ai/Product/AtlasProductDeliveryControlPlaneService.php
  - app/Console/Commands/Ai/Product/AtlasProductDeliveryControlPlaneCommand.php
  - app/Services/Ai/Product/AtlasProductReleaseGateService.php
  - app/Console/Commands/Ai/Product/AtlasProductReleaseGateCommand.php
  - app/Services/Ai/Product/AtlasProductDeliveryProviderMemoryFeedService.php
  - app/Console/Commands/Ai/Product/AtlasProductDeliveryProviderMemoryCommand.php
  - app/Services/Ai/Product/AtlasProductDeliveryPolicyOptimizerService.php
  - app/Console/Commands/Ai/Product/AtlasProductDeliveryPolicyOptimizerCommand.php
  - app/Services/Ai/Product/AtlasProductDeliveryMultiStepRepairPlannerService.php
  - app/Console/Commands/Ai/Product/AtlasProductDeliveryRepairPlanCommand.php
  - app/Services/Ai/Product/AtlasProductDeliveryEvidenceReplayLabService.php
  - app/Console/Commands/Ai/Product/AtlasProductDeliveryReplayLabCommand.php
  - tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-execution-doctrine-runtime-matrix
graph_title: Atlas Execution Doctrine Runtime Matrix
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-execution-doctrine-product-delivery-system
graph_status: active
graph_source: repo
owner: product-delivery
repo_paths:
  - docs/engineering-knowledge-base/atlas-execution-doctrine-runtime-matrix.md
allowed_changes:
  - Atualizar estados e provas quando codigo/testes mudarem juntos.
forbidden_changes:
  - Declarar bloco implementado sem evidencia.
  - Declarar repair mutativo enquanto o bridge for non-mutative.
depends_on:
  - atlas-execution-doctrine-product-delivery-system
flows_to:
  - atlas-ai-product-certification
unlocks:
  - product-delivery-runtime-audit
  - product-delivery-implementation-map
governs:
  - product-delivery
evidence:
  - tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php
evidence_refs:
  - symbol: AtlasProductTruthCompilerService
  - command: atlas:product-truth:compile
required_tests:
  - "php artisan test tests/Feature/Ai/Product"
  - "php artisan atlas:product-delivery:certify --json --strict"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
quality_gates:
  - "php artisan test tests/Feature/Ai/Product"
  - "php artisan atlas:product-delivery:certify --json --strict"
  - "php artisan atlas:ai:product-certify --json --strict"
failure_modes:
  - Estado implementado sem prova.
  - Rota Forge/Dev confundida.
  - Patch de provider/subagente aplicado sem Proposal Gate.
  - Outcome memory promovida sem Judgment Guard.
next_actions:
  - Manter matriz sincronizada com Product Certification.
  - Promover geracao autonoma de patch somente quando provider policy, approval e rollback estiverem provados.
  - Manter Release Gate read-only ate existir approval e receipt de release real.
---
# Atlas Execution Doctrine Runtime Matrix

## Resumo

Esta e a matriz operacional filha do AEDPDS. Use esta doc quando precisar
implementar, auditar ou corrigir blocos do Product Delivery OS.

## Papel no Atlas

Ela impede que uma IA leia apenas a doutrina mae e invente estado de runtime.
Cada bloco declara estado, prova e limite de claim.

## Onde Se Encaixa

Esta doc fica abaixo de `atlas-execution-doctrine-product-delivery-system.md`
e acima dos services Product em `app/Services/Ai/Product`.

## Contratos

Contrato principal: bloco so pode ser `implemented` quando existir evidencia
em service, teste, comando, wiring ou certificacao.

## Fluxo

```text
AEDPDS mae -> runtime matrix -> service/comando/teste -> certification
```

## Regras para IA

- Nao promover estado sem prova.
- Nao tratar `implemented_shadow` como execucao mutativa.
- Nao aplicar patch de provider/subagente sem `Patch Proposal Gate`.
- Atualizar AEDPDS mae somente quando a doutrina mudar.

## Escopo de Implementacao

## Blocos

| Ordem | Bloco | Funcao | Estado | Prova |
|---:|---|---|---|---|
| 1 | Product Truth Compiler | Compila pedido humano em verdade de produto executavel. | implemented | `AtlasProductTruthCompilerService` |
| 2 | Execution Doctrine Selector | Escolhe drivers canonicos AEDPDS por tarefa: TDD, ATDD, CDD/API-first, DbDD, UX, Risk, Architecture, Security, Performance, Docs, DDD, MDD, Observability e Evidence. | implemented | `AtlasExecutionDoctrineRuntimeService` + `atlas:aedpds:select` |
| 3 | Human Intent Normalization | Traduz pedido ruim em objetivo verificavel. | implemented | Product Truth tests |
| 4 | Ambiguity Resolver | Pergunta so o minimo necessario antes de executar. | implemented_shadow | `needs_context` |
| 5 | Product Brief Runtime | Cria problema, publico, resultado e limites. | implemented | Product Truth payload |
| 6 | Business Rule Extractor | Separa regra de negocio de detalhe tecnico. | implemented_shadow | Domain fields |
| 7 | Domain Modeling Runtime | Aplica DDD leve/profundo conforme risco. | implemented_shadow | Bounded contexts/entities |
| 8 | Acceptance Universe Builder | Cria universo de pronto e anti-regressao. | implemented | `acceptance_universe` |
| 9 | Contract Runtime | Aplica CDD para API, eventos, payloads e schemas. | implemented_shadow | `contract_map` |
| 10 | Architecture Runtime | Aplica ADD, riscos e atributos de qualidade. | implemented_shadow | `architecture_constraints` |
| 11 | Prototype Runtime | Aplica PDD quando UX/viabilidade nao estao claros. | design_canonical | No mutative runtime |
| 12 | Delivery Decomposition Engine | Decide Dev, Forge, pesquisa, prototipo ou bloqueio. | implemented | APDR route |
| 13 | Proof Plan Compiler | Define testes, gates e evidencias antes da execucao. | implemented | `proof_plan` |
| 14 | Product Simulation Runtime | Simula fluxo, risco e impacto antes de codar. | implemented_shadow | APDR delivery plan |
| 15 | AEDPDS Execution Gate | Bloqueia ou alerta quando drivers exigem aceite, contexto, teste, contrato, UX, modelo formal/semi-formal, observabilidade/readiness, review ou evidencia ausente. | implemented | `AtlasExecutionDoctrineGateService` + `atlas:aedpds:gate` |
| 16 | APDR Delivery Runtime | Monta contrato de execucao provider-free, embute `aedpds.doctrine` + `aedpds.gate` e bloqueia `ready_for_delivery` quando o gate AEDPDS bloqueia. | implemented | `AtlasAutonomousProductDeliveryRuntimeService` |
| 17 | Dev Delivery Bridge | Usa AEDPDS/AAEQ/ADRI para tarefas curtas. | implemented | `DevTaskPacketRuntimeService`, `DevContextGateService`, `DevRunCertificationService` |
| 18 | Forge Delivery Bridge | Encaminha Obra longa para Forge semantics com projecao AEDPDS no Work Intake. | implemented | `AtlasCodeForgeWorkIntakeService` |
| 19 | APFPR Proof Runtime | Tenta reprovar requisito, aceite, contrato, seguranca, teste e evidencia. | implemented | `AtlasProductFalsificationProofRuntimeService` |
| 20 | Enforcement Runtime | Bloqueia provider/completion quando falta prova. | implemented | `AtlasProductDeliveryEnforcementService` |
| 21 | Repair Bridge | Converte blocker APFPR em Dev repair receipt ou Forge repair packet. | implemented_non_mutative | `AtlasProductDeliveryRepairBridgeService` |
| 22 | Delivery Certification Runtime | Certifica AEDPDS e bloqueia falso pronto. | implemented | `atlas:aedpds:certify` + `atlas:product-delivery:certify` |
| 23 | Outcome Memory Runtime | Persiste resultado, evidencia e reparos. | implemented | `atlas_product_delivery_outcome_memories` |
| 24 | AEMOR Judgment Bridge | Passa outcome pelo Judgment Guard antes de learning. | implemented_guarded | `AtlasAemorJudgmentService` |
| 25 | Product Control Plane | Agrega delivery, risk, replay, fitness, receipts, certification, blockers e proximas acoes. | implemented_shadow | `AtlasProductDeliveryControlPlaneService` + `atlas:product-delivery:control-plane` |
| 26 | Patch Request Contract | Projeta pedido seguro para humano/provider/subagente propor patch no schema correto. | implemented_controlled | `AtlasProductDeliveryPatchRequestContractService` + `atlas:product-delivery:patch-request` |
| 27 | Patch Proposal Gate | Valida patch humano/provider/subagente antes do executor, exige approval quando aplica risco alto. | implemented_controlled | `AtlasProductDeliveryPatchProposalGateService` |
| 28 | Mutative Repair Executor | Aplica patch explicito aprovado apos proof failure com allowlist, rollback snapshot e proof rerun. | implemented_controlled | `AtlasProductDeliveryMutativeRepairExecutorService` + `atlas:product-delivery:repair-execute` |
| 29 | Runtime Receipt Ledger | Persiste patch request, gate e repair execution em receipts append-only. | implemented_guarded | `AtlasProductDeliveryRuntimeReceiptService` |
| 30 | Self-Improving Delivery | Ajusta rotas/testes por outcomes aprovados. | partial_guarded | AEMOR learning gated |
| 31 | Assisted Patch Generator | Gera patch candidato a partir do Patch Request Contract, sem aplicar direto. | planned_guarded | Depende de Proposal Gate |
| 32 | Multi-Step Repair Planner | Quebra reparo em passos com budget, rollback, testes e stop conditions. | implemented_shadow | `AtlasProductDeliveryMultiStepRepairPlannerService` |
| 33 | Product Twin Simulation | Simula impacto em dominio, contrato, arquivo, teste, UI e operacao antes da execucao. | implemented_shadow | `AtlasProductTwinSimulationService` |
| 34 | Doctrine Fitness Loop | Mede qual lente de entrega gerou melhor outcome real. | implemented_shadow | `AtlasProductDeliveryDoctrineFitnessService` + `atlas:product-delivery:doctrine-fitness` |
| 35 | Delivery Risk Governor | Ajusta autonomia por risco, approval, replay, fitness, receipts, patch de provider, proof e Product Twin. | implemented_shadow | `AtlasProductDeliveryRiskGovernorService` + `atlas:product-delivery:risk-govern` |
| 36 | Evidence Replay Lab | Reexecuta cenarios antigos e receipts para provar que mudanca de doutrina nao regrediu qualidade. | implemented_shadow | `AtlasProductDeliveryEvidenceReplayLabService` + `atlas:product-delivery:replay-lab` |
| 37 | Autonomous Product Release Gate | Decide se uma entrega pode sair de Dev/Forge para release candidate com prova completa. | implemented_shadow | `AtlasProductReleaseGateService` + `atlas:product-delivery:release-gate` |
| 38 | Provider/Cost/Flake Memory Feed | Alimenta Risk Governor com falha de provider, custo real e flakiness de teste. | implemented_shadow | `AtlasProductDeliveryProviderMemoryFeedService` + `atlas:product-delivery:provider-memory` |
| 39 | Product Policy Optimizer | Propoe ajuste de doutrina quando Replay, Fitness e Outcome convergem. | implemented_shadow | AEMOR Judgment required |

## Loops Apex

| Loop | Melhora | Gate |
|---|---|---|
| Truth | Produto, dominio e aceite. | Product Truth em tarefa complexa. |
| Proof | Teste, contrato e evidencia. | APFPR antes de pronto. |
| Repair | Falha vira patch seguro ou work packet. | Proposal Gate antes de aplicar. |
| Memory | Outcome melhora proximas rotas. | AEMOR Judgment Guard. |
| Reality | Doc, codigo e runtime ficam sincronizados. | Certification e docs-health. |
| Governance | Autonomia aumenta ou recua por risco real. | Risk Governor + Control Plane. |

## Fronteira de Autonomia

| Nivel | Autonomia | Estado |
|---:|---|---|
| 1 | Planeja e certifica sem side effect. | implemented |
| 2 | Gera patch request seguro. | implemented |
| 3 | Valida patch externo. | implemented |
| 4 | Aplica patch explicito aprovado. | implemented_controlled |
| 5 | Gera patch candidato. | planned_guarded |
| 6 | Executa repair multi-step. | planned |
| 7 | Aprende politica por outcomes. | partial_guarded |
| 8 | Otimiza doutrina por evidencia longitudinal. | planned |
| 9 | Libera release candidate por Control Plane healthy. | implemented_shadow |

## Maturidade e Definition of Done

| Fase | Nome | Criterio |
|---:|---|---|
| 0 | Canonical Doctrine | Doc mae existe e passa docs-health. |
| 1 | APTC Spec | Product Truth Contract, schemas, comandos e testes definidos. |
| 2 | APDR Spec | Delivery contract, comandos e testes definidos. |
| 3 | APFPR Spec | Falsificacao, prova e blockers definidos. |
| 4 | Truth Shadow Runtime | APTC gera Product Truth sem alterar execucao. |
| 5 | Delivery Shadow Runtime | APDR gera envelope sem alterar execucao. |
| 6 | Proof Shadow Runtime | APFPR tenta reprovar sem bloquear execucao. |
| 7 | Dev/Forge Opt-in | Dev/Forge consomem envelopes em modo opt-in. |
| 8 | Partial Enforcement | Risco alto exige Product Truth e APFPR antes do provider. |
| 9 | Default Enforcement | Produto complexo passa por APTC + APDR + APFPR. |
| 10 | Self-Improving Delivery | Outcome memory melhora rotas, testes e criterios. |
| 11 | Mutative Repair Autonomy | Reparo real com rollback, receipts e override humano. |
| 12 | Assisted Patch Generation | Atlas gera patch candidato; Proposal Gate decide. |
| 13 | Multi-Step Repair Plan | Reparo vira passos com budget, testes e rollback. |
| 14 | Product Twin Simulation | Simula impacto antes de executar. |
| 15 | Evidence Replay Lab | Reexecuta cenarios/receipts para detectar regressao. |
| 16 | Doctrine Fitness Loop | Mede lente/gate por outcome real. |
| 17 | Autonomous Delivery Governance | Autonomia sobe ou recua por prova, custo, risco e historico. |
| 18 | Delivery Risk Governor | Calcula autonomia permitida, approvals e gates. |
| 19 | Product Control Plane | Agrega risk, replay, fitness, receipts e certification. |
| 20 | Autonomous Product Release Gate | Release candidate so com prova completa. |
| 21 | Provider/Cost/Flake Memory Feed | Alimenta risco por falha, custo e flake reais. |
| 22 | Product Policy Optimizer | Propoe doutrina por replay/fitness sem aplicar policy. |

Definition of Done por fase: schema existe; service ou comando existe; teste
positivo e teste blocker existem; `atlas:product-delivery:certify --json
--strict` passa; `atlas:ai:product-certify --json --strict` inclui evidencia;
docs-health passa; claim policy declara side effects; outcome memory ou skip
reason existe.

Reparo controlado exige patch manifest explicito, Patch Request Contract antes
do provider/subagente, Patch Proposal Gate, approval para risco alto, rollback,
failure capsule, APFPR rerun, completion enforcement e receipt append-only.
Fases apex continuam read-only/controladas ate AEMOR Judgment e operador
permitirem promocao.

## Runtime Matrix

| Runtime | Input | Output | Risco | Comando/Teste |
|---|---|---|---|---|
| APTC | pedido humano, contexto, rota desejada | `atlas.product_truth_contract.v1` | verdade incompleta | `atlas:product-truth:compile --json` |
| AEDPDS Selector | pedido, surface, workspace, hints, risco, arquivos | `atlas.aedpds.execution_doctrine.v1` | driver errado ou excesso de burocracia | `atlas:aedpds:select --task="..." --json` |
| AEDPDS Gate | selector, aceite, contexto, testes, contratos, docs, review, evidencia | `atlas.aedpds.execution_gate.v1` | execucao sem criterio real | `atlas:aedpds:gate --task="..." --acceptance="..." --context="..." --test="..." --contract="..." --review="..." --evidence="..." --json --strict` |
| APDR | Product Truth, contexto, evidencia | `atlas.autonomous_product_delivery_runtime.v1` | rota errada ou falso readiness com gate bloqueado | `atlas:product-delivery:plan --operator-approved --ux="..." --json --strict` |
| APFPR | delivery, truth, evidencia | `atlas.product_proof_challenge.v1` | falso pronto ou prova sobre APDR bloqueado | `atlas:product-proof:challenge --operator-approved --ux="..." --with-demo-evidence --json --strict` |
| Enforcement | delivery, proof, fase | allow/block | bloqueio fraco | Product tests |
| Repair Bridge | delivery, proof bloqueado | Dev repair receipt ou Forge repair packet | repair sem patch real ou reparo sobre APDR bloqueado | Product tests |
| Outcome Memory | delivery, proof, evidencia | `atlas.product_delivery.outcome_memory.v1` | memoria falsa | `atlas:product-delivery:outcome --persist --json` |
| AEMOR Bridge | outcome memory | episode, outcome, judgment, learning signal | false learning | AEMOR tables/tests |
| Patch Request Contract | delivery, proof, repair bridge, target | provider-safe prompt projection + required manifest schema | prompt frouxo para provider ou patch sobre APDR bloqueado | `atlas:product-delivery:patch-request --operator-approved --ux="..." --json` |
| Patch Proposal Gate | delivery, patch manifest, source, approval | approved/blocked + sanitized manifest | provider patch sem operador | `atlas:product-delivery:repair-execute --source=provider --approval=...` |
| Mutative Repair Executor | delivery, proof, repair bridge, approved patch manifest | execution receipt + rollback snapshot + proof rerun | patch inseguro | `atlas:product-delivery:repair-execute --patch=<json>` |
| Runtime Receipt Ledger | runtime envelope | append-only receipt | perda de auditoria | `--persist` nos comandos AEDPDS |
| Assisted Patch Generator | patch request, failure capsule, allowed files | patch manifest candidato | patch inventado | planned |
| Multi-Step Repair Planner | blockers, budget, risk | repair steps | loop infinito | `atlas:product-delivery:repair-plan --json` |
| Product Twin Simulation | truth, delivery, diff candidate | predicted impact | simulacao falsa | `atlas:product-twin:simulate --json` |
| Doctrine Fitness Loop | outcomes, lenses, evidence | route/lens/evidence fitness + policy proposal | false learning | `atlas:product-delivery:doctrine-fitness --json` |
| Delivery Risk Governor | delivery, proof, Product Twin, replay, fitness, receipts, approval | autonomy budget + required approvals | autonomia excessiva | `atlas:product-delivery:risk-govern --json` |
| Evidence Replay Lab | receipts, historical scenarios | replay result | regressao escondida | implemented_shadow |
| Product Control Plane | delivery, risk, replay, fitness, receipts, certification | snapshot canonico + blockers + next actions | painel bonito sem verdade | `atlas:product-delivery:control-plane --json --strict` |
| Autonomous Product Release Gate | proof, outcome, receipts | release candidate decision | release prematuro | `atlas:product-delivery:release-gate --json --strict` |
| Provider/Cost/Flake Memory Feed | outcomes, test logs, provider receipts | risk signal | custo/flake mal atribuido | `atlas:product-delivery:provider-memory --json --strict` |
| Product Policy Optimizer | replay, fitness, AEMOR judgment | policy proposal | auto-policy perigosa | `atlas:product-delivery:policy-optimizer --json --strict` |
| Certification | arquivos, testes, comandos | readiness granular AEDPDS/APDR | claim inflado | `atlas:aedpds:certify --json --strict` + `atlas:product-delivery:certify --json --strict` |

## AEDPDS Selector e Gate

Runtime tecnico do selector: `AtlasExecutionDoctrineRuntimeService`.
Schema: `atlas.aedpds.execution_doctrine.v1`.
Comando: `php artisan atlas:aedpds:select --task="..." --surface=dev --json`.

Gate tecnico: `AtlasExecutionDoctrineGateService`.
Schema: `atlas.aedpds.execution_gate.v1`.
Comando: `php artisan atlas:aedpds:gate --task="..." --surface=dev --json`.
Status: `passed | warning | blocked`.

O gate valida aceite, contexto, testes, contratos, docs, UX/prototipo, modelo
formal/semi-formal, observabilidade/readiness, risk review, senior review e
evidencia esperada antes de execucao relevante. O comando nao injeta artefatos
falsos. Para passar via CLI, forneca explicitamente `--acceptance`,
`--context`, `--test`, `--contract`, `--doc`, `--review`, `--evidence` e `--ux`
conforme os drivers selecionados. Em `--strict`, ausencia obrigatoria precisa
sair com codigo diferente de zero.

Campos canonicos do selector: `request_id`, `trace_id`, `surface`, `workspace`,
`task_type`, `user_intent_summary`, `ambiguity_level`, `risk_level`,
`selected_primary_drivers`, `selected_secondary_drivers`, `required_artifacts`,
`required_context`, `required_tests`, `required_contracts`, `required_docs`,
`required_review`, `required_evidence`, `blockers`, `warnings`,
`allowed_to_execute`, `reason` e `certification_hash`.

Drivers canonicos: `tdd`, `bdd`, `atdd`, `fdd`, `sdd`, `cdd`, `api_first`,
`documentation_driven`, `readme_driven`, `domain_driven_design`,
`model_driven`, `database_driven`, `prototype_driven`, `ux_driven`,
`risk_driven`, `architecture_driven`, `security_driven`, `performance_driven`,
`reliability_observability_driven` e `data_evidence_driven`.

Gates especificos:

- Todo envelope com `required_context` precisa carregar ao menos um contexto
  real: owner doc, context pack, arquivo provavel, `--context` ou evidencia de
  contexto. Sem isso bloqueia com `missing_minimum_context_ref`.
- `model_driven` exige especificacao de modelo ou state machine e contrato
  formal/semi-formal antes de execucao.
- `reliability_observability_driven` exige logs, traces, receipts ou readiness
  signal quando observabilidade/readiness for driver primario.
- APDR propaga bloqueio AEDPDS para status do envelope e enforcement
  pre-provider; carregar gate bloqueado sem bloquear e falso readiness.
- APFPR bloqueia `delivery_contract_not_ready` quando APDR nao estiver
  `ready_for_delivery`.
- Patch Request e Multi-Step Repair Plan bloqueiam `delivery_contract_not_ready`
  para nao projetar patch sobre APDR bloqueado.

Relacoes operacionais:

- APDR executa produto; AEDPDS define a doutrina carregada no envelope.
- Atlas Dev usa AEDPDS no Task Packet, Context Gate, Test Impact e Run
  Certification.
- Forge usa AEDPDS no Work Intake, Work Packets, senior review, escalacao e
  Outcome Memory por driver.
- AEMOR aprende efetividade por outcome memory e sinais aprovados.
- ACRUI impede duplicacao, scaffold falso e claim contra realidade.
- AUCRI/context fornece contexto minimo proporcional aos drivers escolhidos.
- Control Plane e certificacao expõem AEDPDS como readiness/audit.

## Estados

| Estado | Significado |
|---|---|
| implemented | Service/teste/comando ou wiring existem. |
| implemented_shadow | Existe output deterministico, mas sem side effect mutativo. |
| implemented_non_mutative | Materializa plano/receipt, mas nao aplica patch. |
| implemented_controlled | Aplica mudanca somente com patch explicito, allowlist, rollback e receipt. |
| implemented_guarded | Executa somente se gate de julgamento permitir. |
| design_canonical | Especificado, sem runtime final. |
| planned | Planejado, sem superficie operacional. |
| planned_guarded | Planejado, mas ja possui gate obrigatorio definido. |
| not_claimed | Deliberadamente fora de claim pronta. |

## Regra de Atualizacao

Ao alterar um bloco:

1. Atualize service/comando/teste.
2. Atualize esta matriz.
3. Atualize AEDPDS mae somente se a doutrina mudar.
4. Rode Product tests, product-delivery certify, product certify e docs-health.

## Dependencias

- AEDPDS mae.
- APFPR.
- Product Certification.
- AEMOR Judgment Guard.
- Atlas Dev e Forge.

## Evidencias

- `AtlasExecutionDoctrineProductDeliverySystemTest`
- `AtlasProductDeliveryCertificationService`
- `AtlasAiProductCertificationService`
- `AtlasProductDeliveryPatchRequestContractService`
- `AtlasProductDeliveryPatchProposalGateService`
- `AtlasProductDeliveryRuntimeReceiptService`
- `AtlasProductTwinSimulationService`
- `AtlasProductDeliveryMultiStepRepairPlannerService`
- `AtlasProductDeliveryEvidenceReplayLabService`
- `AtlasProductDeliveryDoctrineFitnessService`
- `AtlasProductDeliveryRiskGovernorService`
- `AtlasProductDeliveryControlPlaneService`
- `AtlasProductReleaseGateService`
- `AtlasProductDeliveryProviderMemoryFeedService`
- `AtlasProductDeliveryRepairExecuteCommand`
- `php artisan atlas:product-delivery:certify --json --strict`

## Riscos

- Claim inflado por documentacao.
- Matriz desatualizada em relacao ao service.
- IA implementar fluxo paralelo por nao achar o bloco certo.

## Exemplos

Se APFPR bloqueia um delivery Dev, o bloco 20 deve gerar
`atlas.programming.dev_repair_receipt.v1`. Se bloqueia Forge, deve gerar
`atlas.forge.apfpr_repair_packet.v1`.

## Proximas Acoes

1. Adicionar Assisted Patch Generator somente depois do Patch Proposal Gate.
2. Expor Product Control Plane em surface util somente consumindo o JSON canonico.
3. Persistir release gate receipt quando houver release candidate real.
4. Manter esta matriz dentro do limite de docs-health.
