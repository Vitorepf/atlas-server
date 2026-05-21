---
id: atlas-dev-efficient-programming-flow-contracts-v1
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Contracts v1
status: active
category: programming
priority: 105
summary: Schemas canonicos detalhados dos artefatos do Atlas Dev Efficient Programming Flow, agrupados em quatro camadas (Plano, Contexto, Receipt, Telemetria). Cada artefato vem com schema YAML, invariants enforced, regra de identidade/hash, exemplo valido, exemplos invalidos e signature PHP DTO. Doc filho do contrato principal; nao define passo-a-passo de implementacao.
tags:
  - atlas-dev
  - efficient-programming-flow
  - contracts
  - schemas
  - dto
  - hashing
  - versioning
capabilities:
  - atlas_dev_contracts_canonical
  - compact_sdd_schema
  - light_task_contract_schema
  - mini_programming_spec_schema
  - verification_receipt_schema
  - failure_capsule_schema
  - fast_path_telemetry_schema
  - fast_path_error_ledger_schema
decisions:
  - Schemas dos artefatos sao a fronteira contratual entre fatias do Atlas Dev. Mudanca de schema exige bump de versao e teste de compatibilidade.
  - Toda mensagem entre fatias usa hash determinstico (sha256) sobre payload canonico ordenado.
  - schema_version segue o padrao `atlas.dev.<artifact>.v<n>`; bump exige migrar leitores antes.
  - Camada Plano descreve o que vamos fazer; Camada Contexto descreve o que sabemos; Camada Receipt descreve o que aconteceu; Camada Telemetria descreve como aconteceu.
  - Provider prompt e projecao deterministica dos contratos (ProviderPromptProjection). Prompt artesanal e proibido no fast path.
maintenance:
  - Atualize este doc quando schema mudar, quando novo artefato entrar no fluxo, ou quando uma regra de invariant mudar.
  - Nao adicione passos de implementacao aqui; pertence ao runbook.
  - Nao adicione regras de medicao/benchmark; pertence a outra equipe.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-index.md
  - docs/engineering-knowledge-base/atlas-dev-glossary.md
  - docs/engineering-knowledge-base/atlas-dev-policy.md
  - docs/engineering-knowledge-base/atlas-dev-patamares.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-01.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-02.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-03.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-04.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-05.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-06.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-07.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-08.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-efficient-programming-flow-contracts-v1
graph_title: Atlas Dev Efficient Programming Flow Contracts v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-dev-efficient-programming-flow-v1
graph_status: active
graph_source: repo
human_name: Atlas Dev Efficient Programming Flow Contracts v1
canonical_name: Atlas Dev Efficient Programming Flow Contracts v1
technical_name: atlas-dev-efficient-programming-flow-contracts-v1
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
allowed_changes:
  - Adicionar artefato novo agrupado em camada existente, com schema + invariants + identidade + exemplos + DTO.
  - Bump de schema_version com regra de migracao.
forbidden_changes:
  - Remover invariant sem bump de versao + regra de migracao.
  - Mover schemas para o doc principal; pertencem aqui.
  - Inserir regras de benchmark, oraculos, scoring ou Rivals.
depends_on:
  - atlas-dev-efficient-programming-flow-v1
flows_to:
  - atlas-dev-efficient-programming-flow-runbook-v1
unlocks:
  - atlas_dev_dto_runtime
  - atlas_dev_schema_validation
governs:
  - atlas_dev.contracts.schemas
  - atlas_dev.contracts.hashing
  - atlas_dev.contracts.versioning
evidence:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
next_actions:
  - Implementar DTOs read-only PHP sob `app/Services/Ai/Programming/AtlasDev/Schemas/`.
  - Implementar serializadores deterministicos.
  - Implementar hashers canonicos (sha256 sobre JSON canonicalizado).
  - Adicionar testes de schema (round-trip, invariant enforcement, rejeicao de campos faltantes).
observability_signals:
  - schema_version
  - artifact_kind
  - artifact_hash
required_tests:
  - "php artisan test tests/Unit/Ai/Programming/AtlasDev/Schemas"
requires_evidence: true
risk_level: high
line_limit: 2080
---
# Atlas Dev Efficient Programming Flow Contracts v1

## Resumo

Este documento e o anexo de contratos do Atlas Dev Efficient Programming Flow. Ele preserva schemas, invariants e regras de hash para os artefatos do fluxo.

## Papel no Atlas

Serve como referencia contratual para DTOs, validadores, serializadores e hashers canonicos do Atlas Dev.

## Onde Se Encaixa

Fica abaixo do contrato principal `atlas-dev-efficient-programming-flow-v1.md` e ao lado do runbook de implementacao.

## Contratos

Os contratos detalhados continuam nas secoes numeradas abaixo; esta secao existe para manter cobertura canonica do modulo.

## Fluxo

Os artefatos fluem das camadas de plano e contexto para receipts e telemetria, sempre com identidade deterministica por hash.

## Regras para IA

IA deve tratar schemas como fronteira de compatibilidade, nao remover invariants sem bump e nao criar prompt provider artesanal fora da projecao contratual.

## Escopo de Implementacao

O escopo e documental/contratual: especificar schemas e exemplos, sem implementar runtime neste arquivo.

## Dependencias

Depende do contrato principal, do runbook e dos sistemas de Code Intelligence, Open Brain e governanca de programacao.

## Evidencias

Evidencia primaria: este doc, o contrato principal, o runbook e testes futuros de schema/round-trip/hash.

## Riscos

Risco principal: drift entre schema documentado e DTO/runtime. Mitigacao: hash canonico, versionamento e testes de compatibilidade.

## Exemplos

Os exemplos validos e invalidos permanecem nas secoes especificas de cada artefato abaixo.

## Proximas Acoes

Implementar DTOs read-only, serializacao deterministica e testes de rejeicao de payload invalido.

## Detalhes Extraidos

Este documento foi reduzido para funcionar como índice canônico. O conteúdo detalhado vive nos recortes abaixo, para manter a cartografia e o modal humano legíveis sem perder informação.

- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-01.md` — 1. Resumo ate 4. Camada Plano.
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-02.md` — 4.1 OperationEnvelope ate 4.2 CompactSDD.
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-03.md` — 4.3 MiniProgrammingSpec.
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-04.md` — 4.4 LightTaskContract ate 5.1 ContextRetrievalPlan.
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-05.md` — 5.2 CodeDiscoveryManifest ate 6. Camada Receipt.
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-06.md` — 6.1 ScopeGuardReceipt ate 6.2 VerificationReceipt.
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-07.md` — 6.3 FailureCapsule ate 7.1 FastPathTelemetry.
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-08.md` — 7.2 FastPathErrorLedgerEntry ate 10. Resumo Operacional.

### Regra De Manutencao

- Nao adicionar novas responsabilidades neste índice se um recorte filho for o lugar correto.
- Ao alterar um recorte, manter backlink para este índice e rodar `php artisan atlas:engineering:knowledge docs-health --json`.
- A cartografia deve tratar este índice como porta de entrada e os recortes como documentação detalhada.
