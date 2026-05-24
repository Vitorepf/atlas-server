---
id: atlas-execution-doctrine-product-delivery-system
type: engineering_knowledge
title: Atlas Execution Doctrine & Product Delivery System
status: active
category: product-delivery
priority: 100
summary: Doc mae que define como o Atlas transforma pedido humano ruim em verdade de produto, entrega validada e prova adversarial, usando APTC, APDR, APFPR, Dev, Forge, testes, certificacao e memoria.
human_summary: Define como o Atlas transforma pedido humano confuso em contrato, execucao Dev/Forge, prova adversarial, reparo, certificacao e memoria de resultado.
human_what: Sistema mae de doutrina e entrega de produto assistida/autonoma.
human_purpose: Impedir que Atlas AI, Atlas Dev ou Forge executem trabalho complexo como chat solto sem dominio, aceite, contrato, arquitetura, teste, evidencia e memoria.
human_input: Pedido humano, objetivo de produto, dominio, contexto, restricoes, risco, workspace, arquivos e evidencias.
human_output: Product Truth Contract, brief validado, modelo de dominio, criterios de aceite, contratos, arquitetura, prototipo quando necessario, plano Dev/Forge, verificacao, certificacao e outcome memory.
human_change_when: Atualize quando AAEQ, Atlas Dev, Forge, APDR, certificacao, outcome memory ou doutrina de entrega mudarem.
human_block_when: Bloqueie se trabalho complexo puder chegar ao provider sem brief, aceite, contrato, contexto minimo, teste, escopo e evidencia.
human_name: Atlas Execution Doctrine & Product Delivery System
canonical_name: Atlas Execution Doctrine & Product Delivery System
technical_name: atlas-execution-doctrine-product-delivery-system
product_name: Atlas Execution Doctrine & Product Delivery System
internal_product_name: Atlas Product Execution OS
technical_runtime: AtlasAutonomousProductDeliveryRuntimeService
runtime_acronym: AEDPDS
cartography_type: system
canonical_source: docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md
tags:
  - atlas
  - product-delivery
  - assisted-execution
  - autonomous-execution
  - atlas-dev
  - atlas-forge
  - canonical-glossary
capabilities:
  - execution_doctrine
  - product_truth_compilation
  - execution_lens_selection
  - adversarial_product_proof
  - product_delivery_runtime
  - human_prompt_to_verified_delivery
  - dev_forge_delivery_governance
  - acceptance_driven_execution
  - contract_driven_execution
decisions:
  - AEDPDS e a doc mae da entrega de produto por IA no Atlas.
  - APTC compila a verdade de produto antes de qualquer execucao Dev, Forge, provider ou subagente em trabalho complexo.
  - A doutrina define a regra; APDR executa a regra.
  - APFPR tenta falsificar a entrega antes de certificacao e impede falso pronto.
  - Atlas Dev resolve tarefas curtas e patches verificaveis; Forge resolve Obra longa, milestones e produto complexo.
  - Pedido humano nunca deve virar provider call bruto quando envolve produto, software, empresa, SaaS, ecommerce, fluxo critico ou mudanca de codigo.
  - DDD, ATDD, CDD, ADD, PDD, RDD, TDD e BDD sao tecnicas subordinadas ao sistema, nao fluxos concorrentes.
  - APTC deve selecionar lentes de execucao obrigatorias por tipo de pedido antes do APDR.
maintenance:
  - Atualizar quando a doutrina de execucao, APDR, AAEQ, Atlas Dev, Forge, product certification ou outcome memory mudarem.
  - Rodar docs-health apos qualquer alteracao nesta doc.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-ai-assisted-execution-quality.md
  - docs/engineering-knowledge-base/atlas-dev-runtime-intelligence.md
  - docs/engineering-knowledge-base/atlas-forge-work-packet-native-capabilities.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-ai-product-certification.md
  - docs/engineering-knowledge-base/atlas-product-falsification-proof-runtime.md
  - docs/engineering-knowledge-base/atlas-product-execution-primitives.md
  - docs/engineering-knowledge-base/atlas-execution-doctrine-runtime-matrix.md
  - app/Services/Ai/Product/AtlasAiAssistedExecutionQualityService.php
  - app/Services/Ai/Product/AtlasAiProductCertificationService.php
  - app/Services/Ai/Product/AtlasProductDeliveryRiskGovernorService.php
  - app/Services/Ai/Product/AtlasProductDeliveryControlPlaneService.php
  - app/Services/Ai/Product/AtlasProductReleaseGateService.php
  - app/Services/Ai/Product/AtlasProductExecutionPrimitivesService.php
  - app/Services/Ai/Product/AtlasProductDeliveryProviderMemoryFeedService.php
  - app/Services/Ai/Product/AtlasProductDeliveryPolicyOptimizerService.php
  - app/Services/Ai/Programming/AtlasDev/RuntimeIntelligence/DevRuntimeIntelligenceService.php
  - app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-execution-doctrine-product-delivery-system
