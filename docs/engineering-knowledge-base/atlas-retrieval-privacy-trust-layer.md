---
id: atlas-retrieval-privacy-trust-layer
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Retrieval Privacy & Trust Layer
status: active
implementation_state: runtime_surface_privacy_trust_ready
blocker: Provider enforcement nos flows finais ainda depende de AKIF/ACCR/ATER; runtime ARPTL read-only ja emite gate, receipts e tests.
category: context_retrieval_intelligence
priority: 97
summary: "Camada de privacidade, trust, redacao, retencao e provider-safe policy para todo retrieval e ingestion do Atlas."
tags: [atlas-ai, aucri, arptl, privacy, trust, redaction]
capabilities: [privacy_gate, trust_receipt, redaction_plan, retention_policy, provider_safe_context]
decisions:
  - Privacy e trust ficam antes de embedding, retrieval e provider externo.
  - Embedding precisa de deletion path.
  - Fonte sem autoridade nao vira verdade operacional.
maintenance:
  - Atualizar antes de mudar privacy policy, provider policy, retention ou redaction.
product_name: Atlas Retrieval Privacy & Trust Layer
runtime_acronym: ARPTL
internal_product_name: Atlas Context Trust Gate
technical_runtime: AtlasRetrievalPrivacyTrustLayerService
macro_layer: true
graph_id: atlas-retrieval-privacy-trust-layer
graph_title: Atlas Retrieval Privacy & Trust Layer
graph_world: atlas
graph_parent: atlas-unified-context-retrieval-intelligence
graph_layer: module
graph_kind: module
graph_status: planned
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-semantic-embedding-foundation.md
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/Context/AtlasRetrievalPrivacyTrustLayerService.php
  - app/Console/Commands/AtlasRetrievalPrivacyTrustLayerCommand.php
  - tests/Feature/Ai/Context/RetrievalPrivacyTrustLayerTest.php
allowed_changes:
  - Criar gates de privacy, trust e retention.
  - Aplicar redacao antes de provider externo.
forbidden_changes:
  - Enviar segredo, PII ou fonte proibida para provider sem policy.
  - Persistir dado sensivel sem retention e deletion path.
depends_on:
  - atlas-semantic-embedding-foundation
  - atlas-hybrid-retrieval-infrastructure
flows_to:
  - atlas-knowledge-ingestion-fabric
  - atlas-retrieval-evaluation-benchmark-arena
unlocks: [provider_safe_retrieval, governed_ingestion]
governs: [privacy_gate, trust_policy, retention_policy]
evidence:
  - docs/engineering-knowledge-base/atlas-retrieval-privacy-trust-layer.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:context:privacy-trust --json"
  - "php artisan test tests/Feature/Ai/Context/RetrievalPrivacyTrustLayerTest.php"
requires_evidence: true
risk_level: critical
line_limit: 520
next_actions:
  - Definir classificacoes canonicas e trust receipt.
---

# Atlas Retrieval Privacy & Trust Layer

## Resumo

ARPTL e o gate de confianca de AUCRI. Ele decide o que pode ser indexado,
recuperado, mostrado, enviado a provider, retido, esquecido ou redigido.

## Papel no Atlas

O Atlas quer contexto maximo, mas nao pode vazar dados sensiveis. ARPTL garante
que retrieval poderoso nao vire risco operacional, juridico ou estrategico.

## Onde Se Encaixa

Fica em volta de ingestion, embedding, retrieval, reranking, graph e
observability. Nenhum bloco AUCRI deve bypassar ARPTL quando houver dado real.

## Contratos

- `atlas.retrieval.privacy_gate.v1`
- `atlas.retrieval.trust_receipt.v1`
- `atlas.retrieval.redaction_plan.v1`
- `atlas.retrieval.retention_policy.v1`

Campos minimos: `source_ref`, `classification`, `allowed_actions`,
`provider_allowed`, `redactions`, `retention_until`, `deletion_ref`,
`policy_version`, `receipt_hash`.

## Fluxo

1. Classificar fonte e conteudo.
2. Detectar PII, segredo, token, contrato, financeiro e confidencial.
3. Aplicar policy por flow, usuario e provider.
4. Redigir ou bloquear.
5. Registrar trust receipt.
6. Garantir retention/delete cascade para embeddings e graph edges.

## Regras para IA

- Nao enviar fonte sensivel para provider externo sem receipt.
- Nao criar embedding sem deletion path.
- Nao expor texto cru em observabilidade.
- Nao tratar fonte sem autoridade como verdade operacional.

## Escopo de Implementacao

Implementado em `AtlasRetrievalPrivacyTrustLayerService` como runtime read-only:
classifier de segredo/PII, policy evaluator, redaction receipt, provider-safe
gate, retention/delete cascade receipt e tests com PII/secret fixtures.

## Dependencias

Depende de ASEF para deletion/embedding policy e de AHRI para interceptar fontes
antes do retrieval.

## Evidencias

Evidencia atual: `atlas:context:privacy-trust --json`, trust receipt por fonte,
redaction receipt sem texto cru, provider-safe decision, retention policy e teste
que bloqueia segredo em provider externo.

## Riscos

- Redacao remover contexto necessario.
- False negative vazar segredo.
- Fonte de baixa autoridade contaminar graph.
- Deletion cascade incompleto deixar embedding orfao.

## Exemplos

PDF financeiro privado pode ser usado localmente, mas nao enviado para provider
externo se policy nao permitir. O context pack deve receber versao redigida.

## Proximas Acoes

1. Enforcar ARPTL nos blocos AKIF/ACCR/ATER.
2. Conectar delete refs com embeddings/graph quando houver persistencia real.
3. Promover gate para mandatory em provider/context compiler.
4. Manter fixtures de PII, secrets e delete cascade verdes.
