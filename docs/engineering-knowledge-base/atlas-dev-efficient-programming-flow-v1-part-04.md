---
id: atlas-dev-efficient-programming-flow-v1-part-04
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow v1 · Parte 4
status: active
category: programming
priority: 105
summary: Recorte focado de Atlas Dev Efficient Programming Flow v1: 26.4 Sumario Operacional (Estado Real Implementado) ate 27. Regra Final.
tags:
  - atlas-dev
  - efficient-programming-flow
  - split-doc
  - cartography-readable
capabilities:
  - atlas_dev_efficient_programming_flow
  - atlas_documentation_split
decisions:
  - Este recorte preserva uma parte normativa do Atlas Dev Efficient Programming Flow sem ampliar responsabilidade do índice canônico.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar junto com docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md quando o contrato alto-nivel mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-contracts-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-efficient-programming-flow-v1-part-04
graph_title: Atlas Dev Efficient Programming Flow v1 Parte 4
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-dev-efficient-programming-flow-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-04.md
allowed_changes:
  - Atualizar somente a parte descrita neste recorte.
forbidden_changes:
  - Misturar este recorte com contracts detalhados, runbook executável, benchmark, Rivals ou Forge production.
depends_on:
  - atlas-dev-efficient-programming-flow-v1
flows_to:
  - atlas-dev-efficient-programming-flow-v1
unlocks:
  - atlas_cartography_readable_documentation
governs:
  - atlas_dev.efficient_programming_flow
evidence:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Manter este recorte alinhado ao índice canônico e ao contrato de documentação.
---
# Atlas Dev Efficient Programming Flow v1 · Parte 4

## Resumo

Este recorte preserva uma parte focada de Atlas Dev Efficient Programming Flow v1: 26.4 Sumario Operacional (Estado Real Implementado) ate 27. Regra Final.

## Papel no Atlas

Mantém uma fatia normativa do fluxo de programação diária do Atlas Dev fora do índice principal para que a cartografia continue legível.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md` e deve ser lido quando a pessoa precisar do detalhe desta parte do fluxo.

## Contratos

Segue o documento dono, o glossário canônico, o contracts doc e o modelo obrigatório de documentação do Atlas.

## Fluxo

Índice canônico → recorte focado → contrato, decisão ou regra operacional correspondente.

## Regras para IA

Não transformar este recorte em benchmark, Rivals, Forge production ou implementação sem evidence. Não misturar patamar, versão, fonte, risco, regra ou prova.

## Escopo de Implementacao

Este arquivo só guarda o detalhe extraído do documento maior.

## Dependencias

Depende do índice `atlas-dev-efficient-programming-flow-v1`, do contracts doc e do runbook.

## Evidencias

A evidência de origem é o documento principal e o `docs-health` verde depois da divisão.

## Riscos

Risco principal: alguém confundir contrato alto-nível com schema detalhado, runbook executável ou benchmark competitivo.

## Exemplos

Os exemplos abaixo são o conteúdo extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar este recorte quando a parte correspondente mudar e rodar docs-health.

## Conteudo Extraido
## 26.4 Sumario Operacional (Estado Real Implementado)

Resumo curto do que **ja foi entregue** e do que ainda e follow-up. Detalhe operacional vive em `atlas-dev-efficient-programming-flow-runbook-v1.md` §15.1.

### Flags Atlas Dev Efficient

Tres niveis (todos `false` por padrao em production):

- `ATLAS_DEV_EFFICIENT_PLAN_ENABLED` → libera `POST /ai/interactions/atlas-dev/plan` (zero-provider, seguro ligar primeiro).
- `ATLAS_DEV_EFFICIENT_RUN_ENABLED` → libera `POST /ai/interactions/atlas-dev/run` (so apos Plan verde em prod).
- `ATLAS_DEV_EFFICIENT_DESKTOP_ENABLED` → libera consumo via surface Desktop.

Desligar = flag `false` → controller responde 503 `ATLAS_DEV_{PLAN,RUN}_DISABLED`, sem efeito colateral.

### APP_KEY obrigatorio (fail-closed)

- `APP_KEY` precisa ser `base64:...` com ao menos 32 bytes decodificados (saida canonica de `php artisan key:generate`).
- Plan e Run **falham fechado** com `500 ATLAS_DEV_KEY_MISSING` quando a chave esta ausente ou curta. Nao ha fallback publico, nao ha "default key", e o operador sempre precisa rotacionar antes de habilitar runs.
- Detalhe canonico em `atlas-dev-efficient-programming-flow-runbook-v1.md` §15.1.2 e §15.1.4.

### Smoke CLI canonico

- Publico (igual ao que o Desktop emite): `php artisan atlas:cli:dev "..." --efficient --json`.
- Diagnostico interno (zero-provider, sem token): `php artisan atlas:dev:debug:smoke --intent="..." --workspace=/abs/path --json`. **Nao** e contrato publico de CLI; serve so para isolar regressao no pipeline plan-only.

### Stream snapshot-replay-then-close

- `GET /ai/interactions/atlas-dev/runs/{run_id}/stream` faz **snapshot-replay-then-close**: replay deterministico de eventos `phase:`, `receipt:` (se houver) e `stream_closed:` final, depois fecha a conexao.
- Nao ha long-lived keepalive nesta fase. Cliente trata `stream_closed` como fim canonico; qualquer estado intermediario ou retomada vem de `GET /runs/{run_id}` (REST, fonte de verdade).
- Live async (tail de log incremental) e evolucao futura — clientes ja devem ler o REST como source-of-truth.

### Path redaction nas respostas HTTP

- `HttpResponseRedactor` (camada surface) reescreve toda string que comece com o workspace absoluto:
  - `workspace_label = basename($workspace)` (sem `/Users/...`);
  - `workspace_hash` continua sendo o identifier provider-safe;
  - artefatos persistidos surgem como `persisted_artifact_refs` / `persisted_receipt_refs` no formato `receipts/<run_id>/<file>`, nao como path absoluto.
- Paths absolutos permanecem **so** internamente (storage, discovery, telemetria local).
- Smoke de verificacao: o body de `POST /plan` ou `POST /run` nunca pode conter `/Users/` nem o caminho de `storage/atlas-dev/receipts/`.

### Validacao operacional

```bash
# Suite canonica (core + endpoints + adapters)
/opt/homebrew/bin/php artisan test tests/Unit/Ai/Programming/AtlasDev tests/Feature/Ai/Programming/AtlasDev

