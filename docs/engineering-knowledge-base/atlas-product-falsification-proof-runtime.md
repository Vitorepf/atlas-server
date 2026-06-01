---
id: atlas-product-falsification-proof-runtime
type: engineering_knowledge
title: Atlas Product Falsification & Proof Runtime
status: active
category: product-delivery
priority: 99
summary: Runtime canonico que tenta provar que uma entrega de produto esta errada antes de aceitar, certificando requisitos, aceite, contratos, seguranca, testes e evidencias.
human_summary: Camada que obriga o Atlas a atacar a propria entrega antes de dizer que esta pronta.
human_what: Runtime de falsificacao e prova adversarial de produto.
human_purpose: Evitar falso pronto, teste sem valor, regra inventada, contrato inconsistente e evidencia fraca.
human_input: Product Truth Contract, delivery contract, diff, testes, evidencias, riscos, aceite, contratos e outcome parcial.
human_output: Proof Challenge Report com counterexamples, blockers, falsification score, reparos exigidos e decisao ready/blocked.
human_change_when: Atualize quando AEDPDS, APTC, APDR, Dev, Forge, certification ou evidence gates mudarem.
human_block_when: Bloqueie se entrega complexa puder ser certificada sem tentativa formal de falsificacao.
human_name: Atlas Product Falsification & Proof Runtime
canonical_name: Atlas Product Falsification & Proof Runtime
technical_name: atlas-product-falsification-proof-runtime
product_name: Atlas Product Falsification & Proof Runtime
internal_product_name: Atlas Proof Challenge
technical_runtime: AtlasProductFalsificationProofRuntimeService
runtime_acronym: APFPR
cartography_type: runtime
canonical_source: docs/engineering-knowledge-base/atlas-product-falsification-proof-runtime.md
tags:
  - atlas
  - product-delivery
  - falsification
  - proof
  - certification
capabilities:
  - product_falsification
  - adversarial_acceptance_review
  - evidence_sufficiency_gate
  - false_completion_prevention
decisions:
  - APFPR roda depois de APDR/Dev/Forge e antes da certificacao final.
  - Entrega complexa ou de alto risco nao pode ser aceita sem tentativa de falsificacao.
  - O objetivo do APFPR nao e revisar estilo; e encontrar contraexemplos que derrubam a entrega.
maintenance:
  - Atualizar quando os contratos de AEDPDS, APTC, APDR ou Product Certification mudarem.
  - Rodar docs-health apos alteracoes.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md
  - docs/engineering-knowledge-base/atlas-ai-assisted-execution-quality.md
  - docs/engineering-knowledge-base/atlas-dev-runtime-intelligence.md
  - docs/engineering-knowledge-base/atlas-forge-work-packet-native-capabilities.md
  - docs/engineering-knowledge-base/atlas-ai-product-certification.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-product-falsification-proof-runtime
graph_title: Atlas Product Falsification & Proof Runtime
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-execution-doctrine-product-delivery-system
graph_status: active
graph_source: repo
owner: product-delivery
repo_paths:
  - docs/engineering-knowledge-base/atlas-product-falsification-proof-runtime.md
allowed_changes:
  - Atualizar falsifiers, blockers, schemas e comandos quando codigo e testes existirem.
forbidden_changes:
  - Declarar APFPR implementado sem service, testes, comando e certificacao.
  - Tratar revisao cosmetica como falsificacao.
  - Permitir certificacao final com blockers criticos abertos.
depends_on:
  - atlas-execution-doctrine-product-delivery-system
flows_to:
  - atlas-ai-product-certification
unlocks:
  - adversarial-product-proof
governs:
  - product-delivery
  - programming.dev
  - programming.forge
evidence:
  - docs/engineering-knowledge-base/atlas-product-falsification-proof-runtime.md
evidence_refs:
  - symbol: AtlasProductFalsificationProofRuntimeService
  - test: AtlasProductFalsificationProofRuntimeServiceTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
next_actions:
  - Evoluir de patch explicito aprovado para patch candidato gerado sem pular Proposal Gate.
  - Persistir proof challenge dedicado em receipts quando houver execucao real.
  - Expor blockers no Control Plane sem vazar prompt bruto.
failure_modes:
  - Falso pronto aprovado por falta de adversarial review.
  - Teste passa mas nao prova requisito.
  - Regra de negocio inventada pela IA.
  - Evidencia fraca tratada como certificacao.
