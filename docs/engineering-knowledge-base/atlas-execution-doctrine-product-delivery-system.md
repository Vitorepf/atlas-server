---
id: atlas-execution-doctrine-product-delivery-system
type: engineering_knowledge
title: Atlas Execution Doctrine & Product Delivery System
status: active
category: product-delivery
priority: 100
summary: Doc mae que define como o Atlas transforma pedido humano ruim ou incompleto em verdade de produto executavel e entrega validada, usando APTC, doutrina, Dev, Forge, contratos, testes, certificacao e memoria.
human_summary: Define como o Atlas compila um pedido humano confuso em verdade de produto e depois entrega software, SaaS, ecommerce, empresa ou fluxo validado.
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
  - product_delivery_runtime
  - human_prompt_to_verified_delivery
  - dev_forge_delivery_governance
  - acceptance_driven_execution
  - contract_driven_execution
decisions:
  - AEDPDS e a doc mae da entrega de produto por IA no Atlas.
  - APTC compila a verdade de produto antes de qualquer execucao Dev, Forge, provider ou subagente em trabalho complexo.
  - A doutrina define a regra; APDR executa a regra.
  - Atlas Dev resolve tarefas curtas e patches verificaveis; Forge resolve Obra longa, milestones e produto complexo.
  - Pedido humano nunca deve virar provider call bruto quando envolve produto, software, empresa, SaaS, ecommerce, fluxo critico ou mudanca de codigo.
  - DDD, ATDD, CDD, ADD, PDD, RDD, TDD e BDD sao tecnicas subordinadas ao sistema, nao fluxos concorrentes.
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
  - app/Services/Ai/Product/AtlasAiAssistedExecutionQualityService.php
  - app/Services/Ai/Product/AtlasAiProductCertificationService.php
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
  - Adicionar filhos documentais para APTC, APDR, doutrina, acceptance runtime ou delivery certification.
forbidden_changes:
  - Executar trabalho complexo sem Product Truth Contract.
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
failure_modes:
  - Intencao humana crua passa direto para execucao.
  - Chat solto substitui contrato de produto.
  - IA implementa antes de entender dominio e criterio de aceite.
  - Dev tenta executar Obra longa.
  - Forge executa packet sem contexto minimo.
  - Produto e declarado pronto sem certificacao e outcome memory.
observability_signals:
  - docs-health status ok
  - product-certify ready
next_actions:
  - Criar doc filha do Atlas Product Truth Compiler.
  - Criar doc filha da Atlas Assisted Execution Doctrine.
  - Criar doc filha do APDR antes de implementar runtime tecnico.
  - Implementar `AtlasAutonomousProductDeliveryRuntimeService` somente com testes e certificacao.
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
Atlas Product Execution OS e a superficie humana dessa operacao.
```

## Papel no Atlas

O Atlas nao deve depender de prompt perfeito do operador. O papel do AEDPDS e
forcar o sistema a transformar fala humana em contrato de entrega antes de
executar provider, subagente, Dev ou Forge.

Ele unifica tecnicas conhecidas de engenharia:

| Tecnica | Papel no Atlas |
|---|---|
| DDD | Entender dominio, linguagem, entidades, bounded contexts e regras reais. |
| ATDD | Definir quando a entrega esta pronta antes de implementar. |
| BDD | Descrever comportamento esperado do usuario/sistema. |
| CDD | Definir contratos de API, eventos, payloads, schemas e integracoes. |
| ADD | Garantir arquitetura, atributos de qualidade, seguranca, escala e limites. |
| PDD | Prototipar quando UX, fluxo ou viabilidade estiverem incertos. |
| RDD | Escrever uso, README ou guia primeiro quando produto/API/CLI exigir clareza. |
| TDD | Provar implementacao com testes focados e regressao. |
| MDD | Futuro: gerar ou validar partes a partir de modelos formais. |

## Onde Se Encaixa

AEDPDS fica acima de AAEQ, Atlas Dev e Forge.

```text
Atlas AI
  -> AEDPDS
      -> APTC
      -> Atlas Assisted Execution Doctrine
      -> APDR
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