# Smoke HTTP end-to-end com PipelineRunExecutor real + fakes deterministicos
/opt/homebrew/bin/php artisan test tests/Feature/Ai/Programming/AtlasDev/Http/PipelineRunExecutorHttpSmokeTest.php

# Health dos docs canonicos Atlas Dev
/opt/homebrew/bin/php artisan atlas:engineering:knowledge docs-health --json
```

`atlas:engineering:knowledge docs-health --json` deve devolver `status: ok` com zero `violations`. Qualquer claim alterado neste doc passa por esse gate antes de virar canon.

### Follow-ups explicitos (nao entregues)

- **Bundle hash publico para o operador** — o Plan ja pina server-side dois hashes na linha HMAC-keyed do confirmation_token (`task_contract_hash` e `compact_sdd_hash`), e o `PipelineRunExecutor` valida ambos antes de chamar o provider (mismatch = 422 `TASK_CONTRACT_HASH_MISMATCH` / `COMPACT_SDD_TAMPERED`). MiniSpec ainda carrega `compact_sdd_hash` no disco como terceira camada. O que **ainda** nao existe e um hash unico do bundle completo `(envelope, compact_sdd, mini_spec, task_contract, prompt_projection)` publicado pelo Plan para o operador validar lado a lado, e o pin HMAC ainda nao cobre `envelope_hash` ou `prompt_projection_hash` (so `task_contract_hash` + `compact_sdd_hash`). Esperado em fatia futura.
- **Live async stream** (`phase:` em tempo real durante a execucao real) — ver §26.1 / `15.1.6`. Hoje so existe snapshot-replay-then-close + REST poll.
- **Outras surfaces** (App, API publica) — Desktop e canonico hoje; CLI tem paridade limitada via `atlas:cli:dev`. App/API completos sao trabalho futuro.

## 27. Regra Final

Atlas Dev + Sonnet ganha por **sistema**, nao por modelo:

```text
menos contexto inutil,
mais arquivo certo,
menos escopo errado,
mais teste focado,
mais evidence,
repair pequeno,
falha honesta,
Forge quando precisa.
```

Sem isso, e so provider com branding. Com isso, Atlas tem uma chance real de virar uma maquina de programacao diaria com performance e qualidade extrema.

Este doc e contrato canonico. Mudanca relevante neste fluxo passa por aqui antes de virar codigo, schema ou implementacao.