graph_title: Atlas Execution Doctrine & Product Delivery System
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
owner: product-delivery
repo_paths:
  - docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md
allowed_changes:
  - Atualizar contratos, fases e blocos quando codigo, testes e certificacoes mudarem juntos.
  - Adicionar filhos documentais para APTC, APDR, APFPR, doutrina, acceptance runtime ou delivery certification.
forbidden_changes:
  - Executar trabalho complexo sem Product Truth Contract.
  - Declarar entrega pronta sem APFPR em escopo complexo ou risco alto.
  - Declarar APDR totalmente implementado sem service, testes, comando e certificacao.
  - Permitir provider executar trabalho complexo sem contexto, aceite, contrato, escopo e evidencia.
  - Confundir Atlas Dev com Forge ou transformar Forge em patch curto.
depends_on:
  - atlas-ai-assisted-execution-quality
  - atlas-dev-runtime-intelligence
  - atlas-forge-work-packet-native-capabilities
flows_to:
  - atlas-ai-product-certification
  - atlas-programming-forge-flow
unlocks:
  - product-delivery-os
  - product-truth-compiler
  - autonomous-product-delivery-runtime
  - product-falsification-proof-runtime
governs:
  - atlas-ai
  - programming.dev
  - programming.forge
  - product-delivery
evidence:
  - docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md
  - docs/engineering-knowledge-base/atlas-ai-assisted-execution-quality.md
  - docs/engineering-knowledge-base/atlas-dev-runtime-intelligence.md
  - docs/engineering-knowledge-base/atlas-forge-work-packet-native-capabilities.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:product-certify --json"
  - "php artisan atlas:product-delivery:primitives 'estou com bug na tela de login' --workspace=atlas-app --operator-approved --ux='login visual regression expectation' --json --strict"
requires_evidence: true
risk_level: high
visual_tags:
  - system
  - product-delivery
  - assisted-execution
ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Escopo de Implementacao e Riscos antes de propor fluxo de produto.
ai_usage_notes:
  - Use AEDPDS para decidir como um pedido humano vira brief, aceite, contrato, arquitetura, Dev/Forge, verificacao e memoria.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:product-certify --json"
  - "php artisan atlas:product-delivery:certify --json --strict"
  - "php artisan atlas:product-delivery:primitives 'estou com bug na tela de login' --workspace=atlas-app --operator-approved --ux='login visual regression expectation' --json --strict"
failure_modes:
  - Intencao humana crua passa direto para execucao.
  - Chat solto substitui contrato de produto.
  - IA implementa antes de entender dominio e criterio de aceite.
  - Dev tenta executar Obra longa.
  - Forge executa packet sem contexto minimo.
  - Produto e declarado pronto sem certificacao e outcome memory.
  - Entrega passa sem tentativa formal de falsificacao.
observability_signals:
  - docs-health status ok
  - product-certify ready
next_actions:
  - Evoluir de executor de patch explicito aprovado para geracao assistida de patch sem pular Proposal Gate.
  - Persistir proof challenge dedicado em receipts quando houver execucao real.
  - Manter `/ai/interactions` usando APDR antes de AAEQ/Dev/Forge.
  - Manter Autonomous Product Release Gate read-only ate existir approval de release real.
  - Rodar `atlas:product-delivery:certify --json --strict` apos mudancas.
---
# Atlas Execution Doctrine & Product Delivery System

## Resumo

AEDPDS e a camada mae para entrega de produto por IA no Atlas. Ela define como
um pedido humano incompleto, cansado, ambiguo ou grande demais vira uma entrega
validada: software, SaaS, ecommerce, empresa, automacao, sistema interno, Obra
Forge ou patch Dev.

