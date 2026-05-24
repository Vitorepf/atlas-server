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

### Maturidade e Definition of Done

A matriz filha conserva a tabela completa de fases, estados e criteria por
bloco. A regra executiva e simples: AEDPDS so pode subir de fase quando schema,
service/comando, teste positivo, teste blocker, certification, docs-health,
claim policy e outcome/skip reason estiverem provados.

Estado atual desta doc: Fase 22 shadow/read-only. O executor mutativo aplica
somente patch explicito aprovado com allowlist, rollback e proof rerun; ele nao
gera patch sozinho, nao chama provider, nao faz deploy e nao aplica policy
automaticamente.

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

AEDPDS nao e uma lista de metodologias. AEDPDS e o seletor operacional de
doutrina de entrega do Atlas. Ele escolhe os drivers corretos por tipo de
trabalho e nenhum driver pode ser declarado cumprido sem evidencia.

Runtime tecnico: `AtlasExecutionDoctrineRuntimeService`.
Gate tecnico: `AtlasExecutionDoctrineGateService`.
Certificacao tecnica: `AtlasAedpdsInspectionService`.
Comandos: `atlas:aedpds:select`, `atlas:aedpds:gate`,
`atlas:aedpds:inspect` e `atlas:aedpds:certify`.

A matriz tecnica completa, incluindo schemas, drivers canonicos, campos do
selector, gates especificos, integracao Dev/Forge/AEMOR/ACRUI/AUCRI e blockers
obrigatorios, fica em
`docs/engineering-knowledge-base/atlas-execution-doctrine-runtime-matrix.md`.
Esta doc mae nao duplica essa tabela para evitar drift.

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