---
# Atlas Product Falsification & Proof Runtime

## Resumo

APFPR e o runtime que tenta reprovar uma entrega antes de o Atlas aceitar que
ela esta pronta. Ele e deliberadamente adversarial: procura requisitos faltando,
aceite fraco, contrato quebrado, regra inventada, abuso de seguranca, teste sem
valor e evidencia insuficiente.

## Papel no Atlas

APTC evita comecar errado. APDR evita executar baguncado. APFPR evita terminar
falso.

Fluxo:

```text
APTC -> APDR -> Dev/Forge -> APFPR -> repair loop -> certification -> outcome
```

APFPR nao substitui testes. Ele decide se os testes, evidencias e contratos
realmente provam a entrega.

## Onde Se Encaixa

APFPR fica dentro do AEDPDS, depois da execucao e antes da certificacao final.
Em shadow/provider-free mode, ele relata e materializa blockers. Em enforcement,
blocker critico impede `ready`. O repair bridge transforma o blocker em receipt
Dev ou packet Forge. O executor mutativo controlado pode aplicar patch explicito
aprovado com allowlist, rollback e proof rerun; APFPR ainda nao gera patch
sozinho.

## Contratos

### Nomes obrigatorios

| Campo | Valor |
|---|---|
| Nome canonico / produto | Atlas Product Falsification & Proof Runtime |
| Acronimo tecnico | APFPR |
| Nome interno de experiencia / superficie | Atlas Proof Challenge |
| Runtime tecnico | `AtlasProductFalsificationProofRuntimeService` |
| Comando canonico | `php artisan atlas:product-proof:challenge --json` |
| Repair bridge | `AtlasProductDeliveryRepairBridgeService` |

### Proof Challenge Report

```json
{
  "schema_version": "atlas.product_proof_challenge.v1",
  "status": "ready|needs_repair|blocked",
  "target": {"delivery_id": null, "route": "atlas_dev|atlas_forge"},
  "falsification_score": 0.0,
  "critical_blockers": [],
  "counterexamples": [],
  "required_repairs": [],
  "proof": {
    "requirements": "passed|failed",
    "acceptance": "passed|failed",
    "contracts": "passed|failed",
    "business_rules": "passed|failed",
    "security": "passed|failed",
    "tests": "passed|failed",
    "evidence": "passed|failed"
  }
}
```

## Fluxo

1. Recebe Product Truth Contract, delivery contract, diff, testes e evidencias.
2. Roda falsifiers especializados.
3. Gera counterexamples e blockers.
4. Classifica reparo exigido ou risco residual.
5. Se houver blocker critico, retorna `blocked` ou `needs_repair`.
6. Se falhar em derrubar a entrega, libera para certificacao.

## Regras para IA

- Nao aceite entrega complexa sem APFPR quando a fase do AEDPDS exigir.
- Nao transforme APFPR em revisao textual generica.
- Nao aprove teste que nao prova criterio de aceite.
- Nao aceite contrato sem compatibilidade de payload/schema/evento.
- Nao aceite decisao humana inferida sem evidenciar consentimento ou pergunta.
- Nao esconda counterexample: ele deve virar repair ou risco residual.

## Escopo de Implementacao

| Bloco | Funcao | Estado | Output |
|---|---|---|---|
| Requirement Falsifier | Procura requisito ausente, contraditorio ou inventado. | implemented | blocker/counterexample |
| Acceptance Counterexample Generator | Cria casos em que aceite passa mas produto continua errado. | implemented | counterexample |
| Contract Breaker | Tenta quebrar API, schema, evento, webhook e compatibilidade. | implemented | blocker |
| Business Rule Contradiction Detector | Detecta regra de dominio conflitante ou sem fonte. | implemented_shadow | blocker |
| Security Abuse Case Generator | Gera bypass, escalation, replay e vazamento de dados. | implemented | blocker |
| Test Meaningfulness Auditor | Verifica se teste prova algo real. | implemented | blocker |
| Evidence Sufficiency Gate | Bloqueia evidencia fraca ou incompleta. | implemented | blocker |
| Human Assumption Detector | Detecta decisao humana inventada pela IA. | implemented_shadow | blocker |
| False Completion Gate | Bloqueia pronto sem prova. | implemented | status blocked |
| Repair Bridge | Converte blocker em Dev repair receipt ou Forge repair packet. | implemented_non_mutative | repair payload |
| Adversarial Product Review | Reprova como reviewer senior de produto/engenharia. | implemented | proof report |