Regra central:

```text
A intencao crua nunca executa.
APTC compila a verdade do produto.
A doutrina e a regra.
APDR e o motor que executa a regra.
APFPR tenta provar que a entrega esta errada.
Atlas Product Execution OS e a superficie humana dessa operacao.
```

## Papel no Atlas

O Atlas nao deve depender de prompt perfeito do operador. O papel do AEDPDS e
forcar o sistema a transformar fala humana em contrato de entrega antes de
executar provider, subagente, Dev ou Forge.

Ele unifica tecnicas conhecidas de engenharia:

| Lente | Papel no Atlas |
|---|---|
| DDD | Dominio, linguagem, entidades, bounded contexts e regras reais. |
| ATDD/BDD/TDD | Aceite, comportamento e prova automatizada. |
| CDD/API-first | Contratos de API, eventos, payloads, schemas e integracoes. |
| ADD | Arquitetura, atributos de qualidade, seguranca, escala e limites. |
| PDD/UX-driven | Prototipo, jornada e descoberta quando experiencia estiver incerta. |
| RDD/Documentation-driven | Uso, README e doc canonica antes da implementacao. |
| FDD | Quebra de produto em funcionalidades pequenas e entregaveis. |
| Risk/Security-driven | Ordem por risco, ameacas, permissoes, dados, auth e billing. |
| Performance/Observability-driven | Latencia, escala, profiling, telemetria e operacao. |
| Data/DbDD/MDD | Dados, schema, modelo formal, banco, analytics e geracao futura. |

## Onde Se Encaixa

AEDPDS fica acima de AAEQ, Atlas Dev e Forge.

```text
Atlas AI
  -> AEDPDS
      -> APTC
      -> Atlas Assisted Execution Doctrine
      -> APDR
      -> APFPR
          -> AAEQ
              -> Atlas Dev para tarefa curta
              -> Forge para Obra longa
          -> Certification
          -> Outcome Memory
          -> Control Plane
```

AAEQ ja protege o caminho de execucao assistida. AEDPDS e mais amplo: define a
doutrina de entrega de produto inteira, incluindo dominio, aceite, contrato,
arquitetura, prototipo, execucao, validacao e aprendizado.

APTC e a camada mais alta dentro do AEDPDS. Ele compila o pedido humano em uma
verdade de produto executavel antes de qualquer decisao Dev/Forge. Sem APTC,
um pedido como `cria um ecommerce` pode parecer simples demais e gerar codigo
cedo demais; com APTC, o Atlas primeiro cria dominio, aceite, contratos,
arquitetura, prova e rota.

O selector AEDPDS roda antes e junto do APDR. APTC ainda compila Product Truth;
AEDPDS escolhe os drivers operacionais obrigatorios; APDR carrega essa selecao
no envelope `aedpds.doctrine` e o resultado do gate em `aedpds.gate`. APDR nao
pode declarar `ready_for_delivery` quando `aedpds.gate.status` estiver
`blocked`; nesse caso o status canonico e `blocked_by_aedpds_gate`.

## Versao Maxima

AEDPDS em estado final nao e uma metodologia unica. Ele e um sistema de
composicao de doutrinas de entrega. A regra e escolher a menor combinacao de
lentes que preserva qualidade e prova, sem transformar trabalho simples em
burocracia.

Principio final:

```text
pedido humano
  -> verdade de produto
  -> contrato de execucao
  -> rota Dev/Forge
  -> prova adversarial
  -> repair bridge
  -> certificacao
  -> outcome memory
  -> AEMOR Judgment Guard
  -> aprendizado permitido somente com evidencia
```

Limite de claim: AEDPDS ja possui runtime provider-free, comandos, testes,
certificacao e wiring em `/ai/interactions`. Ele ainda nao deve declarar
auto-correcao mutativa completa sem operador. O executor mutativo atual aplica
patch explicito aprovado com allowlist, rollback e proof rerun; ele nao gera o
patch sozinho.

### Estado final apex

O limite maximo do AEDPDS e virar o sistema operacional de entrega de produto:
pedido ruim vira Product Truth, dominio, aceite, contratos, simulacao, rota
Dev/Forge, patch ou Obra, prova adversarial, reparo controlado, certificacao,
memoria julgada e melhoria da proxima entrega.

