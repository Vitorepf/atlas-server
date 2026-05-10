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