Nenhuma IA deve criar nomes alternativos para esta area sem atualizar esta doc,
glossario, Cartografia e certificacao.

### Envelope minimo

Todo trabalho complexo precisa produzir:

```json
{
  "schema_version": "atlas.product_delivery.contract.v1",
  "status": "ready|needs_context|blocked",
  "route": "atlas_dev|atlas_forge|research|prototype|blocked",
  "product_truth": {
    "schema_version": "atlas.product_truth_contract.v1",
    "compiled_from_human_request": true,
    "confidence": "high|medium|low",
    "missing_truth": []
  },
  "human_request": {"normalized_goal": "...", "ambiguities": []},
  "domain_model": {"bounded_contexts": [], "entities": [], "rules": []},
  "acceptance": {"criteria": [], "scenarios": [], "definition_of_done": []},
  "contracts": {"apis": [], "events": [], "payloads": [], "schemas": []},
  "architecture": {"constraints": [], "quality_attributes": [], "risks": []},
  "prototype": {"required": false, "reason": null},
  "execution": {"dev_task": null, "forge_obra": null, "tests": []},
  "verification": {"required_gates": [], "evidence": []},
  "memory": {"outcome_required": true}
}
```

### Product Truth Contract

APTC deve produzir `atlas.product_truth_contract.v1` antes do APDR. Esse
contrato e a fronteira entre "o humano pediu" e "o Atlas entendeu o que pode
ser executado".

```json
{
  "schema_version": "atlas.product_truth_contract.v1",
  "status": "ready|needs_context|blocked",
  "product_intent": {"kind": "bug|feature|product|company|automation|research"},
  "business_domain": {"actors": [], "objects": [], "rules": []},
  "acceptance_universe": {"must_work": [], "must_not_break": []},
  "contract_map": {"apis": [], "events": [], "data_shapes": []},
  "architecture_constraints": {"security": [], "performance": [], "privacy": []},
  "execution_decomposition": {"route": "atlas_dev|atlas_forge|multi_obra"},
  "proof_plan": {"tests": [], "gates": [], "evidence": []},
  "human_questions": {"minimum_required": []}
}
```

## Fluxo