Nesse estado, o Atlas nao melhora por "prompt melhor"; melhora porque cada
entrega vira evidencia reutilizavel. A matriz filha define os loops apex:
Truth, Proof, Repair, Memory e Reality, alem da fronteira de autonomia.
Regra: nenhum nivel superior pode pular os gates do nivel inferior.

### Versao mais absurda permitida

A versao maxima nao e "autonomia total". E autonomia governada por realidade.
O Atlas so aumenta poder de execucao quando Truth, Context, Simulation, Risk,
Proof, Replay, Fitness, Receipt e Memory estao coerentes. Se qualquer eixo
critico falhar, o sistema reduz autonomia, pede operador ou escala para Forge.

## Contratos

### Nomes obrigatorios

| Campo | Valor |
|---|---|
| Nome canonico / produto | Atlas Execution Doctrine & Product Delivery System |
| Acronimo tecnico | AEDPDS |
| Nome interno de experiencia / superficie | Atlas Product Execution OS |
| Compilador de verdade | Atlas Product Truth Compiler |
| Acronimo do compilador | APTC |
| Superficie do compilador | Product Reality Compiler |
| Runtime tecnico do compilador | `AtlasProductTruthCompilerService` |
| Doutrina filha | Atlas Assisted Execution Doctrine |
| Runtime filho | Atlas Autonomous Product Delivery Runtime |
| Acronimo do runtime filho | APDR |
| Runtime tecnico planejado | `AtlasAutonomousProductDeliveryRuntimeService` |
| Runtime de falsificacao | Atlas Product Falsification & Proof Runtime |
| Acronimo de falsificacao | APFPR |
| Superficie de falsificacao | Atlas Proof Challenge |
| Runtime tecnico de falsificacao | `AtlasProductFalsificationProofRuntimeService` |

Nenhuma IA deve criar nomes alternativos para esta area sem atualizar esta doc,
glossario, Cartografia e certificacao.

### Envelope minimo

Todo trabalho complexo precisa produzir:

| Campo | Obrigatorio |
|---|---|
| schema/status/route | Sim |
| product_truth | Sim |
| normalized human request | Sim |
| execution lenses | Sim |
| domain/acceptance/contracts/architecture | Sim para produto complexo |
| execution/tests/verification/memory | Sim |

### Product Truth Contract

APTC deve produzir `atlas.product_truth_contract.v1` antes do APDR. Esse
contrato e a fronteira entre "o humano pediu" e "o Atlas entendeu o que pode
ser executado".

Campos canonicos: `schema_version`, `status`, `product_intent`,
`execution_lenses`, `business_domain`, `acceptance_universe`, `contract_map`,
`architecture_constraints`, `execution_decomposition`, `proof_plan` e
`human_questions`. A matriz tecnica fica em
`atlas-execution-doctrine-runtime-matrix.md`.

### Execution Lens Matrix

APTC nao aplica sempre o mesmo processo. Ele escolhe lentes obrigatorias por
tipo de pedido. Lente obrigatoria ausente vira blocker; lente opcional ausente
vira nota de risco ou proxima acao.

| Pedido | Lentes obrigatorias | Bloqueia se faltar |
|---|---|---|
| Bug pequeno | TDD, BDD, Risk-driven | teste focado, erro minimo, criterio de sucesso |
| Login, auth, permissao | TDD, BDD, Security-driven, Risk-driven | modelo de risco, teste de auth, scope guard |
| Ecommerce, SaaS, empresa | DDD, ATDD, CDD, ADD, FDD, Security-driven | dominio, aceite, contrato, arquitetura, prova |
| API, webhook, integracao | CDD, API-first, TDD, Observability-driven | schema, payload, contrato, teste de integracao |
| UI/fluxo incerto | UX-driven, PDD, ATDD, BDD | jornada, prototipo ou criterio de aceite visual |
| Banco, sync, analytics | DbDD, Data-driven, TDD, Observability-driven | schema, migration, query, evidencia de dados |
| Performance/indexacao | Performance-driven, Observability-driven, ADD, TDD | baseline, metrica, profiling ou teste de regressao |
| Runtime critico/background | ADD, Reliability-driven, Observability-driven, Risk-driven | health signal, retry policy, failure mode, rollback |
| Produto com docs publicas | RDD, Documentation-driven, ATDD | README/uso, contrato humano, criterio de pronto |
| Grande Obra multi-modulo | FDD, DDD, ADD, Risk-driven, ATDD | decomposicao, milestones, work packets, riscos |