## Dependencias

- AEDPDS
- APTC Product Truth Contract
- APDR delivery contract
- AAEQ
- Atlas Dev Runtime Intelligence
- Forge Work Packet Native Capabilities
- Product Certification
- Outcome Memory

## Evidencias

Estado atual: runtime provider-free implementado, comando JSON criado,
enforcement pre-provider e pos-execucao, repair bridge Dev/Forge, outcome memory
persistida, ponte AEMOR com Judgment Guard, executor mutativo controlado, testes
verdes e product certification exigindo APFPR no AEDPDS. APFPR ainda nao gera
patch sozinho; ele bloqueia, materializa blockers, valida patch explicito pelo
gate e alimenta o proximo repair loop.

Evidencia atual:

- `AtlasProductFalsificationProofRuntimeService`
- `AtlasProductDeliveryRepairBridgeService`
- `AtlasProductDeliveryMutativeRepairExecutorService`
- `AtlasExecutionDoctrineProductDeliverySystemTest`
- `php artisan atlas:product-proof:challenge --json`
- `php artisan atlas:product-delivery:certify --json --strict`
- `php artisan atlas:product-delivery:outcome --persist --json`
- `AtlasProductDeliveryOutcomeMemoryService::bridgeToAemor`
- teste cobrindo AEDPDS -> AEMOR episode/event/outcome/learning signal/memory
  candidate
- teste cobrindo APFPR bloqueado -> Dev repair receipt / Forge repair packet
- teste cobrindo patch explicito -> rollback snapshot -> proof rerun
- Product certification exigindo APFPR para escopo complexo/alto risco
- testes cobrindo falsificacao de requisitos, aceite, contratos, seguranca,
  testes e evidencias

## Riscos

- Falsificacao rasa que so reescreve checklist.
- Bloqueio excessivo em patch simples.
- Counterexample gerado sem relacao com o Product Truth Contract.
- Modelo barato tomar decisao critica sem evidence gate.
- APFPR virar burocracia sem reparar o que encontrou.
- Repair bridge parecer geracao autonoma de patch quando ainda exige manifest explicito.

## Definition of Done

APFPR esta pronto para uma fase quando:

- proof report tem schema e hash deterministico;
- pelo menos um falsifier consegue bloquear evidencia fraca;
- delivery certification exige APFPR para AEDPDS;
- repair bridge existe para Dev e Forge;
- completion enforcement bloqueia proof com critical blockers;
- outcome memory registra evidencia/reparo;
- AEMOR Judgment Guard avalia aprendizado antes de promover memoria.

APFPR possui execucao mutativa controlada quando recebe patch explicito e
permitido. Patch de provider, subagente ou workcell precisa nascer de
`AtlasProductDeliveryPatchRequestContractService`, retornar o schema exigido e
passar pelo `AtlasProductDeliveryPatchProposalGateService`; aplicacao real em
risco alto exige approval do operador, rollback, rerun de prova e receipt. Ele
so sera considerado totalmente autonomo quando tambem gerar patch com policy e
esse mesmo gate, sem pular human override.

## Exemplos

Pedido: `cria um ecommerce`.

APFPR deve tentar reprovar:

- checkout sem pagamento recusado;
- webhook sem idempotencia;
- carrinho sem estoque concorrente;
- admin sem permissao;
- teste cobrindo so render e nao fluxo de compra;
- aceite sem regra de cancelamento/reembolso.

Pedido: `corrige bug na tela de login`.

APFPR leve deve tentar reprovar:

- teste passa sem validar sessao;
- patch altera auth global fora do escopo;
- erro resolvido para senha valida mas nao para token expirado.

## Proximas Acoes

1. Evoluir de patch explicito aprovado para patch candidato gerado sem pular Proposal Gate.
2. Persistir proof challenge dedicado em receipts quando houver execucao real.
3. Expor blockers no Control Plane sem vazar prompt bruto.
4. Usar Judgment Guard para decidir quando o outcome bridged pode virar memoria
   operacional confiavel.
