---
id: atlas-evidence-truth-layer
type: engineering_knowledge
title: Atlas Evidence Truth Layer
status: active
category: atlas-ai
priority: 100
summary: Politica universal de verdade e evidencia para o Atlas: fontes, logs, screenshots, dados, testes, receipts, hashes, confidence, freshness, claims e blockers verificaveis.
tags:
  - atlas-ai
  - evidence
  - truth
  - audit
capabilities:
  - evidence_packs
  - claim_policy
  - confidence_scoring
  - freshness_tracking
  - receipt_hashing
decisions:
  - Toda claim relevante precisa de evidencia ou deve ser marcada como inferencia.
  - Blocker real e melhor que conclusao falsa.
maintenance:
  - Atualize este doc antes de alterar politica global de evidencia.
related_paths:
  - docs/engineering-knowledge-base/atlas-mission-mode.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-evidence-truth-layer
graph_title: Atlas Evidence Truth Layer
graph_world: atlas
graph_layer: system
graph_kind: policy
graph_parent: atlas-autonomous-intelligence-operating-system
graph_status: active
graph_source: repo
human_name: Atlas Evidence Truth Layer
canonical_name: Atlas Evidence Truth Layer
technical_name: atlas-evidence-truth-layer
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/atlas-evidence-truth-layer.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-evidence-truth-layer.md
allowed_changes:
  - Adicionar tipos de evidencia e regras de claim.
forbidden_changes:
  - Aceitar claim critica sem evidencia verificavel.
depends_on:
  - atlas-mission-mode
flows_to:
  - atlas-autonomous-control-plane
  - atlas-evidence-certification-runtime
unlocks:
  - verifiable-autonomous-execution
  - atlas-evidence-certification-runtime
governs:
  - atlas_ai.claims
  - atlas_ai.evidence
evidence:
  - docs/engineering-knowledge-base/atlas-evidence-truth-layer.md
evidence_refs:
  - symbol: ClaimVerificationService
  - command: atlas:ai:evidence
  - test: EvidenceRuntimeClaimVerificationTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: critical
ai_entrypoints:
  - Leia Claim Policy antes de certificar missao.
quality_gates:
  - claim-classified
  - evidence-linked
  - confidence-set
  - freshness-set
failure_modes:
  - Fonte fraca vira verdade.
  - Evidencia indireta prova claim ampla.
  - Teste verde nao cobre requisito.
observability_signals:
  - evidence_pack_id
  - claim_count
  - unsupported_claims
  - confidence
next_actions:
  - Criar evidence pack builder global.
line_limit: 520
---
# Atlas Evidence Truth Layer

## Resumo

Evidence Truth Layer define como o Atlas sabe, prova e comunica. Ele evita
respostas convincentes sem base e exige fontes, logs, dados, testes, receipts,
hashes e blockers para claims relevantes.

## Papel no Atlas

Serve todos os runtimes: pesquisa, software, automacao, ferramentas,
experimentos e delivery.

## Onde Se Encaixa

```text
Execution -> Evidence Pack -> Claim Policy -> Certification -> Final Answer
```

## Contratos

- `atlas.ai.evidence_pack.v1`
- `atlas.ai.claim.v1`
- `atlas.ai.source_ref.v1`
- `atlas.ai.receipt.v1`
- `atlas.ai.certification.v1`

## Fluxo

1. Classificar claims.
2. Coletar evidencias.
3. Vincular claims a evidencias.
4. Definir confidence e freshness.
5. Identificar unsupported claims.
6. Bloquear ou rebaixar claims.
7. Gerar evidence pack e certification.

## Claim Policy

- `verified`: evidencia direta.
- `supported`: evidencia forte, mas nao completa.
- `inferred`: inferencia declarada.
- `uncertain`: precisa mais dados.
- `blocked`: nao pode afirmar.

## Regras para IA

- Nao usar "parece" como prova.
- Nao extrapolar fonte pequena para conclusao ampla.
- Nao citar teste que nao cobre requisito.
- Nao esconder incerteza.
- Nao declarar benchmark sem run real.

## Escopo de Implementacao

Evidence pack builder, claim classifier, source registry, hash receipts,
freshness/confidence policy e certification integration.

## Dependencias

- Mission Mode.
- World Model.
- Experimentation Engine.
- Control Plane.

## Evidencias

Links, arquivos, comandos, logs, screenshots, outputs, diffs, testes, metrics,
receipts, hashes e blockers.

## Riscos

- Burocracia de evidencia pode atrasar; para tasks triviais usar evidencia leve.
- Claims criticas exigem evidencia forte.

## Exemplos

"Atlas vendeu" exige pedido/pagamento/analytics. "Atlas criou ecommerce"
exige URL, checkout, catalogo, pagamento configurado e smoke test.

## Proximas Acoes

1. Criar evidence pack schema global.
2. Criar claim classifier.
3. Criar certification gate.
4. Operacionalizar via `atlas-evidence-certification-runtime.md` (Meta 4): EvidencePack, Receipt, Claim, Artifact, SourceRef, GateRun, TestResult, OperatorDecision, Certification, Blocker e AuditEvent como contratos universais.

## Definition of Done

Esta pronto quando toda resposta final relevante separa verificado, suportado,
inferido, incerto e bloqueado com evidencia rastreavel.