Regra de decisao: sinais de usuario, API, dominio, auth, performance, docs,
banco e arquitetura adicionam lentes obrigatorias. O formato canonico fica no
Product Truth Contract e na matriz filha.

## Fluxo

```text
pedido humano ruim ou incompleto
  -> APTC compila Product Truth Contract
  -> APTC seleciona execution lenses
  -> normalizacao de intencao
  -> brief de produto
  -> modelagem de dominio
  -> criterios de aceite
  -> contratos de integracao/dados
  -> arquitetura e riscos
  -> prototipo quando necessario
  -> decisao Dev/Forge
  -> execucao assistida
  -> testes e repair loop
  -> APFPR falsifica e desafia a entrega
  -> certificacao
  -> outcome memory
  -> control plane
```

### Decisao Dev ou Forge

| Caso | Rota |
|---|---|
| Bug pequeno, patch curto, arquivo claro, teste claro | Atlas Dev |
| Refactor multi-modulo, produto, milestone, varios agentes, incerteza alta | Forge |
| Ideia de negocio, SaaS, ecommerce ou empresa sem escopo claro | Brief + DDD + ATDD antes de Dev/Forge |
| UX incerta ou fluxo humano novo | PDD/prototipo antes de arquitetura final |
| Integracao/API/eventos | CDD obrigatorio antes de codigo |
| Risco alto em auth, billing, provider, dados, seguranca ou migrations | ADD + senior review + certificacao |

## Regras para IA

- Se o trabalho for complexo, primeiro gere ou exija Product Truth Contract.
- Nao execute trabalho complexo a partir do prompt bruto do humano.
- Nao deixe modelo barato, subagente ou provider decidir verdade de produto sem gate.
- Nao escreva codigo antes de brief, aceite e arquitetura minima.
- Nao crie contrato de API depois do codigo quando CDD for aplicavel.
- Nao trate ecommerce, SaaS, empresa ou produto como patch simples.
- Nao use Forge para tarefa curta que Dev resolve com menos risco.
- Nao use Dev como Forge improvisado.
- Nao declare pronto sem evidencia, testes e criterio de aceite fechado.
- Nao confunda documentacao de doutrina com runtime implementado.
- Se faltar dominio, usuario, objetivo, restricao ou criterio de pronto, retorne `needs_context`.
- Se a tarefa for ambigua mas segura, gere perguntas minimas e plano incremental.

## Escopo de Implementacao

O detalhe implementavel dos 36 blocos fica na doc filha:
`docs/engineering-knowledge-base/atlas-execution-doctrine-runtime-matrix.md`.

Esta doc mae conserva a doutrina, o fluxo e a regra de governanca. A doc filha
conserva matriz de bloco, input/output, risco, comando, teste e prova para IA
implementar sem inflar a especificacao mae.

### Fases de maturidade

