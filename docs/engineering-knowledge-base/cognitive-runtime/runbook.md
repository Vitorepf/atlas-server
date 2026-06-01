---
id: atlas-ai-cognitive-runtime-runbook
type: engineering_knowledge
title: Atlas AI Cognitive Runtime Runbook
status: active
category: runbook
priority: 98
summary: Runbook operacional para sessoes longas, compactacao, handoff, retrieval benchmark e auditoria cognitiva.
tags:
  - atlas-ai
  - cognitive-runtime
  - runbook
  - long-sessions
  - compaction
capabilities:
  - long_session_operations
  - compaction_review
  - retrieval_benchmark
  - cognitive_audit
decisions:
  - Operador deve conseguir auditar uma sessao longa sem ler chat bruto.
  - Qualquer sessao longa com drift, contexto contaminado ou evidence ausente entra em modo watch/blocked.
maintenance:
  - Atualizar quando comandos reais de snapshot, compactacao ou audit packet forem implementados.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
  - docs/engineering-knowledge-base/cognitive-runtime/schemas-and-packets.md
  - docs/engineering-knowledge-base/cognitive-runtime/failure-modes.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cognitive-runtime-runbook

graph_title: Atlas AI Cognitive Runtime Runbook

graph_world: atlas

graph_layer: module

graph_kind: runbook

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Cognitive Runtime Runbook
canonical_name: Atlas AI Cognitive Runtime Runbook
technical_name: atlas-ai-cognitive-runtime-runbook
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/cognitive-runtime/runbook.md

owner: cognitive-runtime

repo_paths:
  - docs/engineering-knowledge-base/cognitive-runtime/runbook.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - cognitive-runtime

evidence:
  - docs/engineering-knowledge-base/cognitive-runtime/runbook.md
evidence_refs:
  - symbol: AtlasCognitiveRuntimeRunbookService
  - command: atlas:aaeos:atlas-cognitive-runtime-runbook
  - test: AtlasCognitiveRuntimeRunbookTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - module
  - runbook
  - cognitive-runtime

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Cognitive Runtime Runbook

## When To Use

Use este runbook para:

- iniciar sessao de engenharia longa;
- revisar compactacao automatica;
- fazer handoff entre provider/surface/executor;
- investigar queda de qualidade;
- validar retrieval/context pack;
- promover evidence de 72h.

## Preflight

Antes de sessao longa:

```bash
git status --short
php artisan atlas:ai:session-bootstrap --task="<task>" --json
php artisan atlas:ai:architecture-validate --json
php artisan atlas:engineering:knowledge docs-health --json
```

Confirmar:

- objetivo curto;
- arquivos quentes conhecidos;
- doc owner conhecido;
- AP ou contrato existente;
- contexto provider-safe;
- criteria de parada.

## During Session

A cada bloco relevante, registrar:

- decisoes tomadas;
- arquivos tocados;
- comandos e validacoes;
- riscos pendentes;
- refs de evidence;
- mudanca de escopo;
- motivo de compactacao ou handoff.

## Compaction Review

Aceitar compactacao somente se ela preservar:

- objetivo;
- fase;
- hot files;
- ownership;
- decisions;
- rejected alternatives;
- evidence refs;
- validation status;
- next action;
- forbidden actions.

Bloquear quando:

- proximo executor precisaria do chat bruto;
- evidence refs sumiram;
- policy/receipt/ledger ficaram ambiguos;
- privacy nao foi redigida;
- doc canonico contradiz o resumo.

## Stop Criteria

Parar ou rebaixar para `watch` quando:

- drift de objetivo aparece duas vezes;
- mesmo trabalho e repetido sem nova evidencia;
- contexto obsoleto entra no prompt;
- hot file e editado por engano;
- provider recebe contexto inseguro;
- custo sobe sem ganho;
- operador precisa reconstruir estado manualmente.

## Post-Session Audit

Ao encerrar ou compactar:

```bash
php artisan atlas:ai:architecture-validate --json
php artisan atlas:engineering:knowledge docs-health --json
git diff --check
```

Emitir audit packet read-only com:

- net value;
- ganhos;
- danos;
- repeticao evitada;
- missed critical context;
- contamination;
- recommendations proposal-only.

## Promotion

Uma sessao longa so vira evidencia de maturidade quando:

1. snapshot esta completo;
2. compaction packet esta ready;
3. audit packet esta ready ou watch justificado;
4. validations passaram;
5. replay consegue reconstruir estado sem chat bruto.

## Resumo

Runbook operacional para sessoes longas, compactacao, handoff, retrieval benchmark e auditoria cognitiva.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