```text
pedido humano ruim ou incompleto
  -> APTC compila Product Truth Contract
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

- Nao execute trabalho complexo a partir do prompt bruto do humano.
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

### Blocos do sistema

| Ordem | Bloco | Funcao | Estado |
|---:|---|---|---|
| 1 | Human Intent Normalization | Traduz pedido ruim em objetivo verificavel. | design_canonical |
| 2 | Product Brief Runtime | Cria problema, publico, resultado e limites. | design_canonical |
| 3 | Domain Modeling Runtime | Aplica DDD leve/profundo conforme risco. | design_canonical |
| 4 | Acceptance Runtime | Aplica ATDD/BDD e Definition of Done. | design_canonical |
| 5 | Contract Runtime | Aplica CDD para API, eventos, payloads e schemas. | design_canonical |
| 6 | Architecture Runtime | Aplica ADD, riscos e atributos de qualidade. | design_canonical |
| 7 | Prototype Runtime | Aplica PDD quando UX/viabilidade nao estao claros. | design_canonical |
| 8 | Execution Planner | Decide Dev, Forge, Research ou bloqueio. | design_canonical |
| 9 | Dev Delivery Bridge | Usa ADRI para tarefas curtas. | partially_implemented_via_dev |
| 10 | Forge Delivery Bridge | Usa Forge native capabilities para Obra longa. | partially_implemented_via_forge |
| 11 | Verification & Repair Runtime | Testes, failure capsule e repair loop. | partially_implemented |
| 12 | Delivery Certification Runtime | Certifica entrega e bloqueia falso pronto. | partially_implemented |
| 13 | Outcome Memory Runtime | Aprende com resultado real. | partially_implemented |
| 14 | Product Control Plane | Mostra status, blockers e proximas acoes. | planned |

### Fases de maturidade

| Fase | Nome | Criterio |
|---:|---|---|
| 0 | Canonical Doctrine | Esta doc existe e passa docs-health. |
| 1 | APDR Spec | Doc filha APDR define schemas, comandos e testes. |
| 2 | Shadow Runtime | APDR gera envelope sem alterar execucao. |
| 3 | Dev/Forge Opt-in | Dev/Forge consomem envelope em modo opt-in. |
| 4 | Partial Enforcement | Risco alto exige AEDPDS antes do provider. |
| 5 | Default Enforcement | Todo produto complexo passa por AEDPDS. |
| 6 | Self-Improving Delivery | Outcome memory melhora rotas, testes e criterios. |

Estado atual desta doc: Fase 0. AAEQ, ADRI e Forge native capabilities ja
cobrem partes do caminho, mas APDR completo ainda deve ser implementado e
certificado antes de ser declarado runtime padrao.

## Dependencias

- `AtlasAiAssistedExecutionQualityService`
- `AtlasDevRuntimeService`
- `DevRuntimeIntelligenceService`
- `ForgeWorkPacketExecutionCycleService`
- `ForgeWorkPacketCapabilityOrchestrator`
- `AtlasAiProductCertificationService`
- `atlas-canonical-glossary-and-naming.md`
- `atlas-ai-assisted-execution-quality.md`
- `atlas-dev-runtime-intelligence.md`
- `atlas-forge-work-packet-native-capabilities.md`

## Evidencias

Evidencia atual:

- Esta doc define a doutrina mae.
- AAEQ ja transforma pedidos de programacao em contrato assistido.
- ADRI ja define blocos Dev para tarefa curta.
- Forge native capabilities ja define blocos Forge para work packet.
- Product certification ja audita partes do Atlas AI runtime.

Evidencia futura necessaria para declarar APDR completo:

- `AtlasAutonomousProductDeliveryRuntimeService`
- testes de envelope AEDPDS/APDR;
- comando `php artisan atlas:product-delivery:certify --json`;
- wiring em Atlas AI antes de Dev/Forge;
- outcome memory real por entrega;
- docs-health e product-certify verdes.

## Riscos

- Overengineering: usar pipeline pesado para pergunta simples.
- Underengineering: tratar empresa, SaaS ou ecommerce como patch solto.
- Falso pronto: concluir sem aceite, teste e evidencia.
- Drift: doutrina documentada mas runtime nao usa.
- Duplicacao: criar outro sistema paralelo de produto fora de AEDPDS.
- Prompt perfeito como requisito: o Atlas falha se depender do humano explicar tudo.

## Exemplos

### Bug simples

Pedido: `estou com bug na tela de login`.

Rota correta:

```text
AAEQ -> Atlas Dev -> context gate -> test impact -> patch -> test -> run cert -> outcome
```

### Produto complexo

Pedido: `cria um ecommerce`.

Rota correta:

```text
AEDPDS -> brief -> DDD -> ATDD -> CDD -> ADD -> PDD se necessario
  -> Forge Obra -> milestones -> work packets -> certification -> outcome
```

### Integracao

Pedido: `integra pagamento e webhook`.

Rota correta:

```text
CDD obrigatorio -> threat/risk -> contract tests -> Dev/Forge conforme escopo
```

## Proximas Acoes

1. Criar `atlas-assisted-execution-doctrine.md` como doc filha de regras.
2. Criar `atlas-autonomous-product-delivery-runtime.md` como doc filha APDR.
3. Implementar `AtlasAutonomousProductDeliveryRuntimeService` em shadow mode.
4. Adicionar testes de envelope para pedido ruim, produto complexo, API,
   ecommerce, SaaS, bug simples e fluxo com incerteza.
5. Criar `php artisan atlas:product-delivery:certify --json`.
6. Integrar APDR antes de AAEQ/Dev/Forge quando a certificacao estiver verde.