| Fase | Nome | Criterio |
|---:|---|---|
| 0 | Canonical Doctrine | Esta doc existe e passa docs-health. |
| 1 | APTC Spec | Doc filha APTC define Product Truth Contract, schemas, comandos e testes. |
| 2 | APDR Spec | Doc filha APDR define delivery contract, comandos e testes. |
| 3 | APFPR Spec | Doc filha APFPR define falsificacao, prova e blockers. |
| 4 | Truth Shadow Runtime | APTC gera Product Truth Contract sem alterar execucao. |
| 5 | Delivery Shadow Runtime | APDR gera envelope sem alterar execucao. |
| 6 | Proof Shadow Runtime | APFPR tenta reprovar sem bloquear execucao. |
| 7 | Dev/Forge Opt-in | Dev/Forge consomem truth/delivery/proof envelope em modo opt-in. |
| 8 | Partial Enforcement | Risco alto exige Product Truth Contract e APFPR antes do provider. |
| 9 | Default Enforcement | Todo produto complexo passa por APTC + APDR + APFPR. |
| 10 | Self-Improving Delivery | Outcome memory melhora rotas, testes e criterios. |
| 11 | Mutative Repair Autonomy | Repair bridge executa reparo real com rollback, receipts e human override. |
| 12 | Assisted Patch Generation | Atlas gera patch candidato, mas Proposal Gate decide. |
| 13 | Multi-Step Repair Plan | Reparo vira plano de passos com budget, testes e rollback por passo. |
| 14 | Product Twin Simulation | Simula impacto em dominio, contratos, testes, UI e operacao antes de executar. |
| 15 | Evidence Replay Lab | Reexecuta cenarios e receipts para provar que a doutrina nao regrediu. |
| 16 | Doctrine Fitness Loop | Mede qual lente/gate melhorou ou piorou entrega por outcome real. |
| 17 | Autonomous Delivery Governance | Autonomia cresce ou recua por score de prova, custo, risco e historico. |
| 18 | Delivery Risk Governor | Calcula autonomia permitida, aprovacoes e gates antes de provider, patch ou completion. |
| 19 | Product Control Plane | Agrega delivery, risk, replay, fitness, receipts e certification em snapshot canonico. |
| 20 | Autonomous Product Release Gate | Decide release candidate somente com prova completa e blockers zerados. |
| 21 | Provider/Cost/Flake Memory Feed | Alimenta Risk Governor com falha de provider, custo e flake vindos de receipts/outcomes. |
| 22 | Product Policy Optimizer | Propoe ajuste de doutrina por replay, fitness e provider memory sem aplicar policy. |

Estado atual desta doc: Fase 22 shadow/read-only. APTC, APDR, APFPR, enforcement
pre-provider e pos-execucao, repair bridge Dev/Forge, outcome memory persistida,
ponte AEMOR, Patch Request Contract, Patch Proposal Gate, mutative repair
executor, Runtime Receipt Ledger, Product Twin Simulation e Multi-Step Repair
Planner, Evidence Replay Lab, Doctrine Fitness Loop, Delivery Risk Governor e
Product Control Plane, Autonomous Product Release Gate e Provider/Cost/Flake
Memory Feed e Product Policy Optimizer existem com testes e certification. O executor mutativo
aplica somente patch explicito aprovado, em arquivos permitidos, com rollback
snapshot e rerun de prova; ele nao gera patch sozinho nem chama provider.
Product Twin, Replay, Fitness, Risk Governor, Control Plane, Release Gate e
Provider Memory e Policy Optimizer sao read-only: simulam, agregam, bloqueiam,
autorizam candidato ou recomendam; nao executam provider, nao fazem deploy e
nao aplicam policy automaticamente.

### Definition of Done

AEDPDS so pode ser declarado pronto em uma fase quando:

- o schema da fase existe;
- o comando ou service existe;
- existe teste que prova comportamento positivo e blocker;
- `atlas:product-delivery:certify --json --strict` passa;
- `atlas:ai:product-certify --json --strict` inclui a evidencia;
- docs-health passa;
- claim policy declara `provider_invoked=false` quando a fase for provider-free;
- outcome memory ou justificativa de skip esta materializada.

Para Fase 11 controlada, os criterios adicionais sao:

- executor mutativo recebe patch manifest explicito;
- provider/subagente recebe Patch Request Contract antes de propor patch;
- patch de provider/subagente passa por Patch Proposal Gate;
- aplicacao de patch em risco alto exige approval do operador;
- patch tem rollback ou diff isolado;
- failures viram capsule;
- repair reroda APFPR;
- completion enforcement bloqueia se repair nao provar o blocker;
- patch request, patch gate e repair execution podem ser persistidos em receipt append-only;
- AEMOR Judgment Guard permite ou nega aprendizado.

Para fases 12-16, os criterios adicionais sao:

- gerador de patch nao pode escrever direto;
- todo patch gerado precisa manifest, hash esperado, allowlist e nao-goals;
- repair multi-step precisa budget de CPU/token/tempo e rollback por passo;
- Product Twin precisa prever arquivos, testes, contratos e risco antes da execucao;
- Evidence Replay precisa reexecutar cenarios canonicos e bloquear receipt mutativo sem approval;
- Doctrine Fitness precisa comparar resultado real contra rota, evidencia e reparo recorrente;
- Delivery Risk Governor precisa bloquear autonomia quando faltarem approval, proof, simulacao ou replay seguro;
- Product Control Plane precisa agregar risk, replay, fitness, receipts e certification sem side effects;
- Autonomous Product Release Gate precisa exigir Control Plane healthy, certification ready, replay ready, risk allowed, proof ready e receipts seguros;
- Provider/Cost/Flake Memory Feed deriva sinais somente de receipts/outcomes reais;
- Product Policy Optimizer so propoe politica; nunca aplica sem AEMOR Judgment e operador;
- autonomia precisa reduzir automaticamente quando houver regressao, flake, custo alto ou approval negado.

## Dependencias

Services: AAEQ, Atlas Dev Runtime, Forge Work Packet Cycle, Forge Capability Orchestrator e Product Certification. Docs: glossary, AAEQ e Dev/FWPC specs.

## Evidencias

Evidencia atual: selector AEDPDS, gate AEDPDS, APTC, APDR, APFPR, enforcement,
repair bridge, outcome memory, AEMOR bridge, mutative repair, patch request/gate,
receipts, Product Twin, repair planner, replay lab, fitness, risk, control
plane, release gate, provider memory e policy optimizer.
Comandos canonicos: `product-truth:compile`, `product-proof:challenge`, `product-twin:simulate`, `patch-request`, `repair-execute` e `product-delivery:*`.
Comandos AEDPDS canonicos: `atlas:aedpds:inspect`, `atlas:aedpds:select`,
`atlas:aedpds:gate` e `atlas:aedpds:certify`.
`patch-request` e `repair-execute` aceitam `--persist` para ledger append-only.
O wiring roda em Atlas AI/APDR antes de Dev/Forge; Atlas Dev recebe drivers no
Task Packet, projeta contexto/testes/evidencia e inclui AEDPDS na run
certification; Forge recebe a projecao AEDPDS no Work Intake para Work Packets,
risco, revisao e escalacao. Forge Work Packet Capability Orchestrator tambem
materializa bloco `AEDPDS`, e Forge Outcome Memory persiste drivers, gate hash,
doctrine hash e efetividade por driver para aprendizado posterior. Docs-health,
`atlas:aedpds:certify` e product-certify devem permanecer verdes.

## AEDPDS Runtime Gate Canonico

Nome canonico / produto: Atlas Execution Doctrine & Product Delivery System.
Acronimo tecnico: AEDPDS.

AEDPDS nao e uma lista de metodologias. AEDPDS e o seletor operacional de
doutrina de entrega do Atlas. Ele escolhe os drivers corretos por tipo de
trabalho e nenhum driver pode ser declarado cumprido sem evidencia.

Runtime tecnico:

- `AtlasExecutionDoctrineRuntimeService`
- schema `atlas.aedpds.execution_doctrine.v1`
- comando `php artisan atlas:aedpds:select --task="..." --surface=dev --json`

Gate tecnico:

- `AtlasExecutionDoctrineGateService`
- schema `atlas.aedpds.execution_gate.v1`
- comando `php artisan atlas:aedpds:gate --task="..." --surface=dev --json`
- status `passed | warning | blocked`
- valida aceite, contexto, testes, contratos, docs, UX/prototipo, modelo
  formal/semi-formal, observabilidade/readiness, risk review, senior review e
  evidencia esperada antes de execucao relevante.
- o comando nao injeta artefatos falsos. Para passar o gate via CLI, forneca
  explicitamente `--acceptance`, `--context`, `--test`, `--contract`, `--doc`,
  `--review`, `--evidence` e `--ux` conforme os drivers selecionados. Em
  `--strict`, ausencia de artefato obrigatorio deve sair com codigo diferente
  de zero.

Certificacao tecnica:

- `AtlasAedpdsInspectionService`
- schema `atlas.aedpds.certification.v1`
- comandos `atlas:aedpds:inspect` e `atlas:aedpds:certify`

Contrato canonico do selector:

- request_id / trace_id quando disponivel;
- surface: `atlas_ai`, `atlas_dev`, `atlas_forge`, `cartografia`, `control_plane`;
- workspace/project;
- task_type;
- user_intent_summary;
- ambiguity_level;
- risk_level;
- selected_primary_drivers;
- selected_secondary_drivers;
- required_artifacts;
- required_context;
- required_tests;
- required_contracts;
- required_docs;
- required_review;
- required_evidence;
- blockers;
- warnings;
- allowed_to_execute;
- reason;
- certification_hash.

Drivers canonicos:

- `tdd`
- `bdd`
- `atdd`
- `fdd`
- `sdd`
- `cdd`
- `api_first`
- `documentation_driven`
- `readme_driven`
- `domain_driven_design`
- `model_driven`
- `database_driven`
- `prototype_driven`
- `ux_driven`
- `risk_driven`
- `architecture_driven`
- `security_driven`
- `performance_driven`
- `reliability_observability_driven`
- `data_evidence_driven`

Relacoes operacionais:

- APDR executa produto; AEDPDS define a doutrina de entrega que APDR deve carregar no envelope.
- Atlas Dev usa AEDPDS no `DevTaskPacketRuntimeService`, entrega `required_context` para `DevContextGate`, projeta `required_tests` em test impact, grava evidencia esperada e inclui bloco AEDPDS em `DevRunCertification`.
- Atlas Forge usa AEDPDS em Work Intake/Work Packets para drivers, gates, contexto, testes, evidencia, senior review e escalacao; Forge Outcome Memory registra efetividade da doutrina por driver.
- AEMOR aprende se a doutrina funcionou por outcome memory e sinais de efetividade.
- ACRUI impede duplicacao, scaffold falso e claim contra realidade antes de criar runtime paralelo.
- AUCRI/context deve fornecer contexto minimo proporcional aos drivers escolhidos.
- Control Plane e certificacao devem expor AEDPDS como readiness/audit, nao como texto promocional.

Gates especificos:

- Todo envelope com `required_context` precisa carregar ao menos um contexto
  real (`--context`, owner doc, context pack, arquivo provavel ou evidencia de
  contexto). Sem isso o gate bloqueia com `missing_minimum_context_ref`; aceitar
  teste/evidencia sem contexto e falso readiness.
- `model_driven` exige especificacao de modelo ou state machine e contrato
  formal/semi-formal antes de execucao.
- `reliability_observability_driven` exige logs, traces, receipts ou readiness
  signal quando observabilidade/readiness for driver primario.
- APDR deve propagar bloqueio AEDPDS para o status do envelope e para
  enforcement pre-provider; carregar o gate no envelope sem bloquear e falso
  readiness.
- APFPR deve bloquear `delivery_contract_not_ready` quando o APDR nao estiver
  `ready_for_delivery`; prova nao pode certificar envelope bloqueado pelo
  AEDPDS.
- Patch Request e Multi-Step Repair Plan tambem devem bloquear
  `delivery_contract_not_ready`; provider/subagente nao pode receber projecao
  de patch nem plano de reparo sobre APDR bloqueado por gate.

Regra de claim: docs nao bastam para declarar pronto. `atlas:aedpds:certify
--json --strict` so pode retornar `ready` quando doc, selector deterministico,
gate explicito, Dev, Forge, tests, receipts/outcome e comandos estiverem
presentes com checks granulares.

## Riscos

- Overengineering: usar pipeline pesado para pergunta simples.
- Underengineering: tratar empresa, SaaS ou ecommerce como patch solto.
- Falso pronto: concluir sem aceite, teste e evidencia.
- Drift: doutrina documentada mas runtime nao usa.
- Duplicacao: criar outro sistema paralelo de produto fora de AEDPDS.
- Prompt perfeito como requisito: o Atlas falha se depender do humano explicar tudo.
- Product Truth incompleto: APTC gera contrato bonito mas sem regra real de negocio.
- Economia falsa de pergunta: perguntar pouco demais e deixar a IA inventar.

## Exemplos

| Pedido | Rota correta |
|---|---|
| `bug na tela de login` | APTC leve -> AAEQ -> Dev -> context gate -> test impact -> patch -> test -> cert -> outcome |
| `cria um ecommerce` | AEDPDS -> APTC -> DDD/ATDD/CDD/ADD -> Forge Obra -> milestones -> certification -> outcome |
| `integra pagamento e webhook` | APTC -> CDD obrigatorio -> threat/risk -> contract tests -> Dev/Forge conforme escopo |

## Proximas Acoes

1. Geracao assistida de patch sem pular Proposal Gate.
2. Proof challenge dedicado em receipts.
3. Persistir release gate receipt quando houver release candidate real.
4. UI util somente depois do JSON canonico continuar verde.
